<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Attributes\ConsumesQueue;
use Lettermint\RabbitMQ\Enums\OverflowBehavior;
use Lettermint\RabbitMQ\Enums\RetryStrategy;

describe('ConsumesQueue attribute', function () {
    describe('construction', function () {
        it('creates attribute with minimal parameters', function () {
            $attr = new ConsumesQueue(queue: 'test-queue');

            expect($attr->queue)->toBe('test-queue');
            expect($attr->quorum)->toBeTrue();
            expect($attr->bindings)->toBeEmpty();
            expect($attr->prefetch)->toBe(10);
            expect($attr->timeout)->toBe(30);
            expect($attr->retryAttempts)->toBe(3);
        });

        it('creates attribute with all parameters', function () {
            $attr = new ConsumesQueue(
                queue: 'complex:queue',
                bindings: ['exchange' => 'routing.key'],
                quorum: true,
                messageTtl: 86400000,
                maxLength: 10000,
                overflow: OverflowBehavior::RejectPublishDlx,
                dlqExchange: 'custom.dlq',
                retryAttempts: 5,
                retryStrategy: RetryStrategy::Fixed,
                retryDelays: [60, 120],
                prefetch: 25,
                timeout: 120,
            );

            expect($attr->queue)->toBe('complex:queue');
            expect($attr->messageTtl)->toBe(86400000);
            expect($attr->maxLength)->toBe(10000);
            expect($attr->dlqExchange)->toBe('custom.dlq');
            expect($attr->retryAttempts)->toBe(5);
            expect($attr->prefetch)->toBe(25);
            expect($attr->timeout)->toBe(120);
        });
    });

    describe('validation', function () {
        it('throws on empty queue name', function () {
            new ConsumesQueue(queue: '');
        })->throws(InvalidArgumentException::class, 'Queue name cannot be empty');

        it('throws on whitespace-only queue name', function () {
            new ConsumesQueue(queue: '   ');
        })->throws(InvalidArgumentException::class, 'Queue name cannot be empty');

        it('throws when maxPriority is negative', function () {
            new ConsumesQueue(queue: 'test', quorum: false, maxPriority: -1);
        })->throws(InvalidArgumentException::class, 'maxPriority must be between 0 and 255');

        it('throws when maxPriority exceeds 255', function () {
            new ConsumesQueue(queue: 'test', quorum: false, maxPriority: 256);
        })->throws(InvalidArgumentException::class, 'maxPriority must be between 0 and 255');

        it('allows maxPriority at boundary values', function () {
            $attrMin = new ConsumesQueue(queue: 'test', quorum: false, maxPriority: 0);
            $attrMax = new ConsumesQueue(queue: 'test', quorum: false, maxPriority: 255);

            expect($attrMin->maxPriority)->toBe(0);
            expect($attrMax->maxPriority)->toBe(255);
        });

        it('throws when quorum queue has maxPriority set', function () {
            new ConsumesQueue(queue: 'test', quorum: true, maxPriority: 5);
        })->throws(InvalidArgumentException::class, 'Cannot use maxPriority with quorum queues');

        it('throws when prefetch is less than 1', function () {
            new ConsumesQueue(queue: 'test', prefetch: 0);
        })->throws(InvalidArgumentException::class, 'prefetch must be at least 1');

        it('throws when timeout is less than 1', function () {
            new ConsumesQueue(queue: 'test', timeout: 0);
        })->throws(InvalidArgumentException::class, 'timeout must be at least 1 second');

        it('throws when retryAttempts is negative', function () {
            new ConsumesQueue(queue: 'test', retryAttempts: -1);
        })->throws(InvalidArgumentException::class, 'retryAttempts cannot be negative');

        it('allows zero retryAttempts', function () {
            $attr = new ConsumesQueue(queue: 'test', retryAttempts: 0);
            expect($attr->retryAttempts)->toBe(0);
        });

        it('throws when messageTtl is negative', function () {
            new ConsumesQueue(queue: 'test', messageTtl: -1);
        })->throws(InvalidArgumentException::class, 'messageTtl cannot be negative');

        it('throws when maxLength is less than 1', function () {
            new ConsumesQueue(queue: 'test', maxLength: 0);
        })->throws(InvalidArgumentException::class, 'maxLength must be at least 1');
    });

    describe('DLQ derivation', function () {
        it('derives DLQ queue name from queue name', function () {
            $attr = new ConsumesQueue(queue: 'email:outbound:transactional');
            expect($attr->getDlqQueueName())->toBe('dlq:email:outbound:transactional');
        });

        it('derives DLQ exchange name from first binding', function () {
            $attr = new ConsumesQueue(
                queue: 'test',
                bindings: ['emails.outbound' => 'routing.key']
            );
            expect($attr->getDlqExchangeName())->toBe('emails.dlq');
        });

        it('derives DLQ exchange from multi-part exchange name', function () {
            $attr = new ConsumesQueue(
                queue: 'test',
                bindings: ['notifications.push.high' => 'routing.key']
            );
            expect($attr->getDlqExchangeName())->toBe('notifications.dlq');
        });

        it('returns explicit dlqExchange when set', function () {
            $attr = new ConsumesQueue(
                queue: 'test',
                bindings: ['emails.outbound' => 'routing.key'],
                dlqExchange: 'custom.dlq.exchange'
            );
            expect($attr->getDlqExchangeName())->toBe('custom.dlq.exchange');
        });

        it('returns null DLQ exchange when no bindings', function () {
            $attr = new ConsumesQueue(queue: 'test');
            expect($attr->getDlqExchangeName())->toBeNull();
        });

        it('derives DLQ routing key from queue name', function () {
            $attr = new ConsumesQueue(queue: 'email:outbound:transactional');
            expect($attr->getDlqRoutingKey())->toBe('email.outbound.transactional');
        });
    });

    describe('publishing helpers', function () {
        it('returns first exchange as publish exchange', function () {
            $attr = new ConsumesQueue(
                queue: 'test',
                bindings: ['first-exchange' => 'key1', 'second-exchange' => 'key2']
            );
            expect($attr->getPublishExchange())->toBe('first-exchange');
        });

        it('returns null publish exchange when no bindings', function () {
            $attr = new ConsumesQueue(queue: 'test');
            expect($attr->getPublishExchange())->toBeNull();
        });

        it('returns first routing key as publish routing key', function () {
            $attr = new ConsumesQueue(
                queue: 'test',
                bindings: ['exchange' => 'outbound.transactional']
            );
            expect($attr->getPublishRoutingKey())->toBe('outbound.transactional');
        });

        it('returns first routing key from array of keys', function () {
            $attr = new ConsumesQueue(
                queue: 'test',
                bindings: ['exchange' => ['key.one', 'key.two']]
            );
            expect($attr->getPublishRoutingKey())->toBe('key.one');
        });

        it('derives publish routing key from queue name when no bindings', function () {
            $attr = new ConsumesQueue(queue: 'email:outbound');
            expect($attr->getPublishRoutingKey())->toBe('email.outbound');
        });
    });

    describe('retry delay calculation', function () {
        it('calculates exponential delays correctly', function () {
            $attr = new ConsumesQueue(
                queue: 'test',
                retryStrategy: RetryStrategy::Exponential,
                retryDelays: [60, 300, 900]
            );

            expect($attr->getRetryDelay(1))->toBe(60);
            expect($attr->getRetryDelay(2))->toBe(300);
            expect($attr->getRetryDelay(3))->toBe(900);
            expect($attr->getRetryDelay(4))->toBe(900); // caps at last value
            expect($attr->getRetryDelay(100))->toBe(900);
        });

        it('calculates fixed delays correctly', function () {
            $attr = new ConsumesQueue(
                queue: 'test',
                retryStrategy: RetryStrategy::Fixed,
                retryDelays: [120]
            );

            expect($attr->getRetryDelay(1))->toBe(120);
            expect($attr->getRetryDelay(2))->toBe(120);
            expect($attr->getRetryDelay(5))->toBe(120);
        });

        it('calculates linear delays correctly', function () {
            $attr = new ConsumesQueue(
                queue: 'test',
                retryStrategy: RetryStrategy::Linear,
                retryDelays: [60]
            );

            expect($attr->getRetryDelay(1))->toBe(60);
            expect($attr->getRetryDelay(2))->toBe(120);
            expect($attr->getRetryDelay(3))->toBe(180);
            expect($attr->getRetryDelay(5))->toBe(300);
        });

        it('returns default delay when retryDelays is empty', function () {
            $attr = new ConsumesQueue(
                queue: 'test',
                retryDelays: []
            );

            expect($attr->getRetryDelay(1))->toBe(60);
        });
    });

    describe('queue arguments generation', function () {
        it('generates quorum queue type argument', function () {
            $attr = new ConsumesQueue(queue: 'test', quorum: true);
            $args = $attr->getQueueArguments();

            expect($args)->toHaveKey('x-queue-type');
            expect($args['x-queue-type'])->toBe('quorum');
        });

        it('does not include queue type for classic queue', function () {
            $attr = new ConsumesQueue(queue: 'test', quorum: false);
            $args = $attr->getQueueArguments();

            expect($args)->not->toHaveKey('x-queue-type');
        });

        it('includes maxPriority argument', function () {
            $attr = new ConsumesQueue(queue: 'test', quorum: false, maxPriority: 10);
            $args = $attr->getQueueArguments();

            expect($args)->toHaveKey('x-max-priority');
            expect($args['x-max-priority'])->toBe(10);
        });

        it('includes messageTtl argument', function () {
            $attr = new ConsumesQueue(queue: 'test', messageTtl: 86400000);
            $args = $attr->getQueueArguments();

            expect($args)->toHaveKey('x-message-ttl');
            expect($args['x-message-ttl'])->toBe(86400000);
        });

        it('includes maxLength and overflow arguments', function () {
            $attr = new ConsumesQueue(
                queue: 'test',
                maxLength: 10000,
                overflow: OverflowBehavior::RejectPublishDlx
            );
            $args = $attr->getQueueArguments();

            expect($args)->toHaveKey('x-max-length');
            expect($args['x-max-length'])->toBe(10000);
            expect($args)->toHaveKey('x-overflow');
            expect($args['x-overflow'])->toBe('reject-publish-dlx');
        });

        it('includes dead letter exchange arguments when bindings exist', function () {
            $attr = new ConsumesQueue(
                queue: 'email:outbound',
                bindings: ['emails' => 'outbound.*']
            );
            $args = $attr->getQueueArguments();

            expect($args)->toHaveKey('x-dead-letter-exchange');
            expect($args['x-dead-letter-exchange'])->toBe('emails.dlq');
            expect($args)->toHaveKey('x-dead-letter-routing-key');
            expect($args['x-dead-letter-routing-key'])->toBe('email.outbound');
        });

        it('does not include dead letter args when no bindings', function () {
            $attr = new ConsumesQueue(queue: 'test');
            $args = $attr->getQueueArguments();

            expect($args)->not->toHaveKey('x-dead-letter-exchange');
            expect($args)->not->toHaveKey('x-dead-letter-routing-key');
        });
    });

    describe('enum conversion', function () {
        it('converts string retry strategy to enum', function () {
            $attr = new ConsumesQueue(queue: 'test', retryStrategy: 'exponential');
            expect($attr->retryStrategyEnum)->toBe(RetryStrategy::Exponential);

            $attr2 = new ConsumesQueue(queue: 'test', retryStrategy: 'fixed');
            expect($attr2->retryStrategyEnum)->toBe(RetryStrategy::Fixed);

            $attr3 = new ConsumesQueue(queue: 'test', retryStrategy: 'linear');
            expect($attr3->retryStrategyEnum)->toBe(RetryStrategy::Linear);
        });

        it('accepts RetryStrategy enum directly', function () {
            $attr = new ConsumesQueue(queue: 'test', retryStrategy: RetryStrategy::Linear);
            expect($attr->retryStrategyEnum)->toBe(RetryStrategy::Linear);
        });

        it('converts string overflow to enum', function () {
            $attr = new ConsumesQueue(queue: 'test', overflow: 'drop-head');
            expect($attr->overflowEnum)->toBe(OverflowBehavior::DropHead);
        });

        it('accepts OverflowBehavior enum directly', function () {
            $attr = new ConsumesQueue(queue: 'test', overflow: OverflowBehavior::RejectPublish);
            expect($attr->overflowEnum)->toBe(OverflowBehavior::RejectPublish);
        });
    });
});
