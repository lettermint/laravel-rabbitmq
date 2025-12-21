<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Enums;

/**
 * RabbitMQ topology entity types.
 *
 * Used for error reporting and topology management operations.
 */
enum TopologyEntityType: string
{
    /**
     * Exchange entity.
     */
    case Exchange = 'exchange';

    /**
     * Queue entity.
     */
    case Queue = 'queue';

    /**
     * Binding between exchange and queue.
     */
    case Binding = 'binding';
}
