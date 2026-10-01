<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Consumers;

use Closure;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Lettermint\RabbitMQ\Enums\BatchItemOutcome;
use Lettermint\RabbitMQ\Exceptions\ConnectionException;
use Lettermint\RabbitMQ\Exceptions\PublishException;
use Lettermint\RabbitMQ\Exceptions\SettlementException;
use Lettermint\RabbitMQ\Queue\RabbitMQJob;
use Lettermint\RabbitMQ\Support\ExceptionReporter;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

final class RabbitMQWorker extends Worker
{
    // Keep the raw cache value for Laravel's restart comparison; stores can return strings.
    private mixed $restartTimestamp = null;

    public function startSession(): void
    {
        $this->restartTimestamp = $this->getTimestampOfLastQueueRestart();
        $this->shouldQuit = false;
    }

    public function restartRequested(): bool
    {
        return $this->shouldQuit || $this->queueShouldRestart($this->restartTimestamp);
    }

    public function mayRun(WorkerOptions $options, string $connection, string $queue): bool
    {
        return $this->daemonShouldRun($options, $connection, $queue);
    }

    private function registerMessageTimeoutHandler(
        Job $job,
        string $connectionName,
        WorkerOptions $options,
    ): void {
        // Laravel 13.31 adds connection and queue arguments to the timeout handler.
        $timeoutHandler = new ReflectionMethod(Worker::class, 'registerTimeoutHandler');
        $arguments = $timeoutHandler->getNumberOfParameters() === 4
            ? [$connectionName, $job->getQueue(), $job, $options]
            : [$job, $options];

        $timeoutHandler->invokeArgs($this, $arguments);
        $handler = pcntl_signal_get_handler(SIGALRM);

        pcntl_signal(SIGALRM, function () use ($handler, $options): void {
            try {
                $handler();
            } catch (Throwable $exception) {
                ExceptionReporter::report($exception);
            }

            try {
                $this->kill(self::EXIT_ERROR, $options);
            } catch (Throwable $exception) {
                ExceptionReporter::report($exception);
                exit(self::EXIT_ERROR);
            }
        });
    }

    private function registerBatchTimeoutHandler(
        string $connectionName,
        string $queue,
        WorkerOptions $options,
        Closure $beforeKill,
    ): void {
        $timeoutHandler = new ReflectionMethod(Worker::class, 'registerTimeoutHandler');
        $arguments = $timeoutHandler->getNumberOfParameters() === 4
            ? [$connectionName, $queue, null, $options]
            : [null, $options];

        $timeoutHandler->invokeArgs($this, $arguments);
        $handler = pcntl_signal_get_handler(SIGALRM);

        pcntl_signal(SIGALRM, function () use ($handler, $options, $beforeKill): void {
            try {
                $beforeKill();
            } catch (Throwable $exception) {
                ExceptionReporter::report($exception);
            }

            try {
                $handler();
            } catch (Throwable $exception) {
                ExceptionReporter::report($exception);
            }

            try {
                $this->kill(self::EXIT_ERROR, $options);
            } catch (Throwable $exception) {
                ExceptionReporter::report($exception);
                exit(self::EXIT_ERROR);
            }
        });
    }

    public function resetApplicationScope(): void
    {
        ($this->resetScope)();
    }

    /**
     * Run one application batch call with one timeout for the full call.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function processBatchCall(
        Closure $callback,
        string $connectionName,
        string $queue,
        WorkerOptions $options,
        Closure $beforeTimeout,
    ): mixed {
        $supportsAsyncSignals = $this->supportsAsyncSignals();

        try {
            if ($supportsAsyncSignals) {
                $this->registerBatchTimeoutHandler($connectionName, $queue, $options, $beforeTimeout);
            }

            return $callback();
        } finally {
            if ($supportsAsyncSignals) {
                $this->resetTimeoutHandler();
            }
        }
    }

    /**
     * Apply Laravel's attempt limit before an item enters a batch handler.
     */
    public function batchItemMayRun(RabbitMQJob $job, string $connectionName, WorkerOptions $options): bool
    {
        try {
            $this->markJobAsFailedIfAlreadyExceedsMaxAttempts(
                $connectionName,
                $job,
                (int) $options->maxTries,
            );

            return ! $job->isSettled();
        } catch (Throwable $exception) {
            if ($job->isSettled()) {
                ExceptionReporter::report($exception);

                return false;
            }

            throw $exception;
        }
    }

