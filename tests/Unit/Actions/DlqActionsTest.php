<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Lettermint\RabbitMQ\Actions\Dlq\FindDlqMessage;
use Lettermint\RabbitMQ\Actions\Dlq\InspectDlqMessages;
use Lettermint\RabbitMQ\Actions\Dlq\PurgeDlqMessages;
use Lettermint\RabbitMQ\Actions\Dlq\ReplayDlqMessages;
use Lettermint\RabbitMQ\Actions\Dlq\ResolveDlqQueue;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Events\DlqMessageReplayed;
use Lettermint\RabbitMQ\Queue\RabbitMQJob;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/** @return array<string, mixed> */
function dlqActionConfig(): array
{
    return [
        'queue' => ['default' => 'default'],
        'connection' => 'broker',
        'physical_prefix' => 'test.',
        'strict_topology' => true,
        'dead_letter' => [
            'enabled' => true,
            'exchange' => 'dlx',
            'queue_prefix' => 'dlq:',
        ],
        'publisher' => [
            'confirm' => true,
            'mandatory' => true,
            'confirm_timeout' => 2.0,
        ],
        'retry' => ['maximum_delay' => 3600],
        'topology' => [
            'exchanges' => [
                'jobs' => ['type' => 'direct'],
                'dlx' => ['type' => 'direct'],
            ],
            'queues' => [
                'default' => ['bindings' => ['jobs' => ['default']]],
            ],
        ],
    ];
}

/** @param array<string, mixed> $headers */
function dlqActionMessage(array $headers = [], bool $withTimestamp = true): AMQPMessage
{
    $properties = [
        'message_id' => 'job-1',
        'correlation_id' => 'correlation-1',
        'priority' => 7,
        'application_headers' => new AMQPTable($headers),
    ];

    if ($withTimestamp) {
        $properties['timestamp'] = 123456789;
    }

    $message = new AMQPMessage((string) json_encode([
        'uuid' => 'job-1',
        'displayName' => 'App\\Jobs\\ExampleJob',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => [],
    ], JSON_THROW_ON_ERROR), $properties);
    $message->setDeliveryInfo(42, true, 'test.dlx', 'default');

    return $message;
}

/** @return array{0: ReplayDlqMessages, 1: PurgeDlqMessages, 2: InspectDlqMessages} */
function makeDlqActions(ChannelManager $channels, Dispatcher $events): array
{
    $config = dlqActionConfig();
    $registry = testTopologyRegistry($config);
    $queue = testRabbitMQQueue($channels, $config, $registry);
    $resolve = new ResolveDlqQueue($registry);
    $find = new FindDlqMessage($channels, $queue);

    return [
        new ReplayDlqMessages($channels, $resolve, $find, $queue, $events),
        new PurgeDlqMessages($channels, $resolve, $find, $queue),
        new InspectDlqMessages($channels, $resolve, $find, $queue),
    ];
}

test('replay confirms the replacement before it acknowledges the DLQ message', function () {
    $dlq = mockAMQPChannel();
    $publisher = mockAMQPChannel();
    $channels = Mockery::mock(ChannelManager::class);
    $channels->shouldReceive('channel')->once()->with('dlq-replay', 'broker')->andReturn($dlq);
    $channels->shouldReceive('publishChannel')->once()->with('broker')->andReturn($publisher);
    $events = Mockery::mock(Dispatcher::class);
    $events->shouldReceive('dispatch')->once()->with(Mockery::type(DlqMessageReplayed::class));
    [$replay] = makeDlqActions($channels, $events);
    $message = dlqActionMessage([
        'custom' => 'keep',
        RabbitMQJob::ATTEMPT_HEADER => 5,
        'x-delivery-count' => 4,
        'x-death' => [['count' => 1]],
        'x-first-death-queue' => 'test.default',
    ]);
    $dlq->shouldReceive('basic_get')->once()->with('test.dlq:default', false)->andReturn($message);
    $confirmed = false;
    $publisher->shouldReceive('basic_publish')
        ->once()
        ->withArgs(function (AMQPMessage $published, string $exchange, string $routingKey, bool $mandatory): bool {
            $headers = $published->get('application_headers')->getNativeData();

            expect($exchange)->toBe('test.jobs')
                ->and($routingKey)->toBe('default')
                ->and($mandatory)->toBeTrue()
                ->and($published->get('message_id'))->toBe('job-1')
                ->and($published->get('correlation_id'))->toBe('correlation-1')
                ->and($published->get('timestamp'))->toBe(123456789)
                ->and($published->get('priority'))->toBe(7)
                ->and($headers['custom'])->toBe('keep')
                ->and($headers[RabbitMQJob::ATTEMPT_HEADER])->toBe(1)
                ->and($headers)->not->toHaveKeys([
                    'x-delivery-count',
                    'x-death',
                    'x-first-death-queue',
                ]);

            return true;
        });
    $publisher->shouldReceive('wait_for_pending_acks_returns')
        ->once()
        ->andReturnUsing(function () use (&$confirmed): void {
            $confirmed = true;
        });
    $dlq->shouldReceive('basic_ack')
        ->once()
        ->with(42)
        ->andReturnUsing(function () use (&$confirmed): void {
            expect($confirmed)->toBeTrue();
        });

    $result = $replay('default', messageId: 'job-1');

    expect($result->replayedCount)->toBe(1)
        ->and($result->failedCount)->toBe(0);
});

