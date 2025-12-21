<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Connection;

use Illuminate\Support\Facades\Log;
use Lettermint\RabbitMQ\Enums\CircuitBreakerState;

/**
 * Circuit breaker pattern implementation for connection resilience.
 *
 * Prevents cascading failures by tracking connection failures and temporarily
 * blocking requests when a threshold is exceeded. This is critical for ESP
 * operations where downstream failures shouldn't queue millions of retry attempts.
 *
 * @see https://martinfowler.com/bliki/CircuitBreaker.html
 */
class CircuitBreaker
{
    /**
     * Number of consecutive failures.
     */
    private int $failures = 0;

    /**
     * Timestamp when circuit was opened.
     */
    private ?float $openedAt = null;

    /**
     * Current circuit state.
     */
    private CircuitBreakerState $state = CircuitBreakerState::Closed;

    /**
     * Create a new circuit breaker instance.
     *
     * @param  int  $failureThreshold  Number of failures before opening circuit
     * @param  float  $recoveryTimeout  Seconds before attempting recovery (half-open state)
     */
    public function __construct(
        private readonly int $failureThreshold = 5,
        private readonly float $recoveryTimeout = 30.0,
    ) {}

    /**
     * Check if the circuit allows requests.
     *
     * Returns true if requests should be allowed through.
     * In half-open state, allows one request to test recovery.
     */
    public function isAvailable(): bool
    {
        if ($this->state === CircuitBreakerState::Closed) {
            return true;
        }

        if ($this->state === CircuitBreakerState::Open) {
            // Check if recovery timeout has elapsed
            if ($this->openedAt !== null && (microtime(true) - $this->openedAt) >= $this->recoveryTimeout) {
                $this->state = CircuitBreakerState::HalfOpen;

                Log::info('RabbitMQ circuit breaker entering half-open state', [
                    'failures' => $this->failures,
                    'recovery_timeout' => $this->recoveryTimeout,
                ]);

                return true;
            }

            return false;
        }

        // Half-open state: allow one request to test recovery
        return true;
    }

    /**
     * Record a successful operation.
     *
     * Resets failure count and closes the circuit.
     */
    public function recordSuccess(): void
    {
        if ($this->state === CircuitBreakerState::HalfOpen) {
            Log::info('RabbitMQ circuit breaker closing after successful recovery');
        }

        $this->failures = 0;
        $this->openedAt = null;
        $this->state = CircuitBreakerState::Closed;
    }

    /**
     * Record a failed operation.
     *
     * Increments failure count and opens circuit if threshold exceeded.
     */
    public function recordFailure(): void
    {
        $this->failures++;

        if ($this->state === CircuitBreakerState::HalfOpen) {
            // Failure during half-open, reopen circuit
            $this->openedAt = microtime(true);
            $this->state = CircuitBreakerState::Open;

            Log::warning('RabbitMQ circuit breaker reopened after failed recovery attempt', [
                'failures' => $this->failures,
            ]);

            return;
        }

        if ($this->state === CircuitBreakerState::Closed && $this->failures >= $this->failureThreshold) {
            // Threshold exceeded, open circuit
            $this->openedAt = microtime(true);
            $this->state = CircuitBreakerState::Open;

            Log::error('RabbitMQ circuit breaker opened due to failures', [
                'failures' => $this->failures,
                'threshold' => $this->failureThreshold,
            ]);
        }
    }

    /**
     * Get the current circuit state.
     */
    public function getState(): CircuitBreakerState
    {
        return $this->state;
    }

    /**
     * Reset the circuit breaker to closed state.
     *
     * Use for manual intervention or testing.
     */
    public function reset(): void
    {
        $this->failures = 0;
        $this->openedAt = null;
        $this->state = CircuitBreakerState::Closed;

        Log::info('RabbitMQ circuit breaker manually reset');
    }

    /**
     * Get the current failure count.
     */
    public function getFailures(): int
    {
        return $this->failures;
    }

    /**
     * Get time since circuit was opened (null if not open).
     */
    public function getOpenDuration(): ?float
    {
        if ($this->openedAt === null) {
            return null;
        }

        return microtime(true) - $this->openedAt;
    }

    /**
     * Check if the circuit is currently open (blocking requests).
     */
    public function isOpen(): bool
    {
        return $this->state === CircuitBreakerState::Open;
    }

    /**
     * Check if the circuit is closed (allowing requests).
     */
    public function isClosed(): bool
    {
        return $this->state === CircuitBreakerState::Closed;
    }
}
