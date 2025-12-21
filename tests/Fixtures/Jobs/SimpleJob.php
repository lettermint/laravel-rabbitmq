<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Lettermint\RabbitMQ\Attributes\ConsumesQueue;

/**
 * A simple job with basic ConsumesQueue configuration.
 */
#[ConsumesQueue(
    queue: 'emails:outbound',
    bindings: ['emails' => 'outbound.*'],
)]
class SimpleJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public string $message = 'test'
    ) {}

    public function handle(): void
    {
        // Process the job
    }
}
