<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Consumers;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Exceptions\ConnectionException;
use Lettermint\RabbitMQ\Queue\RabbitMQJob;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\Heartbeat\PCNTLHeartbeatSender;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

/**
 * RabbitMQ message consumer.
 *
 * This class handles the consumption of messages from RabbitMQ queues,
 * processing them through Laravel's job system. Uses basic_consume for
 * push-based message delivery and PCNTLHeartbeatSender for maintaining
 * connections during long-running jobs.
 *
 * IMPORTANT PRODUCTION NOTES:
 *
 * 1. Signal Handler Conflict (SIGALRM):
 *    PCNTLHeartbeatSender uses SIGALRM for heartbeat signals. If your jobs use
 *    Laravel's $timeout property, the job timeout mechanism also uses SIGALRM.
 *    This can cause the heartbeat handler to be overwritten, potentially leading
 *    to connection drops during long-running jobs. Consider either:
 *    - Using maxTime on the consumer instead of $timeout on individual jobs
 *    - Setting heartbeat_sender.enabled = false in config if you use job timeouts
 *    - Ensuring jobs complete within the heartbeat interval
 *
 * 2. No Automatic Reconnection:
 *    If the RabbitMQ connection drops, the consumer will throw a ConnectionException
 *    and exit. The consumer does NOT automatically reconnect. Use a process manager
 *    like Supervisor to restart consumers after failures:
 *
 *    [program:rabbitmq-consumer]
 *    command=php artisan rabbitmq:consume your-queue
 *    autostart=true
 *    autorestart=true
 *    startsecs=0
 *    numprocs=1
 *    redirect_stderr=true
 *
 * 3. Graceful Shutdown:
 *    The consumer handles SIGTERM, SIGINT, and SIGQUIT for graceful shutdown.
 *    It will finish processing the current job before stopping.
 */
class Consumer
{
    /**
     * The queues to consume from.
     *
     * @var list<string>
     */
    protected array $queues = ['default'];

    protected string $connection = 'rabbitmq';

    protected int $prefetch = 10;

    /**
     * Job timeout in seconds.
     *
     * Note: This is reserved for future implementation. Currently, job timeouts
     * are handled at the Laravel job level via the $timeout property on job classes.
     */
    protected int $timeout = 60;

    protected int $maxJobs = 0;

    protected int $maxTime = 0;

    protected int $maxMemory = 128;

    protected int $sleep = 3;

    protected int $tries = 3;

    protected int $rest = 0;

    protected bool $stopWhenEmpty = false;

    protected bool $shouldQuit = false;

    protected int $jobsProcessed = 0;

    protected ?Carbon $startTime = null;

    /**
     * The heartbeat sender for maintaining connections during long-running jobs.
     */
    protected ?PCNTLHeartbeatSender $heartbeatSender = null;

    /**
     * Whether to use the heartbeat sender.
     */
    protected bool $useHeartbeatSender = true;

    /**
     * The active channel for consuming.
     */
    protected ?AMQPChannel $channel = null;

    /**
     * Consumer tags for cancelling consumption, keyed by queue name.
     *
     * @var array<string, string>
     */
    protected array $consumerTags = [];

    public function __construct(
        protected ChannelManager $channelManager,
        protected AttributeScanner $scanner,
        protected RabbitMQQueue $rabbitmq,
        protected ExceptionHandler $exceptions,
        protected Dispatcher $events,
    ) {}

