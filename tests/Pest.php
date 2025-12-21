<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

uses(TestCase::class)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeValidQueueName', function () {
    return $this->toBeString()
        ->not->toBeEmpty()
        ->not->toContain(' ');
});

expect()->extend('toBeValidExchangeName', function () {
    return $this->toBeString()
        ->not->toBeEmpty()
        ->not->toContain(' ');
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Create a mock AMQPConnection.
 */
function mockAMQPConnection(bool $connected = true): Mockery\MockInterface
{
    return Lettermint\RabbitMQ\Tests\Mocks\AMQPMocks::connection($connected);
}

/**
 * Create a mock AMQPChannel.
 */
function mockAMQPChannel(): Mockery\MockInterface
{
    return Lettermint\RabbitMQ\Tests\Mocks\AMQPMocks::channel();
}

/**
 * Create a mock AMQPQueue.
 */
function mockAMQPQueue(string $name = 'test-queue'): Mockery\MockInterface
{
    return Lettermint\RabbitMQ\Tests\Mocks\AMQPMocks::queue($name);
}

/**
 * Create a mock AMQPExchange.
 */
function mockAMQPExchange(string $name = 'test-exchange'): Mockery\MockInterface
{
    return Lettermint\RabbitMQ\Tests\Mocks\AMQPMocks::exchange($name);
}

/**
 * Create a mock AMQPEnvelope.
 */
function mockAMQPEnvelope(array $options = []): Mockery\MockInterface
{
    return Lettermint\RabbitMQ\Tests\Mocks\AMQPMocks::envelope($options);
}

/**
 * Create a job payload JSON string.
 */
function createJobPayload(string $class, array $data = []): string
{
    return Lettermint\RabbitMQ\Tests\Fixtures\Payloads\PayloadFactory::create($class, $data);
}

/**
 * Create an x-death header array simulating DLQ redelivery.
 */
function createXDeathHeader(string $queue, int $count = 1, string $reason = 'rejected'): array
{
    return [
        [
            'queue' => $queue,
            'reason' => $reason,
            'count' => $count,
            'time' => time(),
            'exchange' => '',
            'routing-keys' => [$queue],
        ],
    ];
}
