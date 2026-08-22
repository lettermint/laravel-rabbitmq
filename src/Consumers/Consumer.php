<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Consumers;

use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Exceptions\ConnectionException;
use Lettermint\RabbitMQ\Exceptions\PublishException;
use Lettermint\RabbitMQ\Queue\RabbitMQJob;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;
use Lettermint\RabbitMQ\Support\ExceptionReporter;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\Heartbeat\SIGHeartbeatSender;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

final class Consumer
{
    /** @var list<string> */
    protected array $queues = ['default'];

    protected string $connection = 'rabbitmq';

    protected int $prefetch = 1;

    protected float $waitTimeout = 1.0;

    protected int $jobTimeout = 60;

    protected int $maxJobs = 0;

    protected int $maxTime = 0;

    protected int $maxMemory = 128;

    protected int $sleep = 3;

    protected int $tries = 1;

    protected int $rest = 0;

    protected int|array $backoff = 0;

    protected bool $force = false;

    protected bool $stopWhenEmpty = false;

    protected bool $shouldQuit = false;

    protected int $jobsProcessed = 0;

    protected ?Carbon $startTime = null;

    protected ?AMQPChannel $channel = null;

    /** @var array<string, string> */
    protected array $consumerTags = [];

    protected ?RabbitMQQueue $rabbitmq = null;

    protected ?string $brokerConnection = null;

    protected ?SIGHeartbeatSender $heartbeatSender = null;

    protected bool $heartbeatStopListenerRegistered = false;

    public function __construct(
        protected ChannelManager $channelManager,
        protected QueueManager $queueManager,
        protected RabbitMQWorker $worker,
    ) {}

    /**
     * Consume messages and recover a failed broker connection within configured limits.
     */
    public function consume(): void
    {
        $this->startTime = Carbon::now();
        $this->jobsProcessed = 0;
        $this->shouldQuit = false;
        $this->consumerTags = [];
        $this->registerSignalHandlers();
        $this->registerHeartbeatStopListener();
        $this->resolveQueueConnection();

        $maximumRecoveries = max(0, (int) config('rabbitmq.recovery.max_attempts', 3));

        try {
            while (! $this->shouldQuit) {
                try {
                    $this->consumeSession();

                    return;
                } catch (AMQPIOException|AMQPChannelClosedException|AMQPConnectionClosedException|AMQPProtocolChannelException|AMQPRuntimeException|ConnectionException|PublishException $exception) {
                    $this->cleanup();

                    if ($this->shouldQuit || $maximumRecoveries === 0) {
                        ExceptionReporter::report($exception);

                        throw new ConnectionException(
                            "RabbitMQ consumer recovery failed for [{$this->queueLabel()}]: {$exception->getMessage()}",
                            previous: $exception,
                        );
                    }

                    $this->channelManager->recoverConnection($this->brokerConnection, $maximumRecoveries);
                }
            }
        } finally {
            $this->cleanup();
        }
    }

    protected function consumeSession(): void
    {
        $this->channel = $this->channelManager->consumeChannel($this->brokerConnection);
        $this->startHeartbeatSender();
        $this->channel->basic_qos(0, $this->prefetch, false);
        $this->consumerTags = [];

        foreach ($this->queues as $queue) {
            $physicalQueue = $this->rabbitmq()->physicalQueue($queue);
            $this->consumerTags[$queue] = $this->channel->basic_consume(
                $physicalQueue,
                '',
                false,
                false,
                false,
                false,
                function (AMQPMessage $message) use ($queue): void {
                    $this->handleMessage($message, $queue);
                },
            );
        }

        Log::info('RabbitMQ consumer started', [
            'connection' => $this->connection,
            'queues' => $this->queues,
            'prefetch' => $this->prefetch,
        ]);

        while ($this->channel->is_consuming() && ! $this->shouldQuit) {
            if ($this->shouldStop()) {
                break;
            }

            if (app()->isDownForMaintenance() && ! $this->force) {
                $this->sleep($this->sleep);

                continue;
            }

            event(new Looping($this->connection, $this->queueLabel()));

            try {
                $this->channel->wait(null, false, $this->waitTimeout);
            } catch (AMQPTimeoutException) {
                if ($this->stopWhenEmpty) {
                    break;
                }
            }
        }
    }

