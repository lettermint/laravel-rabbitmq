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
use Lettermint\RabbitMQ\Events\JobReleased;
use Lettermint\RabbitMQ\Queue\RabbitMQJob;
use Lettermint\RabbitMQ\Support\ExceptionReporter;
use Lettermint\RabbitMQ\Support\FailedJobDetails;
use Throwable;

/**
 * Artisan command to consume messages from a RabbitMQ queue.
 *
 * This command replaces Laravel Horizon's worker and provides direct
 * consumption from RabbitMQ queues using php-amqplib.
 */
class ConsumeCommand extends Command
{
    protected $signature = 'rabbitmq:consume
        {queue* : The queue(s) to consume from}
        {--connection=rabbitmq : The queue connection to use}
        {--prefetch=1 : Number of messages to prefetch}
        {--timeout=60 : Maximum seconds for one job}
        {--wait=1 : Seconds to wait for a broker delivery}
        {--max-jobs=0 : Maximum jobs to process before stopping (0 = unlimited)}
        {--max-time=0 : Maximum seconds to run before stopping (0 = unlimited)}
        {--max-memory=128 : Maximum memory in MB before stopping}
        {--sleep=3 : Seconds to sleep when no jobs available}
        {--tries=3 : Number of times to attempt a job before failing}
        {--backoff=0 : Retry delay in seconds, or a comma-separated list}
        {--rest=0 : Seconds to rest between jobs}
        {--force : Force the worker to run even in maintenance mode}
        {--stop-when-empty : Stop when the queue is empty}
        {--quiet-exit : Exit quietly without error when stopped}';

    protected $description = 'Consume messages from one or more RabbitMQ queues';

    /**
     * Job start times keyed by job ID for accurate timing.
     *
     * @var array<string, float>
     */
    protected array $jobStartTimes = [];

    public function handle(Consumer $consumer, ExceptionHandler $handler): int
    {
        /** @var list<string> $queues */
        $queues = $this->argument('queue');

        $this->components->info('Starting consumer for queue(s): '.implode(', ', $queues));

        // Register event listeners for output
        $this->listenForEvents();

        try {
            $consumer
                ->setQueues($queues)
                ->setConnection($this->option('connection'))
                ->setPrefetch((int) $this->option('prefetch'))
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
                ->consume();

            return self::SUCCESS;
        } catch (Throwable $e) {
            ExceptionReporter::report($e);

            if (! $this->option('quiet-exit')) {
                $this->components->error("Consumer error: {$e->getMessage()}");
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
            $jobId = $event->job->getJobId();
            $this->jobStartTimes[$jobId] = microtime(true);
            $this->line("Processing: {$event->job->resolveName()}");
        });

        Event::listen(JobProcessed::class, function (JobProcessed $event) {
            $jobId = $event->job->getJobId();
            $duration = $this->formatDuration($jobId);
            unset($this->jobStartTimes[$jobId]);

            if ($event->job->isReleased() || $event->job->hasFailed()) {
                return;
            }

            $this->line("  <fg=green>DONE</> in {$duration}");
        });

        Event::listen(JobFailed::class, function (JobFailed $event) {
            $jobId = $event->job->getJobId();
            $duration = $this->formatDuration($jobId);
            unset($this->jobStartTimes[$jobId]);

            $this->line("  <fg=red>FAILED</> in {$duration}");

            if ($this->getOutput()->isVerbose()) {
                $this->line("    <fg=red>Error:</> {$event->exception->getMessage()}");
            }

            if ($event->job instanceof RabbitMQJob) {
                app(FailedJobDetails::class)->record($event);
            }
        });

        Event::listen(JobReleased::class, function (JobReleased $event): void {
            unset($this->jobStartTimes[$event->jobId]);
            $this->line('  RELEASED');
        });
    }

    /**
     * Format the duration for a job based on its start time.
     */
    protected function formatDuration(?string $jobId): string
    {
        $startTime = $this->jobStartTimes[$jobId] ?? null;

        if ($startTime === null) {
            return '?ms';
        }

        $seconds = microtime(true) - $startTime;
        $ms = $seconds * 1000;

        return $ms >= 1000
            ? sprintf('%.2fs', $seconds)
            : sprintf('%.2fms', $ms);
    }

    protected function parseBackoff(string $backoff): int|array
    {
        $values = array_map('trim', explode(',', $backoff));
        $values = array_map(fn (string $value): int => max(0, (int) $value), $values);

        return count($values) === 1 ? $values[0] : $values;
    }
}
