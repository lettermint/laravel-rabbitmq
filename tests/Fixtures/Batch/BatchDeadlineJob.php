<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Tests\Fixtures\Batch;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A job that relies on retryUntil() and unlimited tries instead of a fixed
 * attempt count.
 */
final class BatchDeadlineJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 0;

    /** @var list<int> */
    public array $backoff = [1];

    public function __construct(
        public string $marker,
        public string $id,
        public int $retryUntilTimestamp,
        public string $outcome = 'success',
    ) {}

    public function retryUntil(): DateTimeInterface
    {
        return new DateTimeImmutable("@{$this->retryUntilTimestamp}");
    }

    public function handle(): void
    {
        file_put_contents($this->marker, "normal-handle:{$this->id}\n", FILE_APPEND | LOCK_EX);
    }
}