    /**
     * Start consuming messages from the queue.
     *
     * Uses basic_consume for push-based message delivery. The wait() method
     * processes heartbeats between jobs, while PCNTLHeartbeatSender handles
     * heartbeats DURING job execution (via SIGALRM).
     *
     * @throws ConnectionException When consumer fails to initialize or connection is lost
     */
    public function consume(): void
    {
        $this->startTime = Carbon::now();
        $this->jobsProcessed = 0;
        $this->shouldQuit = false;
        $this->consumerTags = [];

        try {
            $this->channel = $this->channelManager->consumeChannel($this->connection);
            $this->channel->basic_qos(0, $this->prefetch, false);
        } catch (AMQPIOException|AMQPChannelClosedException|AMQPConnectionClosedException|AMQPProtocolChannelException|AMQPRuntimeException $e) {
            $this->channel = null;
            $this->channelManager->closeChannel('consume', $this->connection);

            Log::error('RabbitMQ consumer failed to start', [
                'queues' => $this->queues,
                'error' => $e->getMessage(),
            ]);

            throw new ConnectionException(
                "Consumer failed to initialize for queue(s) '{$this->queueLabel()}': {$e->getMessage()}",
                previous: $e
            );
        }

        try {
            $connection = $this->channelManager->getConnection($this->connection);
        } catch (Throwable $e) {
            $this->channel = null;
            $this->channelManager->closeChannel('consume', $this->connection);

            throw $e;
        }

        // Register heartbeat sender BEFORE consuming
        // Note: PCNTLHeartbeatSender uses SIGALRM which may conflict with Laravel's
        // job timeout mechanism. If jobs have $timeout set, the heartbeat sender's
        // signal handler may be overwritten, potentially causing connection drops
        // during long-running jobs.
        if ($this->shouldUseHeartbeatSender($connection)) {
            try {
                $this->heartbeatSender = new PCNTLHeartbeatSender($connection);
                $this->heartbeatSender->register();

                Log::debug('RabbitMQ heartbeat sender registered', [
                    'queues' => $this->queues,
                    'heartbeat_interval' => $connection->getHeartbeat(),
                ]);
            } catch (Throwable $e) {
                // Registration can fail if signal handlers conflict or pcntl is misconfigured
                Log::warning('RabbitMQ heartbeat sender registration failed - long-running jobs may cause connection drops', [
                    'queues' => $this->queues,
                    'heartbeat_interval' => $connection->getHeartbeat(),
                    'error' => $e->getMessage(),
                ]);

                $this->heartbeatSender = null;
            }
        }

        // Register signal handlers for graceful shutdown
        $this->registerSignalHandlers();

        try {
            // Register one callback per queue on one channel. RabbitMQ applies
            // non-global QoS to each consumer. The closure keeps the source
            // queue name for job events, logging, and dead-letter handling.
            foreach ($this->queues as $queue) {
                $this->consumerTags[$queue] = $this->channel->basic_consume(
                    $queue,
                    '',              // consumer tag (auto-generated)
                    false,           // no_local
                    false,           // no_ack (we ack manually)
                    false,           // exclusive
                    false,           // nowait
                    function (AMQPMessage $message) use ($queue): void {
                        $this->handleMessage($message, $queue);
                    }
                );
            }

            Log::info('RabbitMQ consumer started', [
                'queues' => $this->queues,
                'consumer_tags' => $this->consumerTags,
                'prefetch' => $this->prefetch,
            ]);

            // Consume loop - wait() handles heartbeats between jobs
            while ($this->channel->is_consuming() && ! $this->shouldQuit) {
                // Check if we should stop
                if ($this->shouldStop($this->jobsProcessed, $this->startTime)) {
                    break;
                }

                // Fire the looping event
                $this->events->dispatch(new Looping($this->connection, $this->queueLabel()));

                try {
                    // wait() processes heartbeats while waiting for messages
                    // PCNTLHeartbeatSender handles heartbeats DURING job execution
                    $this->channel->wait(null, false, $this->timeout ?: 30);
                } catch (AMQPTimeoutException $e) {
                    // Timeout during wait() - normal when queue is empty
                    // Check if we should stop when empty
                    if ($this->stopWhenEmpty) {
                        break;
                    }
                }
            }
        } catch (AMQPIOException|AMQPChannelClosedException|AMQPConnectionClosedException|AMQPProtocolChannelException|AMQPRuntimeException $e) {
            Log::error('RabbitMQ consumer connection lost', [
                'queues' => $this->queues,
                'jobs_processed' => $this->jobsProcessed,
                'error' => $e->getMessage(),
            ]);

            throw new ConnectionException(
                "Consumer lost connection to queue(s) '{$this->queueLabel()}': {$e->getMessage()}",
                previous: $e
            );
        } finally {
            $this->cleanup();
        }
    }

    /**
     * Handle an incoming message.
     */
    protected function handleMessage(AMQPMessage $message, string $queue): void
    {
        $job = new RabbitMQJob(
            container: app(),
            rabbitmq: $this->rabbitmq,
            channel: $message->getChannel(),
            message: $message,
            connectionName: $this->connection,
            queueName: $queue
        );

        $this->processJob($job);
        $this->jobsProcessed++;

        // Rest between jobs if configured
        if ($this->rest > 0) {
            $this->sleep($this->rest);
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
            // Intentional retries use a package attempt header. RabbitMQ
            // delivery count is reserved for crash-loop protection.
            $job->releaseWithException(0, $e);
        }
    }

