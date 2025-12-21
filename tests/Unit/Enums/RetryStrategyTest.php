<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Enums\RetryStrategy;

describe('RetryStrategy enum', function () {
    it('has exponential strategy', function () {
        expect(RetryStrategy::Exponential->value)->toBe('exponential');
    });

    it('has fixed strategy', function () {
        expect(RetryStrategy::Fixed->value)->toBe('fixed');
    });

    it('has linear strategy', function () {
        expect(RetryStrategy::Linear->value)->toBe('linear');
    });

    it('can be created from string values', function () {
        expect(RetryStrategy::from('exponential'))->toBe(RetryStrategy::Exponential);
        expect(RetryStrategy::from('fixed'))->toBe(RetryStrategy::Fixed);
        expect(RetryStrategy::from('linear'))->toBe(RetryStrategy::Linear);
    });

    it('has exactly three cases', function () {
        expect(RetryStrategy::cases())->toHaveCount(3);
    });
});
