<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Exception thrown when message publishing fails.
 */
class PublishException extends RuntimeException
{
    public function __construct(
        string $message = 'Failed to publish message to RabbitMQ',
        public readonly ?string $exchange = null,
        public readonly ?string $routingKey = null,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
