<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Contracts\HasPriority;
use Lettermint\RabbitMQ\Events\MessagePublished;
use Lettermint\RabbitMQ\Events\MessagePublishFailed;
use Lettermint\RabbitMQ\Exceptions\ConnectionException;
use Lettermint\RabbitMQ\Exceptions\PublishException;
use Lettermint\RabbitMQ\Exceptions\UnknownBindingException;
use Lettermint\RabbitMQ\Exceptions\UnknownQueueException;
use Lettermint\RabbitMQ\Queue\RabbitMQJob;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;
use Lettermint\RabbitMQ\Tests\Fixtures\Jobs\PriorityJob;
use Lettermint\RabbitMQ\Tests\Fixtures\Jobs\RoutedJob;
use Lettermint\RabbitMQ\Tests\Fixtures\Jobs\SimpleJob;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/** @param array<string, mixed> $overrides */
function queueTestConfig(array $overrides = []): array
{
    return array_replace_recursive([
        'queue' => ['default' => 'default'],
        'connection' => 'broker',
        'physical_prefix' => 'test.',
        'strict_topology' => true,
        'dead_letter' => ['enabled' => false],
        'publisher' => [
            'confirm' => true,
            'mandatory' => true,
            'confirm_timeout' => 2.0,
        ],
        'retry' => [
            'maximum_delay' => 3600,
            'delay_queue_cleanup_grace' => 60000,
        ],
        'topology' => [
            'exchanges' => [
                'jobs' => ['type' => 'topic'],
                'dlx' => ['type' => 'direct'],
            ],
            'queues' => [
                'default' => [
                    'bindings' => ['jobs' => ['default']],
                    'dead_letter' => false,
                ],
                'events:shard' => [
                    'bindings' => ['jobs' => ['events.shard.*']],
                    'dead_letter' => false,
                ],
                'test-queue' => [
                    'bindings' => ['jobs' => ['test-queue']],
                    'dead_letter' => false,
                ],
            ],
        ],
    ], $overrides);
}

beforeEach(function () {
    $this->channel = mockAMQPChannel();
    $this->channelManager = Mockery::mock(ChannelManager::class);
    $this->channelManager->shouldReceive('publishChannel')->with('broker')->andReturn($this->channel)->byDefault();
    $this->channelManager->shouldReceive('consumeChannel')->with('broker')->andReturn($this->channel)->byDefault();
    $this->channelManager->shouldReceive('topologyChannel')->with('broker')->andReturn($this->channel)->byDefault();
    $this->channelManager->shouldReceive('closeChannel')->andReturnNull()->byDefault();

    $this->config = queueTestConfig();
    $this->registry = testTopologyRegistry($this->config);
    $this->queue = testRabbitMQQueue($this->channelManager, $this->config, $this->registry);
    $this->queue->setContainer(new Container);
    $this->queue->setConnectionName('rabbitmq-native');
});

test('requires publisher confirmations and mandatory routing', function (array $publisher) {
    $config = queueTestConfig(['publisher' => $publisher]);

    expect(fn () => testRabbitMQQueue($this->channelManager, $config, testTopologyRegistry($config)))
        ->toThrow(InvalidArgumentException::class, 'requires publisher confirmations and mandatory routing');
})->with([
    'confirmations disabled' => [['confirm' => false, 'mandatory' => true]],
    'mandatory routing disabled' => [['confirm' => true, 'mandatory' => false]],
]);

test('uses logical names and a physical prefix', function () {
    expect($this->queue->getQueue(null))->toBe('default')
        ->and($this->queue->physicalQueue('default'))->toBe('test.default')
        ->and($this->queue->getBrokerConnectionName())->toBe('broker');
});

test('rejects an unknown queue in strict mode', function () {
    expect(fn () => $this->queue->getQueue('missing'))
        ->toThrow(UnknownQueueException::class, 'missing');
});

test('publishes with mandatory routing and waits for confirmation', function () {
    $this->channel->shouldReceive('basic_publish')
        ->once()
        ->withArgs(function (AMQPMessage $message, string $exchange, string $routingKey, bool $mandatory): bool {
            return $message->getBody() === '{"uuid":"job-1"}'
                && $message->get('message_id') === 'job-1'
                && $exchange === 'test.jobs'
                && $routingKey === 'default'
                && $mandatory;
        });
    $this->channel->shouldReceive('wait_for_pending_acks_returns')->once()->with(2.0);

    expect($this->queue->pushRaw('{"uuid":"job-1"}', 'default'))->toBe('job-1');
});

