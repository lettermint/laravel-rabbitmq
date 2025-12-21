<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Enums;

/**
 * Queue overflow behavior when max-length is exceeded.
 *
 * Determines what happens to messages when a queue reaches its capacity.
 */
enum OverflowBehavior: string
{
    /**
     * Drop oldest messages from the head of the queue.
     *
     * Use when: Latest messages are more important than older ones.
     * Risk: Silent message loss.
     */
    case DropHead = 'drop-head';

    /**
     * Reject new messages at the publisher.
     *
     * Use when: Publisher can handle rejections and retry.
     * Benefit: No silent message loss.
     */
    case RejectPublish = 'reject-publish';

    /**
     * Reject new messages and route to dead-letter exchange.
     *
     * Use when: All messages must be preserved for debugging/replay.
     * Recommended for: Production systems, ESP, financial applications.
     */
    case RejectPublishDlx = 'reject-publish-dlx';
}
