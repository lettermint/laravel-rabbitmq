<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;
use Lettermint\RabbitMQ\Tests\Fixtures\Payloads\PayloadFactory;
use Lettermint\RabbitMQ\Tests\Mocks\AMQPMocks;
use Lettermint\RabbitMQ\Tests\TestCase;
use Lettermint\RabbitMQ\Topology\TopologyRegistry;
use Mockery\MockInterface;

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
function mockAMQPConnection(bool $connected = true, int $heartbeat = 60): MockInterface
{
    return AMQPMocks::connection($connected, $heartbeat);
}

/**
 * Create a mock AMQPChannel.
 */
function mockAMQPChannel(?MockInterface $connection = null): MockInterface
{
    return AMQPMocks::channel($connection);
}

/**
 * Create a mock AMQPMessage.
 */
function mockAMQPMessage(array $options = []): MockInterface
{
    return AMQPMocks::message($options);
}

/**
 * Create a mock AMQPMessage with a specific job payload.
 */
function mockAMQPMessageWithJob(string $jobClass, array $jobData = [], array $options = []): MockInterface
{
    return AMQPMocks::messageWithJob($jobClass, $jobData, $options);
}

/**
 * Create a mock AMQPTable for headers.
 */
function mockAMQPTable(array $headers = []): MockInterface
{
    return AMQPMocks::headersTable($headers);
}

/**
 * Create a job payload JSON string.
 */
function createJobPayload(string $class, array $data = []): string
{
    return PayloadFactory::create($class, $data);
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

/**
 * Create a registry for tests. An empty topology permits fallback queues.
 *
 * @param  array<string, mixed>  $config
 */
function testTopologyRegistry(array $config = []): TopologyRegistry
{
    return new TopologyRegistry(new AttributeScanner, $config);
}

/**
 * Create a queue connection with the required publisher safety controls.
 *
 * @param  array<string, mixed>  $config
 */
function testRabbitMQQueue(ChannelManager $channelManager, array $config = [], ?TopologyRegistry $registry = null): RabbitMQQueue
{
    $config = array_replace_recursive([
        'queue' => ['default' => 'default'],
        'connection' => 'default',
        'publisher' => [
            'confirm' => true,
            'mandatory' => true,
            'confirm_timeout' => 5.0,
        ],
        'retry' => [
            'maximum_delay' => 86400,
            'delay_queue_cleanup_grace' => 86400000,
        ],
    ], $config);

    $events = Mockery::mock(Dispatcher::class);
    $events->shouldReceive('dispatch')->andReturnNull()->byDefault();

    return new RabbitMQQueue(
        $channelManager,
        $registry ?? testTopologyRegistry($config),
        $events,
        $config,
    );
}