    protected function handleMessage(AMQPMessage $message, string $queue): void
    {
        $job = new RabbitMQJob(
            container: app(),
            rabbitmq: $this->rabbitmq(),
            channel: $message->getChannel(),
            message: $message,
            connectionName: $this->connection,
            queueName: $queue,
        );

        $this->worker->processMessage($job, $this->connection, $this->workerOptions());
        $this->jobsProcessed++;

        if ($this->rest > 0) {
            $this->sleep($this->rest);
        }
    }

    protected function resolveQueueConnection(): void
    {
        $connection = $this->queueManager->connection($this->connection);

        if (! $connection instanceof RabbitMQQueue) {
            throw new InvalidArgumentException(
                "Laravel queue connection [{$this->connection}] does not use the Lettermint RabbitMQ driver."
            );
        }

        $this->rabbitmq = $connection;
        $this->brokerConnection = $connection->getBrokerConnectionName();

        foreach ($this->queues as $queue) {
            $connection->physicalQueue($queue);
        }
    }

    protected function rabbitmq(): RabbitMQQueue
    {
        if (! $this->rabbitmq instanceof RabbitMQQueue) {
            throw new ConnectionException('The RabbitMQ queue connection is not resolved.');
        }

        return $this->rabbitmq;
    }

    protected function workerOptions(): WorkerOptions
    {
        return new WorkerOptions(
            name: $this->connection,
            backoff: $this->backoff,
            memory: $this->maxMemory,
            timeout: $this->jobTimeout,
            sleep: $this->sleep,
            maxTries: $this->tries,
            force: $this->force,
            stopWhenEmpty: $this->stopWhenEmpty,
            maxJobs: $this->maxJobs,
            maxTime: $this->maxTime,
            rest: $this->rest,
        );
    }

