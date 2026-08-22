<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\WorkerOptions;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Consumers\Consumer;
use Lettermint\RabbitMQ\Consumers\RabbitMQWorker;
use Lettermint\RabbitMQ\Exceptions\ConnectionException;
use Lettermint\RabbitMQ\Exceptions\PublishException;
use Mockery\MockInterface;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPTimeoutException;

/**
 * @return array{0: Consumer, 1: MockInterface, 2: MockInterface}
 */
function makeConsumerForTest(): array
{
    config()->set('rabbitmq.recovery.max_attempts', 0);

    $channel = mockAMQPChannel();
    $channel->shouldReceive('wait')->andThrow(new AMQPTimeoutException('empty'));

    $channelManager = Mockery::mock(ChannelManager::class);
    $channelManager->shouldReceive('consumeChannel')->with('broker')->andReturn($channel)->byDefault();
    $channelManager->shouldReceive('closeChannel')->with('consume', 'broker')->andReturnNull()->byDefault();

    $queue = testRabbitMQQueue($channelManager, [
        'connection' => 'broker',
        'strict_topology' => false,
    ]);

    $queueManager = Mockery::mock(QueueManager::class);
    $queueManager->shouldReceive('connection')->with('rabbitmq-native')->andReturn($queue)->byDefault();

    $events = Mockery::mock(Dispatcher::class);
    $events->shouldReceive('dispatch')->andReturnNull()->byDefault();
    $exceptions = Mockery::mock(ExceptionHandler::class);
    $exceptions->shouldReceive('report')->andReturnNull()->byDefault();

    $worker = new RabbitMQWorker(
        $queueManager,
        $events,
        $exceptions,
        fn (): bool => false,
        fn (): null => null,
    );

    return [
        new Consumer($channelManager, $queueManager, $worker),
        $channel,
        $channelManager,
    ];
}

test('rejects an empty queue list', function () {
    [$consumer] = makeConsumerForTest();

    expect(fn () => $consumer->setQueues([]))
        ->toThrow(InvalidArgumentException::class, 'At least one');
});

test('rejects blank and duplicate queue names', function (array $queues, string $message) {
    [$consumer] = makeConsumerForTest();

    expect(fn () => $consumer->setQueues($queues))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'blank' => [['queue-a', '  '], 'non-empty'],
    'duplicate' => [['queue-a', 'queue-a'], 'unique'],
]);

test('rejects invalid worker settings', function (string $method, int|float $value, string $message) {
    [$consumer] = makeConsumerForTest();

    expect(fn () => $consumer->{$method}($value))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'prefetch' => ['setPrefetch', 0, 'prefetch'],
    'job timeout' => ['setTimeout', 0, 'job timeout'],
    'wait timeout' => ['setWaitTimeout', 0.0, 'wait timeout'],
    'memory' => ['setMaxMemory', 0, 'memory limit'],
]);

test('registers one broker consumer for each logical queue', function () {
    [$consumer, $channel] = makeConsumerForTest();

    $channel->shouldReceive('basic_qos')->once()->with(0, 2, false);
    $channel->shouldReceive('basic_consume')
        ->once()
        ->withArgs(fn (string $queue): bool => $queue === 'queue-a')
        ->andReturn('tag-a');
    $channel->shouldReceive('basic_consume')
        ->once()
        ->withArgs(fn (string $queue): bool => $queue === 'queue-b')
        ->andReturn('tag-b');

    $consumer->setConnection('rabbitmq-native')
        ->setQueues(['queue-a', 'queue-b'])
        ->setPrefetch(2)
        ->setStopWhenEmpty(true)
        ->consume();
});

test('uses the configured physical queue names', function () {
    [$consumer, $channel] = makeConsumerForTest();

    $channelManager = Mockery::mock(ChannelManager::class);
    $channelManager->shouldReceive('consumeChannel')->with('broker')->andReturn($channel);
    $channelManager->shouldReceive('closeChannel')->andReturnNull();
    $queue = testRabbitMQQueue($channelManager, [
        'connection' => 'broker',
        'physical_prefix' => 'staging.',
        'strict_topology' => false,
    ]);
    $queueManager = Mockery::mock(QueueManager::class);
    $queueManager->shouldReceive('connection')->with('rabbitmq-native')->andReturn($queue);
    $worker = app(RabbitMQWorker::class);
    $consumer = new Consumer($channelManager, $queueManager, $worker);

    $channel->shouldReceive('basic_consume')
        ->once()
        ->withArgs(fn (string $queue): bool => $queue === 'staging.default')
        ->andReturn('tag');

    $consumer->setConnection('rabbitmq-native')->setQueue('default')->setStopWhenEmpty(true)->consume();
});

