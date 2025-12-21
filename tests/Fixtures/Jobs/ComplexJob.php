<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Lettermint\RabbitMQ\Attributes\ConsumesQueue;
use Lettermint\RabbitMQ\Enums\OverflowBehavior;
use Lettermint\RabbitMQ\Enums\RetryStrategy;

/**
 * A job with all available ConsumesQueue options configured.
 */
#[ConsumesQueue(
    queue: 'complex:processing',
    bindings: ['complex' => ['type.a', 'type.b']],
    quorum: true,
    messageTtl: 86400000,
    maxLength: 10000,
    overflow: OverflowBehavior::RejectPublishDlx,
    dlqExchange: 'complex.dlq',
    retryAttempts: 5,
    retryStrategy: RetryStrategy::Exponential,
    retryDelays: [30, 60, 120, 300, 600],
    prefetch: 20,
    timeout: 120,
)]
class ComplexJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public array $payload = []
    ) {}

    public function handle(): void
    {
        // Process the complex job
    }
}
