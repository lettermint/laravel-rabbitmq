<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Actions\Dlq;

use Lettermint\RabbitMQ\Actions\Dlq\Results\DlqQueueConfig;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Exceptions\DlqOperationException;

/**
 * Resolve a queue name to its DLQ configuration.
 */
final class ResolveDlqQueue
{
    public function __construct(
        private AttributeScanner $scanner,
    ) {}

    /**
     * Resolve a queue name to its DLQ configuration.
     *
     * @throws DlqOperationException When queue not found in topology
     */
    public function __invoke(string $queueName): DlqQueueConfig
    {
        $topology = $this->scanner->getTopology();

        if (! isset($topology['queues'][$queueName])) {
            throw DlqOperationException::queueNotFound(
                $queueName,
                array_keys($topology['queues']),
            );
        }

        $attribute = $topology['queues'][$queueName]['attribute'];

        return new DlqQueueConfig(
            originalQueueName: $queueName,
            dlqQueueName: $attribute->getDlqQueueName(),
            attribute: $attribute,
        );
    }
}
