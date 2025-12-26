<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\Event;
use Lettermint\RabbitMQ\Consumers\Consumer;

/**
 * Artisan command to consume messages from a RabbitMQ queue.
 *
 * This command replaces Laravel Horizon's worker and provides direct
 * consumption from RabbitMQ queues using php-amqplib.
 */
class ConsumeCommand extends Command
{
    protected $signature = 'rabbitmq:consume
        {queue : The queue to consume from}
        {--connection=rabbitmq : The queue connection to use}
        {--prefetch=10 : Number of messages to prefetch}
        {--timeout=60 : Seconds to wait for a message}
        {--max-jobs=0 : Maximum jobs to process before stopping (0 = unlimited)}
        {--max-time=0 : Maximum seconds to run before stopping (0 = unlimited)}
        {--max-memory=128 : Maximum memory in MB before stopping}
        {--sleep=3 : Seconds to sleep when no jobs available}
        {--tries=3 : Number of times to attempt a job before failing}
        {--rest=0 : Seconds to rest between jobs}
        {--force : Force the worker to run even in maintenance mode}
        {--stop-when-empty : Stop when the queue is empty}
        {--quiet-exit : Exit quietly without error when stopped}';

    protected $description = 'Consume messages from a RabbitMQ queue';

    public function handle(Consumer $consumer, ExceptionHandler $handler): int
    {
        $queue = $this->argument('queue');

        $this->components->info("Starting consumer for queue: {$queue}");

        // Register event listeners for output
        $this->listenForEvents();

        try {
            $consumer
                ->setQueue($queue)
                ->setConnection($this->option('connection'))
                ->setPrefetch((int) $this->option('prefetch'))
                ->setTimeout((int) $this->option('timeout'))
                ->setMaxJobs((int) $this->option('max-jobs'))
                ->setMaxTime((int) $this->option('max-time'))
                ->setMaxMemory((int) $this->option('max-memory'))
                ->setSleep((int) $this->option('sleep'))
                ->setTries((int) $this->option('tries'))
                ->setRest((int) $this->option('rest'))
                ->setStopWhenEmpty((bool) $this->option('stop-when-empty'))
                ->consume();

            return self::SUCCESS;
        } catch (\Exception $e) {
            $handler->report($e);

            $this->components->error("Consumer error: {$e->getMessage()}");

            if ($this->option('quiet-exit')) {
                return self::SUCCESS;
            }

            return self::FAILURE;
        }
    }

    /**
     * Listen for queue worker events.
     */
    protected function listenForEvents(): void
    {
        Event::listen(JobProcessing::class, function (JobProcessing $event) {
            $this->components->task(
                "Processing: {$event->job->resolveName()}",
                fn () => true
            );
        });

        Event::listen(JobProcessed::class, function (JobProcessed $event) {
            if ($this->getOutput()->isVerbose()) {
                $this->line("  <fg=green>✓</> Processed: {$event->job->resolveName()}");
            }
        });

        Event::listen(JobFailed::class, function (JobFailed $event) {
            $this->components->error("Failed: {$event->job->resolveName()}");

            if ($this->getOutput()->isVerbose()) {
                $this->line("  <fg=red>Error:</> {$event->exception->getMessage()}");
            }
        });
    }
}
