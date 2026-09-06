<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Enums;

/**
 * RabbitMQ exchange types.
 *
 * Determines how messages are routed from exchanges to queues.
 */
enum ExchangeType: string
{
    /**
     * Topic exchange - routes based on routing key patterns.
     *
     * Supports wildcards: * (one word), # (zero or more words).
     * Use for: Multi-tenant routing, category-based routing.
     * Example: 'notifications.*' matches 'notifications.standard'.
     */
    case Topic = 'topic';

    /**
     * Direct exchange - routes based on exact routing key match.
     *
     * Use for: Simple point-to-point routing.
     * Example: 'email-queue' routes only to bindings with key 'email-queue'.
     */
    case Direct = 'direct';

    /**
     * Fanout exchange - broadcasts to all bound queues.
     *
     * Ignores routing keys entirely.
     * Use for: Broadcasting events, pub/sub patterns.
     */
    case Fanout = 'fanout';

    /**
     * Kept for source compatibility. The topology registry rejects this type.
     */
    case Headers = 'headers';

    /**
     * Kept for source compatibility. The topology registry rejects this type.
     * Delayed releases use classic TTL queues.
     */
    case DelayedMessage = 'x-delayed-message';
}
