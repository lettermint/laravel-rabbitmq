<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Events;

use Throwable;

final readonly class MessagePublishFailed
{
    public function __construct(
        public string $queue,
        public string $physicalQueue,
        public string $exchange,
        public string $routingKey,
        public string $messageId,
        public Throwable $exception,
    ) {}
}
