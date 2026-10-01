<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Tests\Fixtures\Batch;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

final class BatchStorageJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /** @var list<int> */
    public array $backoff = [1];

    public function __construct(
        public string $marker,
        public string $id,
        public string $outcome = 'success',
        public int $sleepSeconds = 0,
        public bool $disconnectBeforeReturn = false,
    ) {}

    public function handle(): void
    {
        file_put_contents($this->marker, "normal-handle:{$this->id}\n", FILE_APPEND | LOCK_EX);
    }
}