    protected function cleanup(): void
    {
        $this->stopHeartbeatSender();

        if ($this->channel !== null && $this->channel->is_open()) {
            foreach ($this->consumerTags as $queue => $consumerTag) {
                try {
                    $this->channel->basic_cancel($consumerTag);
                } catch (Throwable $exception) {
                    Log::debug('RabbitMQ consumer cancellation failed during cleanup', [
                        'queue' => $queue,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        $this->consumerTags = [];
        $this->channel = null;
        $this->channelManager->closeChannel('consume', $this->brokerConnection);
    }

    protected function shouldStop(): bool
    {
        if ($this->maxJobs > 0 && $this->jobsProcessed >= $this->maxJobs) {
            return true;
        }

        if ($this->maxTime > 0 && $this->startTime?->diffInSeconds(Carbon::now()) >= $this->maxTime) {
            return true;
        }

        return (memory_get_usage(true) / 1024 / 1024) >= $this->maxMemory;
    }

    protected function sleep(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    protected function registerSignalHandlers(): void
    {
        if (! extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
            pcntl_signal($signal, function (): void {
                $this->shouldQuit = true;
            });
        }
    }

    protected function registerHeartbeatStopListener(): void
    {
        if ($this->heartbeatStopListenerRegistered) {
            return;
        }

        app('events')->listen(WorkerStopping::class, function (): void {
            $this->stopHeartbeatSender();
        });
        $this->heartbeatStopListenerRegistered = true;
    }

    protected function startHeartbeatSender(): void
    {
        if (! config('rabbitmq.consumer.heartbeat_sender', true)) {
            return;
        }

        if (! extension_loaded('pcntl')
            || ! extension_loaded('posix')
            || ! function_exists('pcntl_fork')
            || ! function_exists('posix_kill')) {
            Log::warning('RabbitMQ heartbeat sender is unavailable; long jobs can cause broker redelivery');

            return;
        }

        $connection = $this->channelManager->getConnection($this->brokerConnection);

        if ($connection->getHeartbeat() <= 0) {
            return;
        }

        $this->heartbeatSender = new SIGHeartbeatSender($connection);
        $this->heartbeatSender->register();
    }

    protected function stopHeartbeatSender(): void
    {
        if (! $this->heartbeatSender instanceof SIGHeartbeatSender) {
            return;
        }

        try {
            $this->heartbeatSender->unregister();
        } catch (Throwable $exception) {
            Log::debug('RabbitMQ heartbeat sender cleanup failed', [
                'error' => $exception->getMessage(),
            ]);
        } finally {
            $this->heartbeatSender = null;
        }
    }

    protected function queueLabel(): string
    {
        return implode(', ', $this->queues);
    }

    public function setQueue(string $queue): self
    {
        return $this->setQueues([$queue]);
    }

    /** @param  array<array-key, mixed>  $queues */
    public function setQueues(array $queues): self
    {
        if ($queues === []) {
            throw new InvalidArgumentException('At least one RabbitMQ queue is required.');
        }

        $normalized = [];

        foreach ($queues as $queue) {
            if (! is_string($queue) || trim($queue) === '') {
                throw new InvalidArgumentException('RabbitMQ queue names must be non-empty strings.');
            }

            $normalized[] = trim($queue);
        }

        if (count(array_unique($normalized)) !== count($normalized)) {
            throw new InvalidArgumentException('RabbitMQ queue names must be unique.');
        }

        $this->queues = $normalized;

        return $this;
    }

    public function setConnection(string $connection): self
    {
        if (trim($connection) === '') {
            throw new InvalidArgumentException('The Laravel queue connection name cannot be empty.');
        }

        $this->connection = $connection;

        return $this;
    }

    public function setPrefetch(int $prefetch): self
    {
        if ($prefetch < 1) {
            throw new InvalidArgumentException('RabbitMQ prefetch must be at least 1.');
        }

        $this->prefetch = $prefetch;

        return $this;
    }

    public function setTimeout(int $timeout): self
    {
        if ($timeout < 1) {
            throw new InvalidArgumentException('The Laravel job timeout must be at least 1 second.');
        }

        $this->jobTimeout = $timeout;

        return $this;
    }

    public function setWaitTimeout(float $timeout): self
    {
        if ($timeout <= 0) {
            throw new InvalidArgumentException('The RabbitMQ wait timeout must be greater than zero.');
        }

        $this->waitTimeout = $timeout;

        return $this;
    }

    public function setMaxJobs(int $maxJobs): self
    {
        $this->maxJobs = max(0, $maxJobs);

        return $this;
    }

    public function setMaxTime(int $maxTime): self
    {
        $this->maxTime = max(0, $maxTime);

        return $this;
    }

    public function setMaxMemory(int $maxMemory): self
    {
        if ($maxMemory < 1) {
            throw new InvalidArgumentException('The worker memory limit must be at least 1 MB.');
        }

        $this->maxMemory = $maxMemory;

        return $this;
    }

    public function setSleep(int $sleep): self
    {
        $this->sleep = max(0, $sleep);

        return $this;
    }

    public function setTries(int $tries): self
    {
        $this->tries = max(0, $tries);

        return $this;
    }

    public function setBackoff(int|array $backoff): self
    {
        $this->backoff = $backoff;

        return $this;
    }

    public function setRest(int $rest): self
    {
        $this->rest = max(0, $rest);

        return $this;
    }

    public function setForce(bool $force): self
    {
        $this->force = $force;

        return $this;
    }

    public function setStopWhenEmpty(bool $stopWhenEmpty): self
    {
        $this->stopWhenEmpty = $stopWhenEmpty;

        return $this;
    }
}
