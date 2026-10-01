<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\WorkerOptions;
use Lettermint\RabbitMQ\Batch\BatchOptions;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Consumers\Consumer;
use Lettermint\RabbitMQ\Consumers\RabbitMQWorker;
use Lettermint\RabbitMQ\Events\BatchInterrupted;
use Lettermint\RabbitMQ\Events\BatchItemSettled;
use Lettermint\RabbitMQ\Exceptions\ConnectionException;
use Lettermint\RabbitMQ\Exceptions\PublishException;
use Lettermint\RabbitMQ\Tests\Fixtures\Batch\BatchMarkerHandler;
use Lettermint\RabbitMQ\Tests\Fixtures\Jobs\ProcessMarkerJob;
use Lettermint\RabbitMQ\Tests\Fixtures\Jobs\SimpleJob;
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
    $queueManager->shouldReceive('connection')->with('rabbitmq')->andReturn($queue)->byDefault();

    $events = Mockery::mock(Dispatcher::class);
    $events->shouldReceive('dispatch')->andReturnNull()->byDefault();
    $events->shouldReceive('until')->andReturnNull()->byDefault();
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
        // The suite shares one process; earlier tests can exceed the worker's default limit.
        (new Consumer($channelManager, $queueManager, $worker))->setMaxMemory(1024),
        $channel,
        $channelManager,
    ];
}

function batchMessage(string $value, int $tag): MockInterface
{
    $job = new SimpleJob($value);

    return mockAMQPMessage([
        'deliveryTag' => $tag,
        'messageId' => 'batch-'.$tag,
        'body' => json_encode([
            'uuid' => 'batch-'.$tag,
            'displayName' => SimpleJob::class,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'maxTries' => null,
            'maxExceptions' => null,
            'backoff' => null,
            'timeout' => null,
            'retryUntil' => null,
            'data' => [
                'commandName' => SimpleJob::class,
                'command' => serialize($job),
            ],
        ], JSON_THROW_ON_ERROR),
    ]);
}

/**
 * @param  list<MockInterface>  $messages
 * @return array{Consumer, MockInterface}
 */
function makeBatchConsumerForTest(
    array $messages,
    bool $delayAfterDelivery = false,
    ?Throwable $finalException = null,
    bool $expectRegistration = true,
): array {
    config()->set('rabbitmq.recovery.max_attempts', 0);
    $callback = null;
    $waits = 0;
    $channel = mockAMQPChannel();
    if ($expectRegistration) {
        $channel->shouldReceive('basic_consume')->once()->andReturnUsing(function (...$arguments) use (&$callback): string {
            $callback = $arguments[6];

            return 'batch-tag';
        });
    } else {
        $channel->shouldNotReceive('basic_consume');
    }
    $channel->shouldReceive('wait')->andReturnUsing(function () use (&$messages, &$callback, &$waits, $delayAfterDelivery, $finalException): void {
        $waits++;

        if ($messages !== []) {
            $message = array_shift($messages);
            $callback($message);

            return;
        }

        if ($delayAfterDelivery && $waits === 2) {
            usleep(60000);
        }

        throw $finalException ?? new AMQPTimeoutException('empty');
    });

    $channelManager = Mockery::mock(ChannelManager::class);
    $channelManager->shouldReceive('consumeChannel')->with('broker')->andReturn($channel);
    $channelManager->shouldReceive('closeChannel')->with('consume', 'broker')->andReturnNull();
    $queue = testRabbitMQQueue($channelManager, ['connection' => 'broker', 'strict_topology' => false]);
    $queueManager = Mockery::mock(QueueManager::class);
    $queueManager->shouldReceive('connection')->with('rabbitmq')->andReturn($queue);
    $events = Mockery::mock(Dispatcher::class);
    $events->shouldReceive('dispatch')->andReturnNull()->byDefault();
    $events->shouldReceive('until')->andReturnNull()->byDefault();
    $exceptions = Mockery::mock(ExceptionHandler::class);
    $exceptions->shouldReceive('report')->andReturnNull()->byDefault();
    $worker = new RabbitMQWorker($queueManager, $events, $exceptions, fn (): bool => false, fn (): null => null);

    return [(new Consumer($channelManager, $queueManager, $worker))->setMaxMemory(1024), $channel];
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

    $consumer->setConnection('rabbitmq')
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
    $queueManager->shouldReceive('connection')->with('rabbitmq')->andReturn($queue);
    $worker = app(RabbitMQWorker::class);
    $consumer = (new Consumer($channelManager, $queueManager, $worker))->setMaxMemory(1024);

    $channel->shouldReceive('basic_consume')
        ->once()
        ->withArgs(fn (string $queue): bool => $queue === 'staging.default')
        ->andReturn('tag');

    $consumer->setConnection('rabbitmq')->setQueue('default')->setStopWhenEmpty(true)->consume();
});

