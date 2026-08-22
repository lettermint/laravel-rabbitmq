<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Exceptions;

use RuntimeException;

final class UnknownBindingException extends RuntimeException
{
    public function __construct(
        public readonly string $queue,
        public readonly string $routingKey,
    ) {
        parent::__construct(
            "RabbitMQ routing key [{$routingKey}] does not match a registered binding for queue [{$queue}]."
        );
    }
}