test('cancels all registered consumers during shutdown', function () {
    [$consumer, $channel] = makeConsumerForTest();

    $channel->shouldReceive('basic_consume')->andReturn('tag-a', 'tag-b');
    $channel->shouldReceive('basic_cancel')->once()->with('tag-a');
    $channel->shouldReceive('basic_cancel')->once()->with('tag-b');

    $consumer->setConnection('rabbitmq-native')
        ->setQueues(['queue-a', 'queue-b'])
        ->setStopWhenEmpty(true)
        ->consume();
});

test('closes the consume channel when QoS setup fails', function () {
    [$consumer, $channel, $channelManager] = makeConsumerForTest();
    $channel->shouldReceive('basic_qos')->once()->andThrow(new AMQPIOException('qos failed'));
    $channelManager->shouldReceive('closeChannel')->with('consume', 'broker')->atLeast()->once();

    expect(fn () => $consumer->setConnection('rabbitmq-native')->consume())
        ->toThrow(ConnectionException::class, 'qos failed');
});

test('recovers a connection and rebuilds the consume channel', function () {
    config()->set('rabbitmq.recovery.max_attempts', 1);

    $workingChannel = mockAMQPChannel();
    $workingChannel->shouldReceive('wait')->andThrow(new AMQPTimeoutException('empty'));
    $channelManager = Mockery::mock(ChannelManager::class);
    $channelManager->shouldReceive('consumeChannel')
        ->with('broker')
        ->once()
        ->andThrow(new AMQPIOException('connection lost'));
    $channelManager->shouldReceive('consumeChannel')
        ->with('broker')
        ->once()
        ->andReturn($workingChannel);
    $channelManager->shouldReceive('recoverConnection')->once()->with('broker', 1);
    $channelManager->shouldReceive('closeChannel')->andReturnNull()->byDefault();

    $queue = testRabbitMQQueue($channelManager, ['connection' => 'broker']);
    $queueManager = Mockery::mock(QueueManager::class);
    $queueManager->shouldReceive('connection')->with('rabbitmq-native')->andReturn($queue);
    $events = Mockery::mock(Dispatcher::class);
    $events->shouldReceive('dispatch')->andReturnNull()->byDefault();
    $exceptions = Mockery::mock(ExceptionHandler::class);
    $exceptions->shouldReceive('report')->andReturnNull()->byDefault();
    $worker = new RabbitMQWorker($queueManager, $events, $exceptions, fn (): bool => false, fn (): null => null);

    (new Consumer($channelManager, $queueManager, $worker))
        ->setConnection('rabbitmq-native')
        ->setStopWhenEmpty(true)
        ->consume();
});

test('rejects a Laravel connection that does not use this driver', function () {
    [$consumer] = makeConsumerForTest();
    $queueManager = Mockery::mock(QueueManager::class);
    $queueManager->shouldReceive('connection')->with('other')->andReturn(new stdClass);

    $reflection = new ReflectionClass($consumer);
    $property = $reflection->getProperty('queueManager');
    $property->setValue($consumer, $queueManager);

    expect(fn () => $consumer->setConnection('other')->consume())
        ->toThrow(InvalidArgumentException::class, 'does not use');
});

test('does not hide a failed replacement publish', function () {
    $queueManager = Mockery::mock(QueueManager::class);
    $events = Mockery::mock(Dispatcher::class);
    $events->shouldReceive('dispatch')->andReturnNull()->byDefault();
    $exceptions = Mockery::mock(ExceptionHandler::class);
    $exceptions->shouldNotReceive('report');
    $worker = new RabbitMQWorker(
        $queueManager,
        $events,
        $exceptions,
        fn (): bool => false,
        fn (): null => null,
    );
    $job = new class extends Job implements QueueJobContract
    {
        public function getJobId(): string
        {
            return 'job-1';
        }

        public function getRawBody(): string
        {
            return '{"uuid":"job-1","job":"Example@handle","data":{}}';
        }

        public function attempts(): int
        {
            return 1;
        }

        public function fire(): void
        {
            throw new RuntimeException('job failed');
        }

        public function release($delay = 0): void
        {
            throw new PublishException('replacement publish failed');
        }
    };

    expect(fn () => $worker->processMessage($job, 'rabbitmq-native', new WorkerOptions(maxTries: 0)))
        ->toThrow(PublishException::class, 'replacement publish failed');
});
