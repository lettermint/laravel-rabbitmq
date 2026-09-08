<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Consumers;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Lettermint\RabbitMQ\Exceptions\ConnectionException;
use Lettermint\RabbitMQ\Exceptions\PublishException;
use Lettermint\RabbitMQ\Exceptions\SettlementException;
use Lettermint\RabbitMQ\Queue\RabbitMQJob;
use Lettermint\RabbitMQ\Support\ExceptionReporter;
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

    private function registerMessageTimeoutHandler(Job $job, string $connectionName, WorkerOptions $options): void
    {
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
