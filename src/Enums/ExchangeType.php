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
     * Example: 'email.outbound.*' matches 'email.outbound.transactional'.
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
     * Headers exchange - routes based on message headers.
     *
     * Use for: Complex routing logic not expressible as routing keys.
     * Less common, higher overhead than topic/direct.
     */
    case Headers = 'headers';

    /**
     * Delayed message exchange (requires plugin).
     *
     * Holds messages for specified delay before routing.
     * Use for: Scheduled jobs, retry delays.
     * Requires: rabbitmq_delayed_message_exchange plugin.
     */
    case DelayedMessage = 'x-delayed-message';
}