    /**
     * Settle one batch result. The returned value is the actual final outcome.
     */
    public function settleBatchItem(
        RabbitMQJob $job,
        BatchItemOutcome $outcome,
        ?Throwable $exception,
        string $connectionName,
        WorkerOptions $options,
        int $minimumRetryDelaySeconds,
    ): BatchItemOutcome {
        if ($outcome === BatchItemOutcome::Success) {
            $job->delete();

            return BatchItemOutcome::Success;
        }

        $exception ??= new \RuntimeException('The batch handler did not return a valid item result.');

        if ($outcome === BatchItemOutcome::Failure) {
            try {
                $job->fail($exception);
            } catch (Throwable $failureCallbackException) {
                if (! $job->isSettled()) {
                    throw $failureCallbackException;
                }

                ExceptionReporter::report($failureCallbackException);
            }

            return BatchItemOutcome::Failure;
        }

        try {
            $this->markJobAsFailedIfWillExceedMaxAttempts(
                $connectionName,
                $job,
                (int) $options->maxTries,
                $exception,
            );
            $this->markJobAsFailedIfWillExceedMaxExceptions($connectionName, $job, $exception);
            $this->markBatchJobAsFailedWhenRetryPolicyStops($connectionName, $job, $exception);
        } catch (Throwable $failureCallbackException) {
            if (! $job->isSettled()) {
                throw $failureCallbackException;
            }

            ExceptionReporter::report($failureCallbackException);
        }

        if ($job->isSettled() || $job->hasFailed()) {
            return BatchItemOutcome::Failure;
        }

        try {
            $this->raiseExceptionOccurredJobEvent($connectionName, $job, $exception);
        } catch (Throwable $lifecycleException) {
            ExceptionReporter::report($lifecycleException);
        }

        $delay = max($minimumRetryDelaySeconds, $this->calculateBackoff($job, $options));
        $job->release($delay);

        try {
            $this->events->dispatch(new JobReleasedAfterException(
                $connectionName,
                $job,
                $delay,
                $exception,
            ));
        } catch (Throwable $lifecycleException) {
            ExceptionReporter::report($lifecycleException);
        }

        return BatchItemOutcome::Retry;
    }

    private function markBatchJobAsFailedWhenRetryPolicyStops(
        string $connectionName,
        RabbitMQJob $job,
        Throwable $exception,
    ): void {
        $method = 'markJobAsFailedIfItShouldntBeRetried';

        if (! (new ReflectionClass(Worker::class))->hasMethod($method)) {
            return;
        }

        (new ReflectionMethod(Worker::class, $method))->invoke($this, $connectionName, $job, $exception);
    }

    protected function handleJobException($connectionName, $job, WorkerOptions $options, Throwable $e)
    {
        if ($job instanceof RabbitMQJob && $job->settlementError() !== null) {
            throw $job->settlementError();
        }

        parent::handleJobException($connectionName, $job, $options, $e);
    }

    /**
     * Preserve the exception before Laravel releases the job.
     */
    protected function raiseExceptionOccurredJobEvent($connectionName, $job, Throwable $e)
    {
        if ($job instanceof RabbitMQJob) {
            $job->recordReleaseException($e);
        }

        parent::raiseExceptionOccurredJobEvent($connectionName, $job, $e);
    }

    /**
     * Process one RabbitMQ delivery with Laravel worker behavior.
     */
    public function processMessage(Job $job, string $connectionName, WorkerOptions $options): void
    {
        $supportsAsyncSignals = $this->supportsAsyncSignals();
        $payloadValid = ! $job instanceof RabbitMQJob;

        try {
            if ($job instanceof RabbitMQJob) {
                $payload = $job->payload();

                if (! is_string($payload['job'] ?? null) || $payload['job'] === '') {
                    throw new \RuntimeException('Cannot process job: the payload has no job handler.');
                }

                $payloadValid = true;
            }

            ($this->resetScope)();

            if ($supportsAsyncSignals) {
                $this->registerMessageTimeoutHandler($job, $connectionName, $options);
            }

            $this->process($connectionName, $job, $options);

            if ($job instanceof RabbitMQJob && ! $job->isSettled()) {
                throw new SettlementException('The job returned without settling its RabbitMQ delivery.');
            }
        } catch (ConnectionException|PublishException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($job instanceof RabbitMQJob) {
                if (! $payloadValid) {
                    $job->rejectMalformed($exception);
                }

                if (! $job->isSettled()) {
                    throw new SettlementException('RabbitMQ delivery processing failed before settlement.', previous: $exception);
                }
            }

            ExceptionReporter::report($exception);
        } finally {
            if ($supportsAsyncSignals) {
                $this->resetTimeoutHandler();
            }
        }
    }
}
