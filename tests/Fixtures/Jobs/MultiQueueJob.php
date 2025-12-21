<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Lettermint\RabbitMQ\Attributes\ConsumesQueue;

/**
 * A job that can be consumed from multiple queues.
 *
 * Uses repeatable attribute to define multiple queue bindings.
 */
#[ConsumesQueue(
    queue: 'notifications:transactional',
    bindings: ['notifications' => 'transactional.*'],
)]
#[ConsumesQueue(
    queue: 'notifications:broadcast',
    bindings: ['notifications' => 'broadcast.*'],
)]
class MultiQueueJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public string $type = 'transactional'
    ) {}

    public function handle(): void
    {
        // Process the notification
    }
}
