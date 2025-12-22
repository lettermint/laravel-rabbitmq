<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Exceptions;

use Lettermint\RabbitMQ\Enums\TopologyEntityType;
use RuntimeException;
use Throwable;

/**
 * Exception thrown when topology declaration fails.
 */
class TopologyException extends RuntimeException
{
    /**
     * The resolved entity type enum (null if not provided or invalid string).
     */
    public readonly ?TopologyEntityType $entityTypeEnum;

    public function __construct(
        string $message = 'RabbitMQ topology operation failed',
        TopologyEntityType|string|null $entityType = null,
        public readonly ?string $entityName = null,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        // Convert to enum if possible
        if ($entityType instanceof TopologyEntityType) {
            $this->entityTypeEnum = $entityType;
        } elseif (is_string($entityType)) {
            $this->entityTypeEnum = TopologyEntityType::tryFrom($entityType);
        } else {
            $this->entityTypeEnum = null;
        }

        parent::__construct($message, $code, $previous);
    }

    /**
     * Get the entity type as a string for backwards compatibility.
     */
    public function getEntityType(): ?string
    {
        return $this->entityTypeEnum?->value;
    }
}
