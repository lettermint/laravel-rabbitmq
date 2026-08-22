<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Exceptions;

use RuntimeException;

final class UnknownQueueException extends RuntimeException
{
    /**
     * @param  list<string>  $registeredQueues
     */
    public function __construct(
        public readonly string $queue,
        public readonly array $registeredQueues = [],
    ) {
        parent::__construct("RabbitMQ queue [{$queue}] is not registered in the configured topology.");
    }
}
