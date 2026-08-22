<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Contracts;

/**
 * Interface for jobs that publish with a per-message routing key.
 *
 * By default a job publishes with the static routing key derived from its
 * `#[ConsumesQueue]` binding (or queue name). Implement this interface to
 * compute the routing key for each dispatched instance.
 *
 * The returned key is injected into the job payload at publish time, so it is
 * also used when the job is released or replayed.
 *
 * @example
 * ```php
 * #[Exchange(name: 'events', type: ExchangeType::Topic)]
 * #[ConsumesQueue(queue: 'events.shard.0', bindings: ['events' => 'events.shard.0'], singleActiveConsumer: true)]
 * class ProjectEvent implements ShouldQueue, HasRoutingKey
 * {
 *     public function __construct(private string $aggregateId) {}
 *
 *     public function getRoutingKey(): string
 *     {
 *         return 'events.shard.'.(crc32($this->aggregateId) % 16);
 *     }
 * }
 * ```
 */
interface HasRoutingKey
{
    /**
     * Get the routing key this job should publish with.
     *
     * Overrides the static routing key from the `#[ConsumesQueue]` binding.
     * The exchange is still resolved from the job's attribute. The key must be
     * non-empty, no more than 255 bytes, and must not contain topic wildcards.
     */
    public function getRoutingKey(): string;
}
