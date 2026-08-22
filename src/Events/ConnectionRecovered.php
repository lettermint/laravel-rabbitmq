<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Events;

final readonly class ConnectionRecovered
{
    public function __construct(
        public string $connection,
        public int $attempt,
        public float $durationMilliseconds,
    ) {}
}
