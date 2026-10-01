<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\QueueManager;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Consumers\Consumer;
use Lettermint\RabbitMQ\Consumers\RabbitMQWorker;
use Lettermint\RabbitMQ\Tests\Fixtures\Batch\BatchMarkerHandler;
use PhpAmqpLib\Exception\AMQPTimeoutException;

/** @param list<string> $expectedQueues */
function commandConsumer(array $expectedQueues): Consumer
{
    config()->set('rabbitmq.recovery.max_attempts', 0);

    $channel = mockAMQPChannel();
    $channel->shouldReceive('basic_consume')
        ->times(count($expectedQueues))
        ->withArgs(fn (string $queue): bool => in_array($queue, $expectedQueues, true))
        ->andReturn('tag');
    $channel->shouldReceive('wait')->andThrow(new AMQPTimeoutException('empty'));

    $channelManager = Mockery::mock(ChannelManager::class);
    $channelManager->shouldReceive('consumeChannel')->with('broker')->andReturn($channel);
    $channelManager->shouldReceive('closeChannel')->with('consume', 'broker')->andReturnNull();

    $queue = testRabbitMQQueue($channelManager, [
        'connection' => 'broker',
        'strict_topology' => false,
    ]);
    $queueManager = Mockery::mock(QueueManager::class);
    $queueManager->shouldReceive('connection')->with('rabbitmq')->andReturn($queue);
    $events = Mockery::mock(Dispatcher::class);
    $events->shouldReceive('dispatch')->andReturnNull()->byDefault();
    $events->shouldReceive('until')->andReturnNull()->byDefault();
    $exceptions = Mockery::mock(ExceptionHandler::class);
    $exceptions->shouldReceive('report')->andReturnNull()->byDefault();
    $worker = new RabbitMQWorker($queueManager, $events, $exceptions, fn (): bool => false, fn (): null => null);

    return new Consumer($channelManager, $queueManager, $worker);
}

it('forwards multiple queue arguments to the consumer', function () {
    app()->instance(Consumer::class, commandConsumer(['default', 'reporting']));

    $this->artisan('rabbitmq:consume', [
        'queue' => ['default', 'reporting'],
        '--connection' => 'rabbitmq',
        '--stop-when-empty' => true,
        '--max-memory' => 1024,
    ])->assertExitCode(0);
});

it('accepts a single queue argument', function () {
    app()->instance(Consumer::class, commandConsumer(['default']));

    $this->artisan('rabbitmq:consume', [
        'queue' => ['default'],
        '--connection' => 'rabbitmq',
        '--stop-when-empty' => true,
        '--max-memory' => 1024,
    ])->assertExitCode(0);
});

it('keeps batch consumption behind a separate command with explicit limits', function () {
    $consumer = commandConsumer(['default']);
    app()->instance(Consumer::class, $consumer);

    $this->artisan('rabbitmq:consume-batch', [
        'queue' => 'default',
        '--handler' => BatchMarkerHandler::class,
        '--max-count' => 25,
        '--max-bytes' => 4096,
        '--max-wait' => 0.25,
        '--stop-when-empty' => true,
        '--max-memory' => 1024,
    ])->assertExitCode(0);
});

it('requires a batch handler and all batch limits', function () {
    $this->artisan('rabbitmq:consume-batch', [
        'queue' => 'default',
    ])->assertExitCode(2);

    $this->artisan('rabbitmq:consume-batch', [
        'queue' => 'default',
        '--handler' => BatchMarkerHandler::class,
    ])->assertExitCode(1);
});
