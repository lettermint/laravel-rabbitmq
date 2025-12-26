<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Lettermint\RabbitMQ\Attributes\ConsumesQueue;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Contracts\HasPriority;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;
use Lettermint\RabbitMQ\Tests\Fixtures\Jobs\PriorityJob;
use Lettermint\RabbitMQ\Tests\Fixtures\Jobs\SimpleJob;

beforeEach(function () {
    $this->mockChannel = mockAMQPChannel();

    $this->channelManager = Mockery::mock(ChannelManager::class);
    $this->channelManager->shouldReceive('publishChannel')
        ->andReturn($this->mockChannel)
        ->byDefault();
    $this->channelManager->shouldReceive('consumeChannel')
        ->andReturn($this->mockChannel)
        ->byDefault();
    $this->channelManager->shouldReceive('topologyChannel')
        ->andReturn($this->mockChannel)
        ->byDefault();

    $this->scanner = Mockery::mock(AttributeScanner::class);
    $this->scanner->shouldReceive('getQueueForJob')
        ->andReturn(null)
        ->byDefault();
    $this->scanner->shouldReceive('getQueues')
        ->andReturn(collect([]))
        ->byDefault();

    $this->config = [
        'queue' => ['default' => 'default'],
        'publisher_confirms' => false,
    ];
});

test('uses default queue from config', function () {
    $queue = new RabbitMQQueue(
        $this->channelManager,
        $this->scanner,
        ['queue' => ['default' => 'my-default']]
    );

    expect($queue->getQueue(null))->toBe('my-default');
});

test('falls back to "default" when not configured', function () {
    $queue = new RabbitMQQueue(
        $this->channelManager,
        $this->scanner,
        []
    );

    expect($queue->getQueue(null))->toBe('default');
});

test('returns provided queue name', function () {
    $queue = new RabbitMQQueue(
        $this->channelManager,
        $this->scanner,
        $this->config
    );

    expect($queue->getQueue('custom-queue'))->toBe('custom-queue');
});

test('returns default when null provided', function () {
    $queue = new RabbitMQQueue(
        $this->channelManager,
        $this->scanner,
        $this->config
    );

    expect($queue->getQueue(null))->toBe('default');
});

test('returns job when message available', function () {
    $message = mockAMQPMessage([
        'body' => json_encode([
            'uuid' => 'test-uuid',
            'displayName' => 'TestJob',
            'job' => 'TestJob@handle',
            'data' => [],
        ]),
    ]);

    // Set up channel to return message from basic_get
    $this->mockChannel->shouldReceive('basic_get')
        ->once()
        ->andReturn($message);

    $queue = new RabbitMQQueue(
        $this->channelManager,
        $this->scanner,
        $this->config
    );
    $queue->setContainer(new Container);
    $queue->setConnectionName('rabbitmq');

    $job = $queue->pop('test-queue');

    expect($job)->not->toBeNull();
    expect($job->getQueue())->toBe('test-queue');
});

test('extracts priority from HasPriority job', function () {
    $priorityJob = new PriorityJob(priority: 8);

    expect($priorityJob)->toBeInstanceOf(HasPriority::class);
    expect($priorityJob->getPriority())->toBe(8);
});

test('returns null priority for non-priority jobs', function () {
    $simpleJob = new SimpleJob;

    expect($simpleJob)->not->toBeInstanceOf(HasPriority::class);
});

test('uses attribute exchange when available', function () {
    $attribute = new ConsumesQueue(
        queue: 'emails:outbound',
        bindings: ['emails' => 'outbound.*']
    );

    $this->scanner->shouldReceive('getQueueForJob')
        ->with(SimpleJob::class, Mockery::any())
        ->andReturn($attribute);

    $queue = new RabbitMQQueue(
        $this->channelManager,
        $this->scanner,
        $this->config
    );

    $payload = json_encode([
        'uuid' => 'test-uuid',
        'displayName' => SimpleJob::class,
    ]);

    // Access the protected method via reflection for testing
    $reflection = new ReflectionClass($queue);
    $method = $reflection->getMethod('getExchangeAndRoutingKey');
    $method->setAccessible(true);

    [$exchange, $routingKey] = $method->invoke($queue, 'emails:outbound', $payload);

    expect($exchange)->toBe('emails');
    expect($routingKey)->toBe('outbound.*');
});

test('uses fallback routing when no attribute', function () {
    $this->scanner->shouldReceive('getQueueForJob')
        ->andReturn(null);
    $this->scanner->shouldReceive('getQueues')
        ->andReturn(collect([]));

    $queue = new RabbitMQQueue(
        $this->channelManager,
        $this->scanner,
        $this->config
    );

    $payload = json_encode([
        'uuid' => 'test-uuid',
        'displayName' => 'SomeJob',
    ]);

    $reflection = new ReflectionClass($queue);
    $method = $reflection->getMethod('getExchangeAndRoutingKey');
    $method->setAccessible(true);

    [$exchange, $routingKey] = $method->invoke($queue, 'some:queue', $payload);

    expect($exchange)->toBe('');
    expect($routingKey)->toBe('fallback.some.queue');
});

test('returns empty array for empty batch', function () {
    $queue = new RabbitMQQueue(
        $this->channelManager,
        $this->scanner,
        $this->config
    );

    $result = $queue->pushBatch([]);

    expect($result)->toBeEmpty();
});

test('acknowledges message', function () {
    $message = mockAMQPMessage(['deliveryTag' => 42]);
    $channel = mockAMQPChannel();
    $channel->shouldReceive('basic_ack')->with(42)->once();

    $queue = new RabbitMQQueue(
        $this->channelManager,
        $this->scanner,
        $this->config
    );

    $queue->ack($message, $channel);

    // Assertion is in mock expectation
    expect(true)->toBeTrue();
});

test('rejects message without requeue', function () {
    $message = mockAMQPMessage(['deliveryTag' => 42]);
    $channel = mockAMQPChannel();
    $channel->shouldReceive('basic_reject')->with(42, false)->once();

    $queue = new RabbitMQQueue(
        $this->channelManager,
        $this->scanner,
        $this->config
    );

    $queue->reject($message, $channel, false);

    expect(true)->toBeTrue();
});

test('rejects message with requeue', function () {
    $message = mockAMQPMessage(['deliveryTag' => 42]);
    $channel = mockAMQPChannel();
    $channel->shouldReceive('basic_reject')->with(42, true)->once();

    $queue = new RabbitMQQueue(
        $this->channelManager,
        $this->scanner,
        $this->config
    );

    $queue->reject($message, $channel, true);

    expect(true)->toBeTrue();
});

test('provides access to channel manager', function () {
    $queue = new RabbitMQQueue(
        $this->channelManager,
        $this->scanner,
        $this->config
    );

    expect($queue->getChannelManager())->toBe($this->channelManager);
});
