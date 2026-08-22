<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Events;

final readonly class MessagePublished
{
    public function __construct(
        public string $queue,
        public string $physicalQueue,
        public string $exchange,
        public string $routingKey,
        public string $messageId,
        public float $durationMilliseconds,
    ) {}
}
