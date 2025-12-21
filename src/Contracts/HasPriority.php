<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Contracts;

/**
 * Interface for jobs that support message priority.
 *
 * Implement this interface on your job class to enable priority-based
 * message ordering in RabbitMQ. Higher priority messages are delivered first.
 *
 * @example
 * ```php
 * #[ConsumesQueue(queue: 'emails', maxPriority: 10)]
 * class ProcessEmail implements ShouldQueue, HasPriority
 * {
 *     public function __construct(
 *         private string $emailId,
 *         private int $priority = 5
 *     ) {}
 *
 *     public function getPriority(): int
 *     {
 *         return $this->priority;
 *     }
 * }
 *
 * // Dispatch with high priority
 * ProcessEmail::dispatch($emailId, priority: 10);
 * ```
 */
interface HasPriority
{
    /**
     * Get the priority level for this job.
     *
     * @return int Priority level (0 = lowest, maxPriority = highest)
     */
    public function getPriority(): int;
}
