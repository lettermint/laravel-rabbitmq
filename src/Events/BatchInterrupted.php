<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Events;

final readonly class BatchInterrupted
{
    public function __construct(
        public string $batchId,
        public string $connection,
        public string $queue,
        public int $size,
        public int $payloadBytes,
        public int $settled,
        public int $unacknowledged,
        public string $reason,
        public float $collectionMilliseconds,
        public float $processingMilliseconds = 0,
        public float $settlementMilliseconds = 0,
        public ?string $exceptionClass = null,
    ) {}
}
