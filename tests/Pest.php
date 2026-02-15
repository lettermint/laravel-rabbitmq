<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

uses(TestCase::class)->in('Feature', 'Unit', 'Integration');

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
 * Create a mock AMQPStreamConnection.
 */
function mockAMQPConnection(bool $connected = true, int $heartbeat = 60): Mockery\MockInterface
{
    return Lettermint\RabbitMQ\Tests\Mocks\AMQPMocks::connection($connected, $heartbeat);
}

/**
 * Create a mock AMQPChannel.
 */
function mockAMQPChannel(?Mockery\MockInterface $connection = null): Mockery\MockInterface
{
    return Lettermint\RabbitMQ\Tests\Mocks\AMQPMocks::channel($connection);
}

/**
 * Create a mock AMQPMessage.
 */
function mockAMQPMessage(array $options = []): Mockery\MockInterface
{
    return Lettermint\RabbitMQ\Tests\Mocks\AMQPMocks::message($options);
}

/**
 * Create a mock AMQPMessage with a specific job payload.
 */
function mockAMQPMessageWithJob(string $jobClass, array $jobData = [], array $options = []): Mockery\MockInterface
{
    return Lettermint\RabbitMQ\Tests\Mocks\AMQPMocks::messageWithJob($jobClass, $jobData, $options);
}

/**
 * Create a mock AMQPTable for headers.
 */
function mockAMQPTable(array $headers = []): Mockery\MockInterface
{
    return Lettermint\RabbitMQ\Tests\Mocks\AMQPMocks::headersTable($headers);
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
