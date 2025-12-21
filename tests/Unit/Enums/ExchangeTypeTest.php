<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Enums\ExchangeType;

describe('ExchangeType enum', function () {
    it('has topic type', function () {
        expect(ExchangeType::Topic->value)->toBe('topic');
    });

    it('has direct type', function () {
        expect(ExchangeType::Direct->value)->toBe('direct');
    });

    it('has fanout type', function () {
        expect(ExchangeType::Fanout->value)->toBe('fanout');
    });

    it('has headers type', function () {
        expect(ExchangeType::Headers->value)->toBe('headers');
    });

    it('has delayed message type', function () {
        expect(ExchangeType::DelayedMessage->value)->toBe('x-delayed-message');
    });

    it('can be created from string values', function () {
        expect(ExchangeType::from('topic'))->toBe(ExchangeType::Topic);
        expect(ExchangeType::from('direct'))->toBe(ExchangeType::Direct);
        expect(ExchangeType::from('fanout'))->toBe(ExchangeType::Fanout);
        expect(ExchangeType::from('headers'))->toBe(ExchangeType::Headers);
        expect(ExchangeType::from('x-delayed-message'))->toBe(ExchangeType::DelayedMessage);
    });

    it('has exactly five cases', function () {
        expect(ExchangeType::cases())->toHaveCount(5);
    });
});
