<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Queue;

use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Contracts\HasPriority;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Exceptions\ConnectionException;
use Lettermint\RabbitMQ\Exceptions\PublishException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * RabbitMQ Queue implementation for Laravel.
 *
 * This class implements Laravel's Queue contract, allowing standard Laravel
 * dispatch patterns to work with RabbitMQ. Includes publisher confirms for
 * message durability and comprehensive error logging.
 */
class RabbitMQQueue extends Queue implements QueueContract
{
    /**
     * The default queue name.
     */
    protected string $default;

    /**
     * Publisher confirm timeout in seconds.
     */
    protected float $confirmTimeout;

    /**
     * Whether to use publisher confirms.
     */
    protected bool $useConfirms;

    /**
     * The channel ID that has confirm mode enabled.
     * Used to detect when the channel is replaced (reconnection).
     */
    protected ?int $confirmModeChannelId = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected ChannelManager $channelManager,
        protected AttributeScanner $scanner,
        array $config,
    ) {
        $this->config = $config;

        // Handle both config structures:
        // - Laravel queue connection config: ['queue' => 'default', ...]
        // - Package config (rabbitmq.php): ['queue' => ['default' => 'default', ...], ...]
        $queue = $config['queue'] ?? 'default';
        $this->default = is_array($queue) ? ($queue['default'] ?? 'default') : $queue;

        $this->confirmTimeout = (float) ($config['confirm_timeout'] ?? 5.0);
        $this->useConfirms = (bool) ($config['publisher_confirms'] ?? true);
    }

    /**
     * Get the size of the queue.
     *
     * php-amqplib's queue_declare returns [queue_name, message_count, consumer_count].
     *
     * @throws ConnectionException When unable to connect or access queue
     */
    public function size($queue = null): int
    {
        $queue = $this->getQueue($queue);

        try {
            $channel = $this->channelManager->topologyChannel();

            // queue_declare returns [queue_name, message_count, consumer_count]
            // passive=true just checks if queue exists without modifying it
            [$queueName, $messageCount, $consumerCount] = $channel->queue_declare(
                $queue,
                true,   // passive - just check, don't create
                false,  // durable
                false,  // exclusive
                false   // auto_delete
            );

            return $messageCount;
        } catch (AMQPProtocolChannelException $e) {
            // Queue doesn't exist - channel is now closed by RabbitMQ
            // Invalidate the topology channel so next call creates a fresh one
            $this->channelManager->closeChannel('topology');

            Log::debug('RabbitMQ queue does not exist', [
                'queue' => $queue,
                'error' => $e->getMessage(),
            ]);

            return 0;
        } catch (AMQPIOException|AMQPConnectionClosedException|AMQPRuntimeException $e) {
            Log::error('RabbitMQ connection failed while getting queue size', [
                'queue' => $queue,
                'error' => $e->getMessage(),
            ]);

            throw new ConnectionException(
                "Failed to connect to RabbitMQ while getting size of queue '{$queue}': {$e->getMessage()}",
                previous: $e
            );
        }
    }

    /**
     * Push a new job onto the queue.
     *
     * @param  object|string  $job
     * @param  mixed  $data
     */
    public function push($job, $data = '', $queue = null): mixed
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            null,
            fn ($payload, $queue) => $this->pushRaw($payload, $queue, [
                'priority' => $this->getJobPriority($job),
            ])
        );
    }

    /**
     * Push a raw payload onto the queue.
     *
     * @param  array<string, mixed>  $options
     */
    public function pushRaw($payload, $queue = null, array $options = []): mixed
    {
        $queue = $this->getQueue($queue);

        [$exchange, $routingKey] = $this->getExchangeAndRoutingKey($queue, $payload);

        $this->publishMessage(
            exchange: $exchange,
            routingKey: $routingKey,
            payload: $payload,
            priority: $options['priority'] ?? null,
            delay: null
        );

        return $this->getPayloadId($payload);
    }

    /**
     * Push a new job onto the queue after a delay.
     *
     * @param  DateInterval|DateTimeInterface|int  $delay
     * @param  object|string  $job
     * @param  mixed  $data
     */
    public function later($delay, $job, $data = '', $queue = null): mixed
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            $delay,
            fn ($payload, $queue, $delay) => $this->laterRaw($delay, $payload, $queue, [
                'priority' => $this->getJobPriority($job),
            ])
        );
    }

    /**
     * Push a raw payload onto the queue after a delay.
     *
     * @param  DateInterval|DateTimeInterface|int  $delay
     * @param  array<string, mixed>  $options
     */
    protected function laterRaw($delay, string $payload, ?string $queue = null, array $options = []): mixed
    {
        $queue = $this->getQueue($queue);
        $delay = $this->secondsUntil($delay);

        [$exchange, $routingKey] = $this->getExchangeAndRoutingKey($queue, $payload);

        // Use delayed exchange if available
        $delayedExchange = $this->config['delayed_exchange'] ?? 'delayed';

        $this->publishMessage(
            exchange: $delayedExchange,
            routingKey: $routingKey,
            payload: $payload,
            priority: $options['priority'] ?? null,
            delay: (int) ($delay * 1000) // Convert to milliseconds
        );

        return $this->getPayloadId($payload);
    }

    /**
     * Pop the next job off of the queue.
     *
     * @throws ConnectionException When unable to connect or access queue
     */
    public function pop($queue = null): ?Job
    {
        $queue = $this->getQueue($queue);

        try {
            $channel = $this->channelManager->consumeChannel();

            // basic_get returns AMQPMessage or null
            $message = $channel->basic_get($queue, false);

            if ($message instanceof AMQPMessage) {
                return new RabbitMQJob(
                    container: $this->container,
                    rabbitmq: $this,
                    channel: $channel,
                    message: $message,
                    connectionName: $this->connectionName,
                    queueName: $queue
                );
            }

            return null;
        } catch (\Exception $e) {
            Log::error('RabbitMQ error while popping job', [
                'queue' => $queue,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            throw new ConnectionException(
                "Failed to pop from queue '{$queue}': {$e->getMessage()}",
                previous: $e
            );
        }
    }

    /**
     * Push a batch of jobs onto the queue.
     *
     * Uses RabbitMQ transactions to ensure all-or-nothing semantics.
     * Critical for operations where batch consistency is required.
     *
     * Note: Uses a separate 'batch' channel to avoid conflicts with publisher
     * confirms on the regular publish channel. Mixing tx_select and confirm_select
     * on the same channel is not supported by RabbitMQ.
     *
     * @param  array<object|string>  $jobs  Array of job instances or class names
     * @return array<array{status: string, job: string, id?: string}>
     *
     * @throws PublishException When batch publish fails
     */
    public function pushBatch(array $jobs, ?string $queue = null): array
    {
        if (empty($jobs)) {
            return [];
        }

        $results = [];
        $queue = $this->getQueue($queue);

        try {
            // Use dedicated batch channel to avoid tx/confirm mode conflict
            $channel = $this->channelManager->channel('batch');
            $channel->tx_select();

            foreach ($jobs as $index => $job) {
                try {
                    $payload = $this->createPayload($job, $queue, '');
                    [$exchange, $routingKey] = $this->getExchangeAndRoutingKey($queue, $payload);

                    $this->publishMessageWithoutConfirm(
                        $channel,
                        $exchange,
                        $routingKey,
                        $payload,
                        $this->getJobPriority($job)
                    );

                    $results[] = [
                        'status' => 'queued',
                        'job' => is_object($job) ? get_class($job) : $job,
                        'id' => $this->getPayloadId($payload),
                    ];
                } catch (AMQPIOException|AMQPConnectionClosedException|AMQPChannelClosedException|AMQPProtocolChannelException $e) {
                    $channel->tx_rollback();

                    Log::error('Batch publish failed', [
                        'queue' => $queue,
                        'failed_at_index' => $index,
                        'error' => $e->getMessage(),
                        'exception_class' => get_class($e),
                    ]);

                    throw new PublishException(
                        "Batch publish failed at job index {$index}: {$e->getMessage()}",
                        exchange: $exchange ?? '',
                        routingKey: $routingKey ?? '',
                        previous: $e
                    );
                }
            }

            $channel->tx_commit();

            Log::info('Batch published successfully', [
                'queue' => $queue,
                'job_count' => count($jobs),
            ]);

            return $results;
        } catch (PublishException $e) {
            throw $e;
        } catch (AMQPIOException|AMQPConnectionClosedException|AMQPChannelClosedException $e) {
            Log::error('Batch publish transaction failed', [
                'queue' => $queue,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);

            throw new PublishException(
                "Failed to publish batch: {$e->getMessage()}",
                exchange: '',
                routingKey: '',
                previous: $e
            );
        }
    }

    /**
     * Publish a message to RabbitMQ with optional publisher confirms.
     *
     * @throws PublishException When publish fails or confirm times out
     */
    protected function publishMessage(
        string $exchange,
        string $routingKey,
        string $payload,
        ?int $priority = null,
        ?int $delay = null,
    ): void {
        try {
            $channel = $this->channelManager->publishChannel();

            // Enable publisher confirms if needed.
            // Track by channel ID to detect when channel is replaced (reconnection).
            $channelId = spl_object_id($channel);
            if ($this->useConfirms && $this->confirmModeChannelId !== $channelId) {
                $channel->confirm_select();
                $this->confirmModeChannelId = $channelId;
            }

            $message = $this->buildMessage($payload, $priority, $delay);

            $channel->basic_publish($message, $exchange, $routingKey);

            if ($this->useConfirms) {
                try {
                    $channel->wait_for_pending_acks_returns($this->confirmTimeout);
                } catch (AMQPTimeoutException $e) {
                    // Timeout waiting for confirm - message was already published via basic_publish
                    // but we don't know for sure if RabbitMQ received it. Log as warning since
                    // the message may have been delivered, and let the caller decide whether to retry.
                    Log::warning('RabbitMQ publisher confirm timed out - message may have been delivered', [
                        'exchange' => $exchange,
                        'routing_key' => $routingKey,
                        'confirm_timeout' => $this->confirmTimeout,
                    ]);

                    // Don't throw - the message was published, we just couldn't confirm
                    // Throwing here would cause duplicates if caller retries
                }
            }
        } catch (PublishException $e) {
            Log::error('RabbitMQ message publish failed', [
                'exchange' => $exchange,
                'routing_key' => $routingKey,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } catch (AMQPIOException|AMQPConnectionClosedException|AMQPChannelClosedException|AMQPProtocolChannelException|AMQPRuntimeException $e) {
            Log::error('RabbitMQ publish error', [
                'exchange' => $exchange,
                'routing_key' => $routingKey,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);

            throw new PublishException(
                "Failed to publish message: {$e->getMessage()}",
                exchange: $exchange,
                routingKey: $routingKey,
                previous: $e
            );
        }
    }

    /**
     * Publish a message without confirms (for batch transactions).
     */
    protected function publishMessageWithoutConfirm(
        AMQPChannel $channel,
        string $exchange,
        string $routingKey,
        string $payload,
        ?int $priority = null,
    ): void {
        $message = $this->buildMessage($payload, $priority, null);

        $channel->basic_publish($message, $exchange, $routingKey);
    }

    /**
     * Build an AMQPMessage with properties.
     */
    protected function buildMessage(string $payload, ?int $priority = null, ?int $delay = null): AMQPMessage
    {
        $properties = [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'content_type' => 'application/json',
            'message_id' => Str::uuid()->toString(),
            'timestamp' => time(),
        ];

        if ($priority !== null) {
            $properties['priority'] = $priority;
        }

        // Add delay header for delayed exchange plugin
        if ($delay !== null && $delay > 0) {
            $properties['application_headers'] = new AMQPTable([
                'x-delay' => $delay,
            ]);
        }

        return new AMQPMessage($payload, $properties);
    }

    /**
     * Determine exchange and routing key for a queue.
     *
     * When a job class has multiple ConsumesQueue attributes (e.g., ProcessMessage
     * can be dispatched to either transactional or broadcast queues), we match
     * the attribute by the target queue name to get the correct exchange and
     * routing key for that specific queue.
     *
     * For jobs WITHOUT a ConsumesQueue attribute (e.g., third-party packages),
     * we use fallback routing:
     * - Exchange: config('rabbitmq.queue.exchange') - your fallback exchange
     * - Routing key: 'fallback.{queue_name}' (colons replaced with dots)
     *
     * To catch fallback messages, create a queue bound to your fallback exchange
     * with routing key 'fallback.#' (wildcard catches all fallback routes).
     *
     * @return array{0: string, 1: string}
     */
    protected function getExchangeAndRoutingKey(string $queue, string $payload): array
    {
        // Try to get ConsumesQueue attribute from the job class
        $data = json_decode($payload, true);
        $jobClass = $data['displayName'] ?? $data['data']['commandName'] ?? null;

        if ($jobClass !== null) {
            // Pass queue name to match correct attribute when job has multiple
            $attribute = $this->scanner->getQueueForJob($jobClass, $queue);

            if ($attribute !== null) {
                $exchange = $attribute->getPublishExchange();
                $routingKey = $attribute->getPublishRoutingKey();

                Log::debug('RabbitMQ routing from ConsumesQueue attribute', [
                    'job_class' => $jobClass,
                    'queue' => $queue,
                    'exchange' => $exchange,
                    'routing_key' => $routingKey,
                ]);

                return [
                    $exchange ?? config('rabbitmq.queue.exchange', ''),
                    $routingKey,
                ];
            }

            // Attribute not found - log for debugging
            Log::warning('RabbitMQ: ConsumesQueue attribute not found for job', [
                'job_class' => $jobClass,
                'queue' => $queue,
                'scanner_queue_count' => $this->scanner->getQueues()->count(),
                'scanner_classes' => $this->scanner->getQueues()->pluck('class')->unique()->values()->toArray(),
            ]);
        }

        // Fallback: use package config with 'fallback.' prefix for routing key
        // Note: Use config('rabbitmq...') not $this->config which is the connection config
        // The 'fallback.' prefix allows you to create a catch-all queue bound to 'fallback.#'
        $exchange = config('rabbitmq.queue.exchange', '');
        $routingKey = 'fallback.'.str_replace(':', '.', $queue);

        Log::debug('RabbitMQ routing using fallback', [
            'job_class' => $jobClass,
            'queue' => $queue,
            'exchange' => $exchange,
            'routing_key' => $routingKey,
        ]);

        return [$exchange, $routingKey];
    }

    /**
     * Get the priority from a job if it implements HasPriority.
     */
    protected function getJobPriority(mixed $job): ?int
    {
        if ($job instanceof HasPriority) {
            return $job->getPriority();
        }

        return null;
    }

    /**
     * Get the ID from the payload.
     */
    protected function getPayloadId(string $payload): string
    {
        $data = json_decode($payload, true);

        return $data['uuid'] ?? Str::uuid()->toString();
    }

    /**
     * Get the queue name, falling back to the default.
     */
    public function getQueue(?string $queue): string
    {
        return $queue ?? $this->default;
    }

    /**
     * Get the underlying channel manager.
     */
    public function getChannelManager(): ChannelManager
    {
        return $this->channelManager;
    }

    /**
     * Acknowledge a message.
     *
     * Critical operation - failures are logged for visibility.
     *
     * @throws ConnectionException When acknowledgment fails
     */
    public function ack(AMQPMessage $message, AMQPChannel $channel): void
    {
        try {
            $channel->basic_ack($message->getDeliveryTag());
        } catch (AMQPIOException|AMQPChannelClosedException $e) {
            Log::error('Failed to acknowledge RabbitMQ message', [
                'delivery_tag' => $message->getDeliveryTag(),
                'message_id' => $message->get('message_id'),
                'error' => $e->getMessage(),
            ]);

            throw new ConnectionException(
                "Failed to acknowledge message: {$e->getMessage()}",
                previous: $e
            );
        }
    }

    /**
     * Reject a message (send to DLQ if configured).
     *
     * Critical operation - failures are logged for visibility.
     *
     * @throws ConnectionException When rejection fails
     */
    public function reject(AMQPMessage $message, AMQPChannel $channel, bool $requeue = false): void
    {
        try {
            $channel->basic_reject($message->getDeliveryTag(), $requeue);
        } catch (AMQPIOException|AMQPChannelClosedException $e) {
            Log::error('Failed to reject RabbitMQ message', [
                'delivery_tag' => $message->getDeliveryTag(),
                'message_id' => $message->get('message_id'),
                'requeue' => $requeue,
                'error' => $e->getMessage(),
            ]);

            throw new ConnectionException(
                "Failed to reject message: {$e->getMessage()}",
                previous: $e
            );
        }
    }
}
