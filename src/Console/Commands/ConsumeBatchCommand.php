<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Lettermint\RabbitMQ\Batch\BatchOptions;
use Lettermint\RabbitMQ\Consumers\Consumer;
use Lettermint\RabbitMQ\Contracts\BatchHandler;
use Lettermint\RabbitMQ\Events\BatchInterrupted;
use Lettermint\RabbitMQ\Events\BatchProcessed;
use Lettermint\RabbitMQ\Events\BatchProcessing;
use Lettermint\RabbitMQ\Support\ExceptionReporter;
use Throwable;

final class ConsumeBatchCommand extends Command
{
    protected $signature = 'rabbitmq:consume-batch
        {queue : The queue to consume from}
        {--handler= : Batch handler class}
        {--max-count= : Maximum messages in one batch}
        {--max-bytes= : Maximum raw payload bytes in one batch}
        {--max-wait= : Maximum seconds from the first delivery to a flush}
        {--connection=rabbitmq : The queue connection to use}
        {--timeout=60 : Maximum seconds for one batch handler call}
        {--wait=1 : Maximum seconds to wait for broker activity}
        {--max-jobs=0 : Maximum messages to settle before stopping (0 = unlimited)}
        {--max-time=0 : Maximum seconds to run before stopping (0 = unlimited)}
        {--max-memory=128 : Maximum memory in MB before stopping}
        {--sleep=3 : Seconds to sleep while the worker is paused}
        {--tries=3 : Number of application attempts before terminal failure}
        {--backoff=0 : Retry delay in seconds, or a comma-separated list}
        {--min-retry-delay=1 : Minimum delayed retry in seconds}
        {--rest=0 : Seconds to rest after each batch}
        {--force : Run during maintenance mode}
        {--stop-when-empty : Flush a partial batch, then stop when the queue is empty}
        {--quiet-exit : Exit without an error message when stopped}';

    protected $description = 'Consume RabbitMQ messages with an explicit application batch handler';

    public function handle(Consumer $consumer): int
    {
        $handler = $this->option('handler');

        if (! is_string($handler) || trim($handler) === '') {
            $this->components->error('The --handler option is required.');

            return self::INVALID;
        }

        try {
            $options = new BatchOptions(
                maxCount: (int) $this->option('max-count'),
                maxBytes: (int) $this->option('max-bytes'),
                maxWaitSeconds: (float) $this->option('max-wait'),
                minimumRetryDelaySeconds: (int) $this->option('min-retry-delay'),
            );

            /** @var class-string<BatchHandler> $handler */
            $handler = trim($handler);
            $queue = (string) $this->argument('queue');
            $this->components->info("Starting batch consumer for queue: {$queue}");
            $this->listenForEvents();

            $consumer
                ->setQueue($queue)
                ->setConnection((string) $this->option('connection'))
                ->setTimeout((int) $this->option('timeout'))
                ->setWaitTimeout((float) $this->option('wait'))
                ->setMaxJobs((int) $this->option('max-jobs'))
                ->setMaxTime((int) $this->option('max-time'))
                ->setMaxMemory((int) $this->option('max-memory'))
                ->setSleep((int) $this->option('sleep'))
                ->setTries((int) $this->option('tries'))
                ->setBackoff($this->parseBackoff((string) $this->option('backoff')))
                ->setRest((int) $this->option('rest'))
                ->setForce((bool) $this->option('force'))
                ->setStopWhenEmpty((bool) $this->option('stop-when-empty'))
                ->consumeBatch($handler, $options);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            ExceptionReporter::report($exception);

            if (! $this->option('quiet-exit')) {
                $this->components->error("Batch consumer error: {$exception->getMessage()}");
            }

            return self::FAILURE;
        }
    }

    private function listenForEvents(): void
    {
        Event::listen(BatchProcessing::class, function (BatchProcessing $event): void {
            $this->line("Processing batch: {$event->size} messages, {$event->payloadBytes} bytes");
        });

        Event::listen(BatchProcessed::class, function (BatchProcessed $event): void {
            $duration = $event->processingMilliseconds + $event->settlementMilliseconds;
            $this->line(sprintf(
                '  <fg=green>DONE</> %.2fms; %d successful, %d retried, %d failed',
                $duration,
                $event->successes,
                $event->retries,
                $event->failures,
            ));
        });

        Event::listen(BatchInterrupted::class, function (BatchInterrupted $event): void {
            $this->line("  <fg=yellow>INTERRUPTED</>; {$event->unacknowledged} messages remain unacknowledged");
        });
    }

    private function parseBackoff(string $backoff): int|array
    {
        $values = array_map('trim', explode(',', $backoff));
        $values = array_map(static fn (string $value): int => max(0, (int) $value), $values);

        return count($values) === 1 ? $values[0] : $values;
    }
}
