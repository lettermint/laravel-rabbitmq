<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Attributes\Exchange;
use Lettermint\RabbitMQ\Enums\ExchangeType;

describe('Exchange attribute', function () {
    describe('construction', function () {
        it('creates exchange with minimal parameters', function () {
            $attr = new Exchange(name: 'test-exchange');

            expect($attr->name)->toBe('test-exchange');
            expect($attr->typeEnum)->toBe(ExchangeType::Topic);
            expect($attr->durable)->toBeTrue();
            expect($attr->autoDelete)->toBeFalse();
            expect($attr->internal)->toBeFalse();
            expect($attr->bindTo)->toBeNull();
            expect($attr->bindRoutingKey)->toBe('#');
            expect($attr->arguments)->toBeEmpty();
        });

        it('creates exchange with all parameters', function () {
            $attr = new Exchange(
                name: 'custom-exchange',
                type: ExchangeType::Direct,
                durable: false,
                autoDelete: true,
                internal: true,
                bindTo: 'parent-exchange',
                bindRoutingKey: 'custom.#',
                arguments: ['x-custom' => 'value']
            );

            expect($attr->name)->toBe('custom-exchange');
            expect($attr->typeEnum)->toBe(ExchangeType::Direct);
            expect($attr->durable)->toBeFalse();
            expect($attr->autoDelete)->toBeTrue();
            expect($attr->internal)->toBeTrue();
            expect($attr->bindTo)->toBe('parent-exchange');
            expect($attr->bindRoutingKey)->toBe('custom.#');
            expect($attr->arguments)->toBe(['x-custom' => 'value']);
        });
    });

    describe('validation', function () {
        it('throws on empty exchange name', function () {
            new Exchange(name: '');
        })->throws(InvalidArgumentException::class, 'Exchange name cannot be empty');

        it('throws on whitespace-only exchange name', function () {
            new Exchange(name: '   ');
        })->throws(InvalidArgumentException::class, 'Exchange name cannot be empty');

        it('throws when bindRoutingKey set without bindTo', function () {
            new Exchange(name: 'test', bindRoutingKey: 'custom.key');
        })->throws(InvalidArgumentException::class, 'bindRoutingKey requires bindTo');

        it('allows default bindRoutingKey without bindTo', function () {
            $attr = new Exchange(name: 'test');
            expect($attr->bindRoutingKey)->toBe('#');
            expect($attr->bindTo)->toBeNull();
        });

        it('allows bindRoutingKey with bindTo', function () {
            $attr = new Exchange(name: 'test', bindTo: 'parent', bindRoutingKey: 'custom.#');
            expect($attr->bindRoutingKey)->toBe('custom.#');
            expect($attr->bindTo)->toBe('parent');
        });
    });

    describe('type conversion', function () {
        it('converts string type to enum', function () {
            $attr = new Exchange(name: 'test', type: 'topic');
            expect($attr->typeEnum)->toBe(ExchangeType::Topic);
        });

        it('accepts ExchangeType enum directly', function () {
            $attr = new Exchange(name: 'test', type: ExchangeType::Fanout);
            expect($attr->typeEnum)->toBe(ExchangeType::Fanout);
        });

        it('converts all string types correctly', function () {
            $types = [
                'topic' => ExchangeType::Topic,
                'direct' => ExchangeType::Direct,
                'fanout' => ExchangeType::Fanout,
                'headers' => ExchangeType::Headers,
                'x-delayed-message' => ExchangeType::DelayedMessage,
            ];

            foreach ($types as $string => $enum) {
                $attr = new Exchange(name: 'test', type: $string);
                expect($attr->typeEnum)->toBe($enum);
            }
        });
    });

    describe('type value', function () {
        it('returns correct type value for topic', function () {
            $attr = new Exchange(name: 'test', type: ExchangeType::Topic);
            expect($attr->getTypeValue())->toBe('topic');
        });

        it('returns correct type value for direct', function () {
            $attr = new Exchange(name: 'test', type: ExchangeType::Direct);
            expect($attr->getTypeValue())->toBe('direct');
        });

        it('returns correct type value for fanout', function () {
            $attr = new Exchange(name: 'test', type: ExchangeType::Fanout);
            expect($attr->getTypeValue())->toBe('fanout');
        });

        it('returns correct type value for headers', function () {
            $attr = new Exchange(name: 'test', type: ExchangeType::Headers);
            expect($attr->getTypeValue())->toBe('headers');
        });

        it('returns correct type value for delayed message', function () {
            $attr = new Exchange(name: 'test', type: ExchangeType::DelayedMessage);
            expect($attr->getTypeValue())->toBe('x-delayed-message');
        });
    });

    describe('DLQ exchange derivation', function () {
        it('derives DLQ exchange from simple name', function () {
            $attr = new Exchange(name: 'emails');
            expect($attr->getDlqExchangeName())->toBe('emails.dlq');
        });

        it('derives DLQ exchange from multi-part name', function () {
            $attr = new Exchange(name: 'emails.outbound');
            expect($attr->getDlqExchangeName())->toBe('emails.dlq');
        });

        it('derives DLQ exchange from deeply nested name', function () {
            $attr = new Exchange(name: 'notifications.push.high.priority');
            expect($attr->getDlqExchangeName())->toBe('notifications.dlq');
        });
    });
});
