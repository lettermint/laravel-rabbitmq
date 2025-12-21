<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Enums\CircuitBreakerState;

describe('CircuitBreakerState enum', function () {
    it('has closed state', function () {
        expect(CircuitBreakerState::Closed->value)->toBe('closed');
    });

    it('has open state', function () {
        expect(CircuitBreakerState::Open->value)->toBe('open');
    });

    it('has half-open state', function () {
        expect(CircuitBreakerState::HalfOpen->value)->toBe('half-open');
    });

    it('can be created from string values', function () {
        expect(CircuitBreakerState::from('closed'))->toBe(CircuitBreakerState::Closed);
        expect(CircuitBreakerState::from('open'))->toBe(CircuitBreakerState::Open);
        expect(CircuitBreakerState::from('half-open'))->toBe(CircuitBreakerState::HalfOpen);
    });

    it('has exactly three cases', function () {
        expect(CircuitBreakerState::cases())->toHaveCount(3);
    });
});
