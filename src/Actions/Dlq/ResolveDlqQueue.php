<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Actions\Dlq;

use Lettermint\RabbitMQ\Actions\Dlq\Results\DlqQueueConfig;
use Lettermint\RabbitMQ\Exceptions\DlqOperationException;
use Lettermint\RabbitMQ\Topology\TopologyRegistry;

/**
 * Resolve a queue name to its DLQ configuration.
 */
final class ResolveDlqQueue
{
    public function __construct(
        private TopologyRegistry $registry,
    ) {}

    /**
     * Resolve a queue name to its DLQ configuration.
     *
     * @throws DlqOperationException When queue not found in topology
     */
    public function __invoke(string $queueName): DlqQueueConfig
    {
        $topology = $this->registry->queues();

        if (! isset($topology[$queueName])) {
            throw DlqOperationException::queueNotFound(
                $queueName,
                array_keys($topology),
            );
        }

        $definition = $topology[$queueName];

        if (! $definition->deadLetterEnabled) {
            throw new DlqOperationException(
                "Queue '{$queueName}' does not have a dead-letter queue.",
                queueName: $queueName,
                availableQueues: array_keys($topology),
            );
        }

        return new DlqQueueConfig(
            originalQueueName: $queueName,
            dlqQueueName: $definition->deadLetterQueue,
            definition: $definition,
        );
    }
}