    /**
     * Mark the job as failed.
     *
     * @throws ConnectionException When rejection fails and consumer cannot safely continue
     */
    protected function failJob(RabbitMQJob $job, Throwable $e): void
    {
        $this->events->dispatch(new JobFailed($this->connection, $job, $e));

        try {
            // Reject without requeue - will go to DLQ
            $this->rabbitmq->reject($job->getMessage(), $job->getChannel(), false);
        } catch (ConnectionException $rejectException) {
            Log::critical('Failed to reject job after failure - message state undefined', [
                'queue' => $job->getQueue(),
                'job_id' => $job->getJobId(),
                'job_name' => $job->getName(),
                'original_error' => $e->getMessage(),
                'reject_error' => $rejectException->getMessage(),
            ]);

            // Re-throw to stop the consumer - we cannot safely continue
            throw $rejectException;
        }
    }

    /**
     * Determine if the heartbeat sender should be used.
     */
    protected function shouldUseHeartbeatSender(AbstractConnection $connection): bool
    {
        // Check if enabled in config
        if (! $this->useHeartbeatSender) {
            return false;
        }

        if (! config('rabbitmq.heartbeat_sender.enabled', true)) {
            return false;
        }

        // Check if pcntl extension is available
        if (! extension_loaded('pcntl')) {
            Log::warning('RabbitMQ heartbeat sender not available: pcntl extension not loaded', [
                'queues' => $this->queues,
            ]);

            return false;
        }

        // Check if connection has heartbeat enabled
        if ($connection->getHeartbeat() <= 0) {
            return false;
        }

        return true;
    }

    /**
     * Clean up resources.
     */
    protected function cleanup(): void
    {
        // Unregister heartbeat sender
        if ($this->heartbeatSender !== null) {
            $this->heartbeatSender->unregister();
            $this->heartbeatSender = null;

            Log::debug('RabbitMQ heartbeat sender unregistered', [
                'queues' => $this->queues,
            ]);
        }

        // Cancel every registered consumer
        if ($this->channel !== null && $this->channel->is_open()) {
            foreach ($this->consumerTags as $queue => $consumerTag) {
                try {
                    $this->channel->basic_cancel($consumerTag);
                } catch (AMQPIOException|AMQPChannelClosedException $e) {
                    Log::debug('Consumer cancel during cleanup (expected)', [
                        'queue' => $queue,
                        'consumer_tag' => $consumerTag,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->consumerTags = [];
        $this->channel = null;
        $this->channelManager->closeChannel('consume', $this->connection);

        Log::info('RabbitMQ consumer stopped', [
            'queues' => $this->queues,
            'jobs_processed' => $this->jobsProcessed,
            'runtime_seconds' => $this->startTime?->diffInSeconds(Carbon::now()),
        ]);
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

            // Note: SIGALRM is used by PCNTLHeartbeatSender for heartbeats
            // We only register SIGTERM, SIGINT, SIGQUIT for graceful shutdown

            pcntl_signal(SIGTERM, function (): void {
                $this->shouldQuit = true;
            });

            pcntl_signal(SIGINT, function (): void {
                $this->shouldQuit = true;
            });

            pcntl_signal(SIGQUIT, function (): void {
                $this->shouldQuit = true;
            });
        }
    }

    /**
     * Human-readable label for the consumed queue(s), used in logs and events.
     */
    protected function queueLabel(): string
    {
        return implode(', ', $this->queues);
    }

    // Fluent setters

    public function setQueue(string $queue): self
    {
        return $this->setQueues([$queue]);
    }

    /**
     * Set the queues to consume from.
     *
     * @param  array<array-key, mixed>  $queues
     *
     * @throws InvalidArgumentException
     */
    public function setQueues(array $queues): self
    {
        if ($queues === []) {
            throw new InvalidArgumentException('At least one RabbitMQ queue is required');
        }

        $normalized = [];

        foreach ($queues as $queue) {
            if (! is_string($queue) || trim($queue) === '') {
                throw new InvalidArgumentException('RabbitMQ queue names must be non-empty strings');
            }

            $normalized[] = trim($queue);
        }

        if (count(array_unique($normalized)) !== count($normalized)) {
            throw new InvalidArgumentException('RabbitMQ queue names must be unique');
        }

        $this->queues = $normalized;

        return $this;
    }

    public function setConnection(string $connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    public function setPrefetch(int $prefetch): self
    {
        if ($prefetch < 1) {
            throw new InvalidArgumentException('RabbitMQ prefetch must be at least 1');
        }

        $this->prefetch = $prefetch;

        return $this;
    }

    public function setTimeout(int $timeout): self
    {
        if ($timeout < 1) {
            throw new InvalidArgumentException('RabbitMQ wait timeout must be at least 1 second');
        }

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

    public function setUseHeartbeatSender(bool $use): self
    {
        $this->useHeartbeatSender = $use;

        return $this;
    }
}
