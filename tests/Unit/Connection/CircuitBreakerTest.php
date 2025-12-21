<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Lettermint\RabbitMQ\Connection\CircuitBreaker;
use Lettermint\RabbitMQ\Enums\CircuitBreakerState;

test('starts in closed state', function () {
    $breaker = new CircuitBreaker;

    expect($breaker->getState())->toBe(CircuitBreakerState::Closed);
    expect($breaker->isClosed())->toBeTrue();
    expect($breaker->isOpen())->toBeFalse();
});

test('starts with zero failures', function () {
    $breaker = new CircuitBreaker;

    expect($breaker->getFailures())->toBe(0);
});

test('is available when closed', function () {
    $breaker = new CircuitBreaker;

    expect($breaker->isAvailable())->toBeTrue();
});

test('has no open duration when closed', function () {
    $breaker = new CircuitBreaker;

    expect($breaker->getOpenDuration())->toBeNull();
});

test('increments failure count on failure', function () {
    $breaker = new CircuitBreaker;

    $breaker->recordFailure();
    expect($breaker->getFailures())->toBe(1);

    $breaker->recordFailure();
    expect($breaker->getFailures())->toBe(2);
});

test('remains closed below threshold', function () {
    $breaker = new CircuitBreaker(failureThreshold: 5);

    for ($i = 0; $i < 4; $i++) {
        $breaker->recordFailure();
    }

    expect($breaker->getState())->toBe(CircuitBreakerState::Closed);
    expect($breaker->isAvailable())->toBeTrue();
});

test('opens circuit after threshold exceeded', function () {
    $breaker = new CircuitBreaker(failureThreshold: 3);

    $breaker->recordFailure();
    $breaker->recordFailure();
    $breaker->recordFailure();

    expect($breaker->getState())->toBe(CircuitBreakerState::Open);
    expect($breaker->isOpen())->toBeTrue();
});

test('logs error when circuit opens', function () {
    Log::spy();

    $breaker = new CircuitBreaker(failureThreshold: 2);

    $breaker->recordFailure();
    $breaker->recordFailure();

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(function ($message, $context) {
            return str_contains($message, 'circuit breaker opened')
                && $context['failures'] === 2
                && $context['threshold'] === 2;
        });
});

test('blocks requests when open', function () {
    $breaker = new CircuitBreaker(failureThreshold: 1, recoveryTimeout: 60.0);

    $breaker->recordFailure();

    expect($breaker->isAvailable())->toBeFalse();
});

test('reports open duration', function () {
    $breaker = new CircuitBreaker(failureThreshold: 1);

    $breaker->recordFailure();

    expect($breaker->getOpenDuration())->toBeGreaterThanOrEqual(0);
    expect($breaker->getOpenDuration())->toBeLessThan(1.0);
});

test('transitions to half-open after recovery timeout', function () {
    $breaker = new CircuitBreaker(failureThreshold: 1, recoveryTimeout: 0.01);

    $breaker->recordFailure();
    expect($breaker->isOpen())->toBeTrue();

    // Wait for recovery timeout
    usleep(15000); // 15ms

    expect($breaker->isAvailable())->toBeTrue();
    expect($breaker->getState())->toBe(CircuitBreakerState::HalfOpen);
});

test('logs when entering half-open state', function () {
    Log::spy();

    $breaker = new CircuitBreaker(failureThreshold: 1, recoveryTimeout: 0.01);

    $breaker->recordFailure();
    usleep(15000);
    $breaker->isAvailable();

    Log::shouldHaveReceived('info')
        ->withArgs(function ($message) {
            return str_contains($message, 'half-open');
        });
});

test('allows one request in half-open state', function () {
    $breaker = new CircuitBreaker(failureThreshold: 1, recoveryTimeout: 0.01);

    $breaker->recordFailure();
    usleep(15000);

    expect($breaker->isAvailable())->toBeTrue();
});

