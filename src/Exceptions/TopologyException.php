<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Exception thrown when topology declaration fails.
 */
class TopologyException extends RuntimeException
{
    public function __construct(
        string $message = 'RabbitMQ topology operation failed',
        public readonly ?string $entityType = null,
        public readonly ?string $entityName = null,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
