<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A job without ConsumesQueue attribute.
 *
 * This job will use fallback routing via the default exchange
 * with routing key 'fallback.{queue_name}'.
 */
class NoAttributeJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public string $data = 'test'
    ) {}

    public function handle(): void
    {
        // Process the job
    }
}
