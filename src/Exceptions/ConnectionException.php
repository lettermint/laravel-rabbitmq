<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Exception thrown when RabbitMQ connection fails.
 */
class ConnectionException extends RuntimeException
{
    public function __construct(
        string $message = 'RabbitMQ connection failed',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
