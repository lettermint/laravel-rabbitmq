<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Lettermint\RabbitMQ\Attributes\ConsumesQueue;
use Lettermint\RabbitMQ\Contracts\HasRoutingKey;

/**
 * A job that publishes with a per-message routing key.
 */
#[ConsumesQueue(
    queue: 'events:shard',
    bindings: ['events' => 'events.shard.0'],
)]
class RoutedJob implements HasRoutingKey, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        private string $routingKey = 'events.shard.0'
    ) {}

    public function getRoutingKey(): string
    {
        return $this->routingKey;
    }

    public function handle(): void
    {
        // Process the routed job
    }
}