test('replay keeps the DLQ message when the replacement publish is uncertain', function () {
    $dlq = mockAMQPChannel();
    $publisher = mockAMQPChannel();
    $publisher->shouldReceive('wait_for_pending_acks_returns')
        ->once()
        ->andThrow(new AMQPTimeoutException('timeout'));
    $channels = Mockery::mock(ChannelManager::class);
    $channels->shouldReceive('channel')->once()->with('dlq-replay', 'broker')->andReturn($dlq);
    $channels->shouldReceive('publishChannel')->once()->with('broker')->andReturn($publisher);
    $channels->shouldReceive('closeChannel')->once()->with('publish', 'broker');
    $events = Mockery::mock(Dispatcher::class);
    $events->shouldReceive('dispatch')->andReturnNull()->byDefault();
    [$replay] = makeDlqActions($channels, $events);
    $message = dlqActionMessage();
    $dlq->shouldReceive('basic_get')->once()->with('test.dlq:default', false)->andReturn($message);
    $dlq->shouldReceive('basic_reject')->once()->with(42, true);
    $dlq->shouldNotReceive('basic_ack');

    $result = $replay('default');

    expect($result->replayedCount)->toBe(0)
        ->and($result->failedCount)->toBe(1);
});

test('an event listener failure does not change a completed replay result', function () {
    $dlq = mockAMQPChannel();
    $publisher = mockAMQPChannel();
    $channels = Mockery::mock(ChannelManager::class);
    $channels->shouldReceive('channel')->once()->with('dlq-replay', 'broker')->andReturn($dlq);
    $channels->shouldReceive('publishChannel')->once()->with('broker')->andReturn($publisher);
    $events = Mockery::mock(Dispatcher::class);
    $events->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('listener failed'));
    [$replay] = makeDlqActions($channels, $events);
    $message = dlqActionMessage();
    $dlq->shouldReceive('basic_get')->once()->andReturn($message);
    $dlq->shouldReceive('basic_ack')->once()->with(42);

    expect($replay('default', messageId: 'job-1')->replayedCount)->toBe(1);
});

test('an age-based purge keeps a message when its age is unknown', function () {
    $dlq = mockAMQPChannel();
    $channels = Mockery::mock(ChannelManager::class);
    $channels->shouldReceive('channel')->once()->with('dlq-purge', 'broker')->andReturn($dlq);
    $events = Mockery::mock(Dispatcher::class);
    [, $purge] = makeDlqActions($channels, $events);
    $message = dlqActionMessage(withTimestamp: false);
    $dlq->shouldReceive('basic_get')
        ->twice()
        ->with('test.dlq:default', false)
        ->andReturn($message, null);
    $dlq->shouldReceive('basic_reject')->once()->with(42, true);
    $dlq->shouldNotReceive('basic_ack');

    $result = $purge('default', olderThan: Carbon::now());

    expect($result->purgedCount)->toBe(0)
        ->and($result->skippedCount)->toBe(1);
});

test('inspection uses the queue connection broker', function () {
    $dlq = mockAMQPChannel();
    $channels = Mockery::mock(ChannelManager::class);
    $channels->shouldReceive('channel')->once()->with('dlq-inspect', 'broker')->andReturn($dlq);
    $events = Mockery::mock(Dispatcher::class);
    [, , $inspect] = makeDlqActions($channels, $events);
    $dlq->shouldReceive('basic_get')->once()->with('test.dlq:default', false)->andReturn(null);

    expect($inspect('default')->isEmpty())->toBeTrue();
});
