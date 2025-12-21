<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Enums;

/**
 * Retry strategy for failed messages.
 *
 * Determines how delay intervals are calculated between retry attempts.
 */
enum RetryStrategy: string
{
    /**
     * Exponential backoff - delays increase exponentially.
     *
     * Best for: SMTP delivery, external API calls, rate-limited services.
     * Example with delays [60, 300, 900]: attempt 1 = 60s, attempt 2 = 300s, etc.
     */
    case Exponential = 'exponential';

    /**
     * Fixed delay - same delay between all attempts.
     *
     * Best for: Transient errors, internal services, predictable retry timing.
     * Example with delays [60]: all retries wait 60s.
     */
    case Fixed = 'fixed';

    /**
     * Linear backoff - delays increase linearly.
     *
     * Best for: Gradual rate limiting, quota-based APIs.
     * Example with delays [60]: attempt 1 = 60s, attempt 2 = 120s, attempt 3 = 180s.
     */
    case Linear = 'linear';
}
