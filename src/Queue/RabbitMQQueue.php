<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Queue;

use AMQPChannelException;
use AMQPConnectionException;
use AMQPEnvelope;
use AMQPExchange;
use AMQPQueue;
use AMQPQueueException;
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
     * Note: ext-amqp doesn't expose message count from declareQueue().
     * This method returns 0 when successful. Use RabbitMQ Management API
     * for accurate queue sizes.
     *
     * @throws ConnectionException When unable to connect or access queue
     */
    public function size($queue = null): int
    {
        $queue = $this->getQueue($queue);

        try {
            $channel = $this->channelManager->topologyChannel();

            $amqpQueue = new AMQPQueue($channel);
            $amqpQueue->setName($queue);
            $amqpQueue->declareQueue();

            // ext-amqp doesn't expose count from declareQueue
            return 0;
        } catch (AMQPConnectionException $e) {
            Log::error('RabbitMQ connection failed while getting queue size', [
                'queue' => $queue,
                'error' => $e->getMessage(),
            ]);

            throw new ConnectionException(
                "Failed to connect to RabbitMQ while getting size of queue '{$queue}': {$e->getMessage()}",
                previous: $e
            );
        } catch (AMQPChannelException $e) {
            Log::error('RabbitMQ channel error while getting queue size', [
                'queue' => $queue,
                'error' => $e->getMessage(),
            ]);

            throw new ConnectionException(
                "Channel error while getting size of queue '{$queue}': {$e->getMessage()}",
                previous: $e
            );
        } catch (AMQPQueueException $e) {
            Log::error('RabbitMQ queue error while getting queue size', [
                'queue' => $queue,
                'error' => $e->getMessage(),
            ]);

            throw new ConnectionException(
                "Queue error while getting size of queue '{$queue}': {$e->getMessage()}",
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
            $amqpQueue = new AMQPQueue($channel);
            $amqpQueue->setName($queue);

            $envelope = $amqpQueue->get();

            if ($envelope instanceof AMQPEnvelope) {
                return new RabbitMQJob(
                    container: $this->container,
                    rabbitmq: $this,
                    queue: $amqpQueue,
                    envelope: $envelope,
                    connectionName: $this->connectionName,
                    queueName: $queue
                );
            }

            return null;
        } catch (AMQPConnectionException $e) {
            Log::error('RabbitMQ connection failed while popping job', [
                'queue' => $queue,
                'error' => $e->getMessage(),
            ]);

            throw new ConnectionException(
                "Failed to connect to RabbitMQ while popping from queue '{$queue}': {$e->getMessage()}",
                previous: $e
            );
        } catch (AMQPChannelException $e) {
            Log::error('RabbitMQ channel error while popping job', [
                'queue' => $queue,
                'error' => $e->getMessage(),
            ]);

            throw new ConnectionException(
                "Channel error while popping from queue '{$queue}': {$e->getMessage()}",
                previous: $e
            );
        } catch (AMQPQueueException $e) {
            Log::error('RabbitMQ queue error while popping job', [
                'queue' => $queue,
                'error' => $e->getMessage(),
            ]);

            throw new ConnectionException(
                "Queue error while popping from queue '{$queue}': {$e->getMessage()}",
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
            $channel = $this->channelManager->publishChannel();
            $channel->startTransaction();

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
                } catch (\Exception $e) {
                    $channel->rollbackTransaction();

                    Log::error('Batch publish failed', [
                        'queue' => $queue,
                        'failed_at_index' => $index,
                        'error' => $e->getMessage(),
                    ]);

                    throw new PublishException(
                        "Batch publish failed at job index {$index}: {$e->getMessage()}",
                        exchange: $exchange ?? '',
                        routingKey: $routingKey ?? '',
                        previous: $e
                    );
                }
            }

            $channel->commitTransaction();

            Log::info('Batch published successfully', [
                'queue' => $queue,
                'job_count' => count($jobs),
            ]);

            return $results;
        } catch (PublishException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Batch publish transaction failed', [
                'queue' => $queue,
                'error' => $e->getMessage(),
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
                $channel->confirmSelect();
                // IMPORTANT: Return FALSE to stop waiting, TRUE to continue waiting
                // (counterintuitive but per ext-amqp docs)
                // Note: basic.nack from broker only happens on internal Erlang errors (rare)
                $channel->setConfirmCallback(
                    fn (int $deliveryTag, bool $multiple): bool => false,  // ack - success, stop waiting
                    function (int $deliveryTag, bool $multiple, bool $requeue): bool {
                        // NACK means broker couldn't handle the message (internal error)
                        // This is rare but we should fail explicitly
                        throw new \AMQPException(
                            "Message nack'd by broker (delivery_tag: {$deliveryTag}, multiple: ".($multiple ? 'true' : 'false').')'
                        );
                    }
                );
                $this->confirmModeChannelId = $channelId;
            }

            $amqpExchange = new AMQPExchange($channel);
            $amqpExchange->setName($exchange);

            $attributes = $this->buildMessageAttributes($priority, $delay);

            // AMQPExchange::publish() returns void and throws on failure
            // Publisher confirms handle broker-level acknowledgment
            $amqpExchange->publish(
                $payload,
                $routingKey,
                AMQP_NOPARAM,
                $attributes
            );

            if ($this->useConfirms) {
                $channel->waitForConfirm($this->confirmTimeout);
            }
        } catch (PublishException $e) {
            Log::error('RabbitMQ message publish failed', [
                'exchange' => $exchange,
                'routing_key' => $routingKey,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } catch (\Exception $e) {
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
        \AMQPChannel $channel,
        string $exchange,
        string $routingKey,
        string $payload,
        ?int $priority = null,
    ): void {
        $amqpExchange = new AMQPExchange($channel);
        $amqpExchange->setName($exchange);

        $attributes = $this->buildMessageAttributes($priority, null);

        $amqpExchange->publish(
            $payload,
            $routingKey,
            AMQP_NOPARAM,
            $attributes
        );
    }

    /**
     * Build message attributes for publishing.
     *
     * @return array<string, mixed>
     */
    protected function buildMessageAttributes(?int $priority = null, ?int $delay = null): array
    {
        $attributes = [
            'delivery_mode' => 2, // Persistent messages for durability
            'content_type' => 'application/json',
            'message_id' => Str::uuid()->toString(),
            'timestamp' => time(),
        ];

        if ($priority !== null) {
            $attributes['priority'] = $priority;
        }

        // Add delay header for delayed exchange plugin
        if ($delay !== null && $delay > 0) {
            $attributes['headers'] = [
                'x-delay' => $delay,
            ];
        }

        return $attributes;
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
    public function ack(AMQPEnvelope $envelope, AMQPQueue $queue): void
    {
        try {
            $queue->ack($envelope->getDeliveryTag());
        } catch (AMQPQueueException|AMQPChannelException $e) {
            Log::error('Failed to acknowledge RabbitMQ message', [
                'delivery_tag' => $envelope->getDeliveryTag(),
                'message_id' => $envelope->getMessageId(),
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
    public function reject(AMQPEnvelope $envelope, AMQPQueue $queue, bool $requeue = false): void
    {
        try {
            $queue->reject($envelope->getDeliveryTag(), $requeue ? AMQP_REQUEUE : AMQP_NOPARAM);
        } catch (AMQPQueueException|AMQPChannelException $e) {
            Log::error('Failed to reject RabbitMQ message', [
                'delivery_tag' => $envelope->getDeliveryTag(),
                'message_id' => $envelope->getMessageId(),
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
