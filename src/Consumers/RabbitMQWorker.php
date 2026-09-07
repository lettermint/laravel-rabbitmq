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
use Throwable;

final class RabbitMQWorker extends Worker
{
    private ?int $restartTimestamp = null;

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

    protected function registerTimeoutHandler($job, WorkerOptions $options)
    {
        parent::registerTimeoutHandler($job, $options);
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
                $this->registerTimeoutHandler($job, $options);
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
