<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Exception thrown when a DLQ operation fails.
 */
class DlqOperationException extends RuntimeException
{
    /**
     * @param  array<string>  $availableQueues
     */
    public function __construct(
        string $message = 'DLQ operation failed',
        public readonly ?string $queueName = null,
        public readonly array $availableQueues = [],
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create an exception for when a queue is not found in topology.
     *
     * @param  array<string>  $availableQueues
     */
    public static function queueNotFound(string $queueName, array $availableQueues = []): self
    {
        return new self(
            message: "Queue '{$queueName}' not found in topology",
            queueName: $queueName,
            availableQueues: $availableQueues,
        );
    }

    /**
     * Create an exception for when a message is not found in DLQ.
     */
    public static function messageNotFound(string $messageId, string $dlqQueueName): self
    {
        return new self(
            message: "Message with ID '{$messageId}' not found in DLQ '{$dlqQueueName}'",
        );
    }

    /**
     * Create an exception for connection/channel failures.
     */
    public static function connectionFailed(string $message, ?Throwable $previous = null): self
    {
        return new self(
            message: $message,
            previous: $previous,
        );
    }
}
