<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Enums;

/**
 * Circuit breaker state enumeration.
 *
 * Implements the circuit breaker pattern for connection resilience.
 */
enum CircuitBreakerState: string
{
    /**
     * Circuit is closed - normal operation, requests flow through.
     */
    case Closed = 'closed';

    /**
     * Circuit is open - failing fast, blocking requests.
     *
     * Triggered when failure threshold is exceeded.
     */
    case Open = 'open';

    /**
     * Circuit is half-open - testing if service recovered.
     *
     * Allows limited traffic after recovery timeout.
     */
    case HalfOpen = 'half-open';
}