test('reuses the publisher channel for multiple confirmed publishes', function () {
    $this->channel->shouldReceive('basic_publish')->twice();
    $this->channel->shouldReceive('wait_for_pending_acks_returns')->twice();

    $this->queue->pushRaw('{"uuid":"job-1"}', 'default');
    $this->queue->pushRaw('{"uuid":"job-2"}', 'default');
});

test('fails a returned unroutable publish and emits a failure event', function () {
    $events = Mockery::mock(Dispatcher::class);
    $events->shouldReceive('dispatch')->once()->with(Mockery::type(MessagePublishFailed::class));
    $queue = new RabbitMQQueue($this->channelManager, $this->registry, $events, $this->config);

    $this->channel->shouldReceive('set_return_listener')
        ->once()
        ->withArgs(function (callable $listener): bool {
            $listener(312, 'NO_ROUTE', 'test.jobs', 'default');

            return true;
        });

    expect(fn () => $queue->pushRaw('{"uuid":"job-1"}', 'default'))
        ->toThrow(PublishException::class, 'unroutable');
});

test('fails a negative publisher confirmation', function () {
    $this->channel->shouldReceive('set_nack_handler')
        ->once()
        ->withArgs(function (callable $handler): bool {
            $handler(mockAMQPMessage());

            return true;
        });

    expect(fn () => $this->queue->pushRaw('{"uuid":"job-1"}', 'default'))
        ->toThrow(PublishException::class, 'negatively confirmed');
});

test('fails an uncertain confirmation timeout and closes the publish channel', function () {
    $this->channel->shouldReceive('wait_for_pending_acks_returns')
        ->once()
        ->andThrow(new AMQPTimeoutException('timeout'));
    $this->channelManager->shouldReceive('closeChannel')->once()->with('publish', 'broker');

    expect(fn () => $this->queue->pushRaw('{"uuid":"job-1"}', 'default'))
        ->toThrow(PublishException::class, 'result is uncertain');
});

test('emits a success event only after the publish is confirmed', function () {
    $events = Mockery::mock(Dispatcher::class);
    $events->shouldReceive('dispatch')->once()->with(Mockery::type(MessagePublished::class));
    $queue = new RabbitMQQueue($this->channelManager, $this->registry, $events, $this->config);

    $queue->pushRaw('{"uuid":"job-1"}', 'default');
});

test('does not turn a confirmed publish into a failure when an event listener fails', function () {
    $events = Mockery::mock(Dispatcher::class);
    $events->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('listener failed'));
    $queue = new RabbitMQQueue($this->channelManager, $this->registry, $events, $this->config);

    expect($queue->pushRaw('{"uuid":"job-1"}', 'default'))->toBe('job-1');
});

test('accepts only a registered dynamic routing key', function () {
    $payload = json_encode([
        'uuid' => 'job-1',
        'routingKey' => 'events.shard.9',
    ]);

    $this->channel->shouldReceive('basic_publish')
        ->once()
        ->withArgs(fn (AMQPMessage $message, string $exchange, string $routingKey): bool => $exchange === 'test.jobs'
            && $routingKey === 'events.shard.9');

    $this->queue->pushRaw($payload, 'events:shard');
});

test('rejects invalid or unbound dynamic routing keys', function (string $routingKey, string $exception) {
    $payload = json_encode(['uuid' => 'job-1', 'routingKey' => $routingKey]);

    expect(fn () => $this->queue->pushRaw($payload, 'events:shard'))
        ->toThrow($exception);
})->with([
    'empty' => ['', InvalidArgumentException::class],
    'wildcard' => ['events.#', InvalidArgumentException::class],
    'unknown binding' => ['other.route', UnknownBindingException::class],
]);

test('adds a HasRoutingKey value to the Laravel payload', function () {
    $reflection = new ReflectionClass($this->queue);
    $method = $reflection->getMethod('createPayloadArray');

    $payload = $method->invoke($this->queue, new RoutedJob('events.shard.9'), 'events:shard', '');

    expect($payload['routingKey'])->toBe('events.shard.9');
});

