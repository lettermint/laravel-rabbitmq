<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use RuntimeException;

final class ThrowingJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        throw new RuntimeException('Retry this test job.');
    }
}
