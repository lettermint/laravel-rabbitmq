<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Attributes;

use Attribute;
use InvalidArgumentException;
use Lettermint\RabbitMQ\Enums\OverflowBehavior;
use Lettermint\RabbitMQ\Enums\RetryStrategy;

/**
 * Defines queue configuration for a Laravel job class.
 *
 * This is the primary attribute for declaring RabbitMQ queue topology. Apply it
 * to your Laravel job classes to define:
 * - Queue name and settings
 * - Exchange bindings
 * - Dead letter queue configuration
 * - Consumer settings (prefetch, timeout)
 * - Retry strategies
 *
 * The package will automatically:
 * - Create the queue with specified settings
 * - Create bindings to exchanges
 * - Create DLQ exchange and queue
 * - Configure retry behavior
 *
 * @example
 * ```php
 * // Quorum queue (recommended for production - HA, durable)
 * #[ConsumesQueue(
 *     queue: 'email:outbound:transactional',
 *     bindings: ['emails.outbound' => 'transactional.*'],
 *     quorum: true,
 *     messageTtl: 86400000, // 24 hours
 *     retryAttempts: 3,
 *     retryStrategy: RetryStrategy::Exponential,
 *     retryDelays: [60, 300, 900],
 *     prefetch: 25,
 *     timeout: 120,
 * )]
 *
 * // Classic queue with priorities (when ordering matters more than HA)
 * #[ConsumesQueue(
 *     queue: 'priority:jobs',
 *     bindings: ['background' => 'priority.*'],
 *     quorum: false,
 *     maxPriority: 10,
 * )]
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class ConsumesQueue
{
    /**
     * @deprecated Use RetryStrategy::Exponential enum instead
     */
    public const RETRY_STRATEGY_EXPONENTIAL = 'exponential';

    /**
     * @deprecated Use RetryStrategy::Fixed enum instead
     */
    public const RETRY_STRATEGY_FIXED = 'fixed';

    /**
     * @deprecated Use RetryStrategy::Linear enum instead
     */
    public const RETRY_STRATEGY_LINEAR = 'linear';

    /**
     * The resolved retry strategy enum.
     */
    public readonly RetryStrategy $retryStrategyEnum;

    /**
     * The resolved overflow behavior enum.
     */
    public readonly OverflowBehavior $overflowEnum;

    /**
     * @param  string  $queue  Queue name (e.g., 'email:outbound:transactional')
     * @param  array<string, string|array<string>>  $bindings  Exchange bindings: ['exchange' => 'routing.key'] or ['exchange' => ['key1', 'key2']]
     * @param  bool  $quorum  Use quorum queue for HA (default: true, recommended for production)
     * @param  int|null  $maxPriority  Enable priority queue with max level (0-255). NOT compatible with quorum queues.
     * @param  int|null  $messageTtl  Message TTL in milliseconds (e.g., 86400000 = 24 hours)
     * @param  int|null  $maxLength  Maximum queue length (messages rejected/dead-lettered when exceeded)
     * @param  OverflowBehavior|string  $overflow  Overflow behavior when maxLength exceeded
     * @param  string|null  $dlqExchange  Custom DLQ exchange (null = auto-derive from binding domain)
     * @param  int  $retryAttempts  Maximum retry attempts before permanent DLQ (default: 3)
     * @param  RetryStrategy|string  $retryStrategy  Retry strategy for failed jobs
     * @param  array<int>  $retryDelays  Delay between retries in seconds [60, 300, 900]
     * @param  int  $prefetch  Consumer prefetch count / QoS (default: 10)
     * @param  int  $timeout  Job timeout in seconds (default: 30)
     *
     * @throws InvalidArgumentException When validation fails
     */
    public function __construct(
        public string $queue,
        public array $bindings = [],
        public bool $quorum = true,
        public ?int $maxPriority = null,
        public ?int $messageTtl = null,
        public ?int $maxLength = null,
        OverflowBehavior|string $overflow = OverflowBehavior::RejectPublishDlx,
        public ?string $dlqExchange = null,
        public int $retryAttempts = 3,
        RetryStrategy|string $retryStrategy = RetryStrategy::Exponential,
        public array $retryDelays = [60, 300, 900],
        public int $prefetch = 10,
        public int $timeout = 30,
    ) {
        // Validate queue name
        if (trim($this->queue) === '') {
            throw new InvalidArgumentException('Queue name cannot be empty');
        }

        // Validate maxPriority range (RabbitMQ supports 0-255)
        if ($this->maxPriority !== null && ($this->maxPriority < 0 || $this->maxPriority > 255)) {
            throw new InvalidArgumentException(
                "maxPriority must be between 0 and 255, got {$this->maxPriority}"
            );
        }

        // Quorum queues do not support priority - this is a RabbitMQ limitation
        if ($this->quorum && $this->maxPriority !== null) {
            throw new InvalidArgumentException(
                'Cannot use maxPriority with quorum queues. RabbitMQ quorum queues do not support '.
                'x-max-priority. Either set quorum: false to use a classic queue with priorities, '.
                'or remove maxPriority to use a quorum queue for high availability.'
            );
        }

        // Validate prefetch (must be at least 1)
        if ($this->prefetch < 1) {
            throw new InvalidArgumentException(
                "prefetch must be at least 1, got {$this->prefetch}"
            );
        }

        // Validate timeout
        if ($this->timeout < 1) {
            throw new InvalidArgumentException(
                "timeout must be at least 1 second, got {$this->timeout}"
            );
        }

        // Validate retryAttempts
        if ($this->retryAttempts < 0) {
            throw new InvalidArgumentException(
                "retryAttempts cannot be negative, got {$this->retryAttempts}"
            );
        }

        // Validate messageTtl
        if ($this->messageTtl !== null && $this->messageTtl < 0) {
            throw new InvalidArgumentException(
                "messageTtl cannot be negative, got {$this->messageTtl}"
            );
        }

        // Validate maxLength
        if ($this->maxLength !== null && $this->maxLength < 1) {
            throw new InvalidArgumentException(
                "maxLength must be at least 1, got {$this->maxLength}"
            );
        }

        // Validate retryDelays - runtime check needed as PHP doesn't enforce array element types
        foreach ($this->retryDelays as $index => $delay) {
            /** @phpstan-ignore function.alreadyNarrowedType */
            if (! is_int($delay) || $delay < 1) {
                throw new InvalidArgumentException(
                    "retryDelays must contain positive integers, got invalid value at index {$index}"
                );
            }
        }

        // Convert string to enum for retryStrategy
        $this->retryStrategyEnum = $retryStrategy instanceof RetryStrategy
            ? $retryStrategy
            : RetryStrategy::from($retryStrategy);

        // Convert string to enum for overflow
        $this->overflowEnum = $overflow instanceof OverflowBehavior
            ? $overflow
            : OverflowBehavior::from($overflow);
    }

    /**
     * Get the DLQ queue name for this queue.
     * Convention: 'email:outbound:transactional' -> 'dlq:email:outbound:transactional'
     */
    public function getDlqQueueName(): string
    {
        return 'dlq:'.$this->queue;
    }

    /**
     * Get the DLQ exchange name.
     * If not explicitly set, derives from the first binding's exchange domain.
     * Convention: 'emails.outbound' -> 'emails.dlq'
     */
    public function getDlqExchangeName(): ?string
    {
        if ($this->dlqExchange !== null) {
            return $this->dlqExchange;
        }

        // Derive from first binding
        if (empty($this->bindings)) {
            return null;
        }

        $firstExchange = array_key_first($this->bindings);
        $parts = explode('.', $firstExchange);

        return $parts[0].'.dlq';
    }

    /**
     * Get the DLQ routing key for this queue.
     * Uses the queue name with colons replaced by dots.
     */
    public function getDlqRoutingKey(): string
    {
        return str_replace(':', '.', $this->queue);
    }

    /**
     * Get the default exchange to publish to when dispatching this job.
     * Returns the first exchange from bindings.
     */
    public function getPublishExchange(): ?string
    {
        if (empty($this->bindings)) {
            return null;
        }

        return array_key_first($this->bindings);
    }

    /**
     * Get the default routing key to use when publishing.
     *
     * Uses the first binding's routing key if available (explicit and predictable),
     * otherwise falls back to deriving from queue name for jobs without bindings.
     */
    public function getPublishRoutingKey(): string
    {
        // Use first binding's routing key if available (explicit, predictable)
        if (! empty($this->bindings)) {
            $firstRoutingKey = reset($this->bindings);

            return is_array($firstRoutingKey) ? $firstRoutingKey[0] : $firstRoutingKey;
        }

        // Fallback: derive from queue name (for jobs without bindings)
        return str_replace(':', '.', $this->queue);
    }

    /**
     * Calculate the delay for a specific retry attempt.
     *
     * Uses the configured retry strategy:
     * - Exponential: Uses retryDelays array progressively [60, 300, 900] -> 60, 300, 900, 900...
     * - Fixed: Always uses the first delay value
     * - Linear: Multiplies first delay by attempt number (60, 120, 180...)
     */
    public function getRetryDelay(int $attempt): int
    {
        if (empty($this->retryDelays)) {
            return 60; // Default 1 minute
        }

        return match ($this->retryStrategyEnum) {
            RetryStrategy::Fixed => $this->retryDelays[0],
            RetryStrategy::Linear => ($this->retryDelays[0] ?? 60) * $attempt,
            RetryStrategy::Exponential => $this->retryDelays[min($attempt - 1, count($this->retryDelays) - 1)],
        };
    }

    /**
     * Get queue arguments for RabbitMQ declaration.
     *
     * @return array<string, mixed>
     */
    public function getQueueArguments(): array
    {
        $arguments = [];

        if ($this->quorum) {
            $arguments['x-queue-type'] = 'quorum';
        }

        if ($this->maxPriority !== null) {
            $arguments['x-max-priority'] = $this->maxPriority;
        }

        if ($this->messageTtl !== null) {
            $arguments['x-message-ttl'] = $this->messageTtl;
        }

        if ($this->maxLength !== null) {
            $arguments['x-max-length'] = $this->maxLength;
            $arguments['x-overflow'] = $this->overflowEnum->value;
        }

        // Dead letter configuration
        $dlqExchange = $this->getDlqExchangeName();
        if ($dlqExchange !== null) {
            $arguments['x-dead-letter-exchange'] = $dlqExchange;
            $arguments['x-dead-letter-routing-key'] = $this->getDlqRoutingKey();
        }

        return $arguments;
    }
}