test('cancels all registered consumers during shutdown', function () {
    [$consumer, $channel] = makeConsumerForTest();

    $channel->shouldReceive('basic_consume')->andReturn('tag-a', 'tag-b');
    $channel->shouldReceive('basic_cancel')->once()->with('tag-a');
    $channel->shouldReceive('basic_cancel')->once()->with('tag-b');

    $consumer->setConnection('rabbitmq')
        ->setQueues(['queue-a', 'queue-b'])
        ->setStopWhenEmpty(true)
        ->consume();
});

test('closes the consume channel when QoS setup fails', function () {
    [$consumer, $channel, $channelManager] = makeConsumerForTest();
    $channel->shouldReceive('basic_qos')->once()->andThrow(new AMQPIOException('qos failed'));
    $channelManager->shouldReceive('closeChannel')->with('consume', 'broker')->atLeast()->once();

    expect(fn () => $consumer->setConnection('rabbitmq')->consume())
        ->toThrow(ConnectionException::class, 'qos failed');
});

test('recovers a connection and rebuilds the consume channel', function () {
    config()->set('rabbitmq.recovery.max_attempts', 3);

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
    $queueManager->shouldReceive('connection')->with('rabbitmq')->andReturn($queue);
    $events = Mockery::mock(Dispatcher::class);
    $events->shouldReceive('dispatch')->andReturnNull()->byDefault();
    $events->shouldReceive('until')->andReturnNull()->byDefault();
    $exceptions = Mockery::mock(ExceptionHandler::class);
    $exceptions->shouldReceive('report')->andReturnNull()->byDefault();
    $worker = new RabbitMQWorker($queueManager, $events, $exceptions, fn (): bool => false, fn (): null => null);

    (new Consumer($channelManager, $queueManager, $worker))
        ->setMaxMemory(1024)
        ->setConnection('rabbitmq')
        ->setStopWhenEmpty(true)
        ->consume();
});

test('stops before registration when the memory limit is reached', function () {
    [$consumer, $channel, $channelManager] = makeConsumerForTest();
    $channelManager->shouldNotReceive('consumeChannel');
    $channel->shouldNotReceive('basic_consume');

    $limit = (int) floor(memory_get_usage(true) / 1024 / 1024);

    $consumer->setConnection('rabbitmq')->setMaxMemory($limit)->consume();
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
    $events->shouldReceive('until')->andReturnNull()->byDefault();
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

    expect(fn () => $worker->processMessage($job, 'rabbitmq', new WorkerOptions(maxTries: 0)))
        ->toThrow(PublishException::class, 'replacement publish failed');
});

test('batch mode derives prefetch from count and max jobs', function () {
    BatchMarkerHandler::reset();
    [$consumer, $channel] = makeBatchConsumerForTest([batchMessage('one', 1), batchMessage('two', 2)]);
    $channel->shouldReceive('basic_qos')->once()->with(0, 2, false);

    $consumer->setConnection('rabbitmq')
        ->setQueue('default')
        ->setMaxJobs(2)
        ->consumeBatch(BatchMarkerHandler::class, new BatchOptions(10, 100000, 1));

    expect(BatchMarkerHandler::$calls)->toBe(1)
        ->and(BatchMarkerHandler::$sizes)->toBe([2]);
});

test('batch mode applies the memory limit before broker registration', function () {
    BatchMarkerHandler::reset();
    [$consumer] = makeBatchConsumerForTest([], expectRegistration: false);

    $consumer->setConnection('rabbitmq')
        ->setQueue('default')
        ->setMaxMemory(1)
        ->consumeBatch(BatchMarkerHandler::class, new BatchOptions(10, 100000, 1));

    expect(BatchMarkerHandler::$calls)->toBe(0);
});

test('batch mode flushes before the next item exceeds the byte limit', function () {
    BatchMarkerHandler::reset();
    $first = batchMessage('one', 1);
    $second = batchMessage('two', 2);
    $limit = strlen($first->getBody()) + 1;
    [$consumer] = makeBatchConsumerForTest([$first, $second]);

    $consumer->setConnection('rabbitmq')
        ->setQueue('default')
        ->setMaxJobs(2)
        ->consumeBatch(BatchMarkerHandler::class, new BatchOptions(10, $limit, 1));

    expect(BatchMarkerHandler::$sizes)->toBe([1, 1]);
});

