<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Consumers;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Lettermint\RabbitMQ\Exceptions\ConnectionException;
use Lettermint\RabbitMQ\Exceptions\PublishException;
use Throwable;

final class RabbitMQWorker extends Worker
{
    /**
     * Process one RabbitMQ delivery with Laravel worker behavior.
     */
    public function processMessage(Job $job, string $connectionName, WorkerOptions $options): void
    {
        ($this->resetScope)();

        $supportsAsyncSignals = $this->supportsAsyncSignals();

        if ($supportsAsyncSignals) {
            $this->registerTimeoutHandler($job, $options);
        }

        try {
            $this->process($connectionName, $job, $options);
        } catch (ConnectionException|PublishException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);
        } finally {
            if ($supportsAsyncSignals) {
                $this->resetTimeoutHandler();
            }
        }
    }
}
