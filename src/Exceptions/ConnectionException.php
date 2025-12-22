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
        public readonly ?string $host = null,
        public readonly ?int $port = null,
        public readonly ?string $vhost = null,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create a connection exception with host context.
     */
    public static function withContext(
        string $message,
        ?string $host = null,
        ?int $port = null,
        ?string $vhost = null,
        ?Throwable $previous = null,
    ): self {
        return new self($message, $host, $port, $vhost, 0, $previous);
    }

    /**
     * Get a formatted connection string for logging.
     */
    public function getConnectionString(): ?string
    {
        if ($this->host === null) {
            return null;
        }

        $connection = $this->host;

        if ($this->port !== null) {
            $connection .= ":{$this->port}";
        }

        if ($this->vhost !== null) {
            $connection .= $this->vhost;
        }

        return $connection;
    }
}
