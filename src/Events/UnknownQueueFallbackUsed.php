<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Events;

final readonly class UnknownQueueFallbackUsed
{
    public function __construct(
        public string $queue,
        public string $physicalQueue,
    ) {}
}
