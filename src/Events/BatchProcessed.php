<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Events;

final readonly class BatchProcessed
{
    /** @param class-string $handler */
    public function __construct(
        public string $batchId,
        public string $connection,
        public string $queue,
        public string $handler,
        public int $size,
        public int $payloadBytes,
        public float $collectionMilliseconds,
        public float $processingMilliseconds,
        public float $settlementMilliseconds,
        public int $successes,
        public int $retries,
        public int $failures,
        public int $invalidResults,
        public ?string $handlerExceptionClass = null,
    ) {}
}