test('batch mode flushes a low-traffic item after its elapsed-time limit', function () {
    BatchMarkerHandler::reset();
    [$consumer] = makeBatchConsumerForTest([batchMessage('one', 1)], true);

    $consumer->setConnection('rabbitmq')
        ->setQueue('default')
        ->setMaxJobs(1)
        ->setWaitTimeout(1)
        ->consumeBatch(BatchMarkerHandler::class, new BatchOptions(10, 100000, 0.05));

    expect(BatchMarkerHandler::$sizes)->toBe([1]);
});

test('batch mode rejects malformed and unsupported deliveries without blocking a valid item', function () {
    BatchMarkerHandler::reset();
    $unsupportedJob = new ProcessMarkerJob('/tmp/not-used', 'unsupported');
    $unsupported = mockAMQPMessage([
        'deliveryTag' => 2,
        'body' => json_encode([
            'uuid' => 'unsupported',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [
                'commandName' => ProcessMarkerJob::class,
                'command' => serialize($unsupportedJob),
            ],
        ], JSON_THROW_ON_ERROR),
    ]);
    $malformed = mockAMQPMessage(['deliveryTag' => 1, 'body' => '{']);
    [$consumer] = makeBatchConsumerForTest([$malformed, $unsupported, batchMessage('valid', 3)]);
    $reasons = [];
    Event::listen(BatchItemSettled::class, function (BatchItemSettled $event) use (&$reasons): void {
        $reasons[] = $event->reason;
    });

    $consumer->setConnection('rabbitmq')
        ->setQueue('default')
        ->setMaxJobs(3)
        ->consumeBatch(BatchMarkerHandler::class, new BatchOptions(10, 100000, 1));

    expect(BatchMarkerHandler::$sizes)->toBe([1])
        ->and($reasons)->toContain('malformed', 'unsupported', 'handler_success');
});

test('batch mode rejects an oversized delivery without calling the handler', function () {
    BatchMarkerHandler::reset();
    [$consumer] = makeBatchConsumerForTest([batchMessage(str_repeat('x', 100), 1)]);
    $reason = null;
    Event::listen(BatchItemSettled::class, function (BatchItemSettled $event) use (&$reason): void {
        $reason = $event->reason;
    });

    $consumer->setConnection('rabbitmq')
        ->setQueue('default')
        ->setMaxJobs(1)
        ->consumeBatch(BatchMarkerHandler::class, new BatchOptions(10, 10, 1));

    expect(BatchMarkerHandler::$calls)->toBe(0)
        ->and($reason)->toBe('oversized');
});

test('batch mode leaves a partial batch unacknowledged after connection loss', function () {
    BatchMarkerHandler::reset();
    [$consumer] = makeBatchConsumerForTest(
        [batchMessage('pending', 1)],
        finalException: new AMQPIOException('connection lost'),
    );
    $interrupted = null;
    Event::listen(BatchInterrupted::class, function (BatchInterrupted $event) use (&$interrupted): void {
        $interrupted = $event;
    });

    expect(fn () => $consumer->setConnection('rabbitmq')
        ->setQueue('default')
        ->consumeBatch(BatchMarkerHandler::class, new BatchOptions(10, 100000, 30)))
        ->toThrow(ConnectionException::class);

    expect(BatchMarkerHandler::$calls)->toBe(0)
        ->and($interrupted)->toBeInstanceOf(BatchInterrupted::class)
        ->and($interrupted->settled)->toBe(0)
        ->and($interrupted->unacknowledged)->toBe(1);
});

test('batch mode requires one queue and a non-shared handler', function () {
    [$consumer] = makeBatchConsumerForTest([], expectRegistration: false);
    expect(fn () => $consumer->setQueues(['one', 'two'])->consumeBatch(
        BatchMarkerHandler::class,
        new BatchOptions(1, 1, 1),
    ))->toThrow(InvalidArgumentException::class, 'exactly one queue');

    app()->singleton(BatchMarkerHandler::class);
    [$consumer] = makeBatchConsumerForTest([], expectRegistration: false);
    expect(fn () => $consumer->setQueue('one')->consumeBatch(
        BatchMarkerHandler::class,
        new BatchOptions(1, 1, 1),
    ))->toThrow(InvalidArgumentException::class, 'cannot be a singleton');
});