test('resets failure count on success', function () {
    $breaker = new CircuitBreaker(failureThreshold: 5);

    $breaker->recordFailure();
    $breaker->recordFailure();
    expect($breaker->getFailures())->toBe(2);

    $breaker->recordSuccess();
    expect($breaker->getFailures())->toBe(0);
});

test('closes circuit on success after half-open', function () {
    $breaker = new CircuitBreaker(failureThreshold: 1, recoveryTimeout: 0.01);

    $breaker->recordFailure();
    expect($breaker->isOpen())->toBeTrue();

    usleep(15000);
    $breaker->isAvailable(); // Transition to half-open

    $breaker->recordSuccess();

    expect($breaker->getState())->toBe(CircuitBreakerState::Closed);
    expect($breaker->isClosed())->toBeTrue();
});

test('logs when closing after recovery', function () {
    Log::spy();

    $breaker = new CircuitBreaker(failureThreshold: 1, recoveryTimeout: 0.01);

    $breaker->recordFailure();
    usleep(15000);
    $breaker->isAvailable();
    $breaker->recordSuccess();

    Log::shouldHaveReceived('info')
        ->withArgs(function ($message) {
            return str_contains($message, 'closing after successful recovery');
        });
});

test('clears open duration on success', function () {
    $breaker = new CircuitBreaker(failureThreshold: 1, recoveryTimeout: 0.01);

    $breaker->recordFailure();
    expect($breaker->getOpenDuration())->not->toBeNull();

    usleep(15000);
    $breaker->isAvailable();
    $breaker->recordSuccess();

    expect($breaker->getOpenDuration())->toBeNull();
});

test('reopens circuit on failure during half-open', function () {
    $breaker = new CircuitBreaker(failureThreshold: 1, recoveryTimeout: 0.01);

    $breaker->recordFailure();
    usleep(15000);
    $breaker->isAvailable(); // Transition to half-open

    $breaker->recordFailure();

    expect($breaker->getState())->toBe(CircuitBreakerState::Open);
    expect($breaker->isOpen())->toBeTrue();
});

test('logs warning when reopening after failed recovery', function () {
    Log::spy();

    $breaker = new CircuitBreaker(failureThreshold: 1, recoveryTimeout: 0.01);

    $breaker->recordFailure();
    usleep(15000);
    $breaker->isAvailable();
    $breaker->recordFailure();

    Log::shouldHaveReceived('warning')
        ->withArgs(function ($message) {
            return str_contains($message, 'reopened after failed recovery');
        });
});

test('manual reset returns circuit to closed state', function () {
    $breaker = new CircuitBreaker(failureThreshold: 1);

    $breaker->recordFailure();
    expect($breaker->isOpen())->toBeTrue();

    $breaker->reset();

    expect($breaker->getState())->toBe(CircuitBreakerState::Closed);
    expect($breaker->getFailures())->toBe(0);
    expect($breaker->getOpenDuration())->toBeNull();
});

test('logs when manually reset', function () {
    Log::spy();

    $breaker = new CircuitBreaker(failureThreshold: 1);

    $breaker->recordFailure();
    $breaker->reset();

    Log::shouldHaveReceived('info')
        ->withArgs(function ($message) {
            return str_contains($message, 'manually reset');
        });
});

test('uses custom failure threshold', function () {
    $breaker = new CircuitBreaker(failureThreshold: 10);

    for ($i = 0; $i < 9; $i++) {
        $breaker->recordFailure();
    }
    expect($breaker->isClosed())->toBeTrue();

    $breaker->recordFailure();
    expect($breaker->isOpen())->toBeTrue();
});

test('uses custom recovery timeout', function () {
    $breaker = new CircuitBreaker(failureThreshold: 1, recoveryTimeout: 0.05);

    $breaker->recordFailure();

    // Before timeout
    usleep(10000); // 10ms
    expect($breaker->isAvailable())->toBeFalse();

    // After timeout
    usleep(50000); // 50ms more
    expect($breaker->isAvailable())->toBeTrue();
});