test('uses a durable classic TTL queue for a delayed publish', function () {
    $this->channel->shouldReceive('queue_declare')
        ->once()
        ->withArgs(function (string $queue, bool $passive, bool $durable, bool $exclusive, bool $autoDelete, bool $nowait, AMQPTable $arguments): bool {
            $values = $arguments->getNativeData();

            return str_starts_with($queue, 'test.delay:default:15000:')
                && ! $passive
                && $durable
                && ! $exclusive
                && ! $autoDelete
                && ! $nowait
                && $values['x-queue-type'] === 'classic'
                && $values['x-message-ttl'] === 15000
                && $values['x-expires'] === 75000
                && $values['x-dead-letter-exchange'] === 'test.jobs'
                && $values['x-dead-letter-routing-key'] === 'default';
        });
    $this->channel->shouldReceive('basic_publish')
        ->once()
        ->withArgs(fn (AMQPMessage $message, string $exchange, string $routingKey): bool => $exchange === ''
            && str_starts_with($routingKey, 'test.delay:default:15000:'));

    $this->queue->pushRaw('{"uuid":"job-1"}', 'default', ['delay' => 15]);
});

test('rejects a delay above the configured maximum', function () {
    expect(fn () => $this->queue->pushRaw('{"uuid":"job-1"}', 'default', ['delay' => 3601]))
        ->toThrow(InvalidArgumentException::class, 'exceeds the configured maximum');
});

test('reports a delayed publish setup failure as a publish failure', function () {
    $events = Mockery::mock(Dispatcher::class);
    $events->shouldReceive('dispatch')->once()->with(Mockery::type(MessagePublishFailed::class));
    $queue = new RabbitMQQueue($this->channelManager, $this->registry, $events, $this->config);
    $this->channel->shouldReceive('queue_declare')->once()->andThrow(new AMQPIOException('declare failed'));
    $this->channelManager->shouldReceive('closeChannel')->once()->with('topology', 'broker');

    expect(fn () => $queue->pushRaw('{"uuid":"job-1"}', 'default', ['delay' => 15]))
        ->toThrow(PublishException::class, 'delayed publish setup failed');
});

test('preserves supplied AMQP properties', function () {
    $this->channel->shouldReceive('basic_publish')
        ->once()
        ->withArgs(function (AMQPMessage $message): bool {
            $headers = $message->get('application_headers');

            return $message->get('message_id') === 'message-1'
                && $message->get('correlation_id') === 'correlation-1'
                && $message->get('timestamp') === 1234
                && $message->get('priority') === 7
                && $headers instanceof AMQPTable
                && $headers->getNativeData()['trace'] === 'keep';
        });

    $this->queue->pushRaw('{"uuid":"job-1"}', 'default', [
        'properties' => [
            'message_id' => 'message-1',
            'correlation_id' => 'correlation-1',
            'timestamp' => 1234,
            'priority' => 7,
            'application_headers' => new AMQPTable(['trace' => 'keep']),
        ],
    ]);
});

test('returns a Laravel job from the physical queue', function () {
    $message = mockAMQPMessage();
    $this->channel->shouldReceive('basic_get')->once()->with('test.test-queue', false)->andReturn($message);

    $job = $this->queue->pop('test-queue');

    expect($job)->toBeInstanceOf(RabbitMQJob::class)
        ->and($job?->getQueue())->toBe('test-queue');
});

test('reports ready jobs through the Laravel queue size contract', function () {
    $this->channel->shouldReceive('queue_declare')
        ->twice()
        ->with('test.default', true, false, false, false)
        ->andReturn(['test.default', 12, 3]);

    expect($this->queue->size('default'))->toBe(12)
        ->and($this->queue->pendingSize('default'))->toBe(12)
        ->and($this->queue->delayedSize('default'))->toBe(0)
        ->and($this->queue->reservedSize('default'))->toBe(0)
        ->and($this->queue->creationTimeOfOldestPendingJob('default'))->toBeNull();
});

test('does not report a missing broker queue as empty', function () {
    $this->channel->shouldReceive('queue_declare')->once()->andThrow(new AMQPIOException('queue missing'));

    expect(fn () => $this->queue->size('default'))
        ->toThrow(ConnectionException::class, 'Failed to read');
});

test('publishes an empty batch without broker work', function () {
    $this->channel->shouldNotReceive('basic_publish');

    expect($this->queue->pushBatch([]))->toBe([]);
});

test('acknowledges and rejects deliveries', function () {
    $message = mockAMQPMessage(['deliveryTag' => 42]);
    $this->channel->shouldReceive('basic_ack')->once()->with(42);
    $this->channel->shouldReceive('basic_reject')->once()->with(42, false);

    $this->queue->ack($message, $this->channel);
    $this->queue->reject($message, $this->channel);
});

test('reads priority from jobs that provide it', function () {
    expect(new PriorityJob(priority: 8))->toBeInstanceOf(HasPriority::class)
        ->and((new PriorityJob(priority: 8))->getPriority())->toBe(8)
        ->and(new SimpleJob)->not->toBeInstanceOf(HasPriority::class);
});
