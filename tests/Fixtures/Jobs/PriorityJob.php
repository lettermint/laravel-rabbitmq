<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Lettermint\RabbitMQ\Attributes\ConsumesQueue;
use Lettermint\RabbitMQ\Contracts\HasPriority;

/**
 * A job with priority support (classic queue, not quorum).
 */
#[ConsumesQueue(
    queue: 'priority:jobs',
    bindings: ['background' => 'priority.*'],
    quorum: false,
    maxPriority: 10,
)]
class PriorityJob implements HasPriority, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        private int $priority = 5
    ) {}

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function handle(): void
    {
        // Process the priority job
    }
}
