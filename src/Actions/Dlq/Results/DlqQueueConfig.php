<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Actions\Dlq\Results;

use Lettermint\RabbitMQ\Topology\QueueDefinition;

/**
 * Configuration for a DLQ resolved from a queue name.
 */
final readonly class DlqQueueConfig
{
    public function __construct(
        public string $originalQueueName,
        public string $dlqQueueName,
        public QueueDefinition $definition,
    ) {}
}
