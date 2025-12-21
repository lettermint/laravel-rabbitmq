<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Consumers;

use AMQPQueue;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Carbon;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Queue\RabbitMQJob;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;
use Throwable;

/**
 * RabbitMQ message consumer.
 *
 * This class handles the consumption of messages from RabbitMQ queues,
 * processing them through Laravel's job system.
 */
class Consumer
{
    protected string $queue = 'default';

    protected string $connection = 'rabbitmq';

    protected int $prefetch = 10;

    protected int $timeout = 60;

    protected int $maxJobs = 0;

    protected int $maxTime = 0;

    protected int $maxMemory = 128;

    protected int $sleep = 3;

    protected int $tries = 3;

    protected int $rest = 0;

    protected bool $stopWhenEmpty = false;

    protected bool $shouldQuit = false;

    public function __construct(
        protected ChannelManager $channelManager,
        protected AttributeScanner $scanner,
        protected RabbitMQQueue $rabbitmq,
        protected ExceptionHandler $exceptions,
        protected Dispatcher $events,
    ) {}

    /**
     * Start consuming messages from the queue.
     */
    public function consume(): void
    {
        $startTime = Carbon::now();
        $jobsProcessed = 0;

        $channel = $this->channelManager->consumeChannel();
        $channel->setPrefetchCount($this->prefetch);

        $amqpQueue = new AMQPQueue($channel);
        $amqpQueue->setName($this->queue);

        // Register signal handlers
        $this->registerSignalHandlers();

        while (! $this->shouldQuit) {
            // Check if we should stop
            if ($this->shouldStop($jobsProcessed, $startTime)) {
                break;
            }

            // Fire the looping event
            $this->events->dispatch(new Looping($this->connection, $this->queue));

            // Get a message from the queue
            $envelope = $amqpQueue->get();

            if ($envelope === null) {
                if ($this->stopWhenEmpty) {
                    break;
                }

                $this->sleep($this->sleep);

                continue;
            }

            // Create a job instance
            $job = new RabbitMQJob(
                container: app(),
                rabbitmq: $this->rabbitmq,
                queue: $amqpQueue,
                envelope: $envelope,
                connectionName: $this->connection,
                queueName: $this->queue
            );

            // Process the job
            $this->processJob($job);

            $jobsProcessed++;

            // Rest between jobs if configured
            if ($this->rest > 0) {
                $this->sleep($this->rest);
            }
        }
    }

    /**
     * Process a single job.
     */
    protected function processJob(RabbitMQJob $job): void
    {
        try {
            // Fire the processing event
            $this->events->dispatch(new JobProcessing($this->connection, $job));

            // Check if we've exceeded max tries
            if ($this->tries > 0 && $job->attempts() > $this->tries) {
                $this->failJob($job, new \Exception("Job exceeded maximum attempts ({$this->tries})"));

                return;
            }

            // Process the job
            $job->fire();

            // Fire the processed event
            $this->events->dispatch(new JobProcessed($this->connection, $job));

            // Delete the job (acknowledge)
            if (! $job->isDeleted() && ! $job->isReleased()) {
                $job->delete();
            }
        } catch (Throwable $e) {
            $this->handleJobException($job, $e);
        }
    }

    /**
     * Handle an exception that occurred during job processing.
     */
    protected function handleJobException(RabbitMQJob $job, Throwable $e): void
    {
        // Fire the exception event
        $this->events->dispatch(new JobExceptionOccurred($this->connection, $job, $e));

        // Report the exception
        $this->exceptions->report($e);

        // Check if we should fail the job or retry
        if ($this->tries > 0 && $job->attempts() >= $this->tries) {
            $this->failJob($job, $e);
        } else {
            // Release the job back (will go to DLQ due to rejection)
            $job->release();
        }
    }

    /**
     * Mark the job as failed.
     */
    protected function failJob(RabbitMQJob $job, Throwable $e): void
    {
        $this->events->dispatch(new JobFailed($this->connection, $job, $e));

        // Reject without requeue - will go to DLQ
        $this->rabbitmq->reject($job->getEnvelope(), $job->getAMQPQueue(), false);
    }

    /**
     * Determine if the worker should stop.
     */
    protected function shouldStop(int $jobsProcessed, Carbon $startTime): bool
    {
        // Check max jobs
        if ($this->maxJobs > 0 && $jobsProcessed >= $this->maxJobs) {
            return true;
        }

        // Check max time
        if ($this->maxTime > 0 && Carbon::now()->diffInSeconds($startTime) >= $this->maxTime) {
            return true;
        }

        // Check memory limit
        if ($this->memoryExceeded()) {
            return true;
        }

        return false;
    }

    /**
     * Determine if the memory limit has been exceeded.
     */
    protected function memoryExceeded(): bool
    {
        return (memory_get_usage(true) / 1024 / 1024) >= $this->maxMemory;
    }

    /**
     * Sleep for the given number of seconds.
     */
    protected function sleep(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    /**
     * Register signal handlers for graceful shutdown.
     */
    protected function registerSignalHandlers(): void
    {
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);

            pcntl_signal(SIGTERM, function () {
                $this->shouldQuit = true;
            });

            pcntl_signal(SIGINT, function () {
                $this->shouldQuit = true;
            });

            pcntl_signal(SIGQUIT, function () {
                $this->shouldQuit = true;
            });
        }
    }

    // Fluent setters

    public function setQueue(string $queue): self
    {
        $this->queue = $queue;

        return $this;
    }

    public function setConnection(string $connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    public function setPrefetch(int $prefetch): self
    {
        $this->prefetch = $prefetch;

        return $this;
    }

    public function setTimeout(int $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    public function setMaxJobs(int $maxJobs): self
    {
        $this->maxJobs = $maxJobs;

        return $this;
    }

    public function setMaxTime(int $maxTime): self
    {
        $this->maxTime = $maxTime;

        return $this;
    }

    public function setMaxMemory(int $maxMemory): self
    {
        $this->maxMemory = $maxMemory;

        return $this;
    }

    public function setSleep(int $sleep): self
    {
        $this->sleep = $sleep;

        return $this;
    }

    public function setTries(int $tries): self
    {
        $this->tries = $tries;

        return $this;
    }

    public function setRest(int $rest): self
    {
        $this->rest = $rest;

        return $this;
    }

    public function setStopWhenEmpty(bool $stopWhenEmpty): self
    {
        $this->stopWhenEmpty = $stopWhenEmpty;

        return $this;
    }
}
