<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Events;

final readonly class QueueProbeProcessed
{
    public function __construct(
        public string $probeId,
        public string $queue,
        public int $dispatchedAt,
        public int $processedAt,
    ) {}
}
