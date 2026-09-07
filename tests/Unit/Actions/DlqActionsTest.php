<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Lettermint\RabbitMQ\Actions\Dlq\FindDlqMessage;
use Lettermint\RabbitMQ\Actions\Dlq\InspectDlqMessages;
use Lettermint\RabbitMQ\Actions\Dlq\PurgeDlqMessages;
use Lettermint\RabbitMQ\Actions\Dlq\ReplayDlqMessages;
use Lettermint\RabbitMQ\Actions\Dlq\ResolveDlqQueue;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Events\DlqMessageReplayed;
use Lettermint\RabbitMQ\Monitoring\ManagementClient;
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
    $management = Mockery::mock(ManagementClient::class);
    $management->shouldReceive('assertSafeDeadLetterQueue')->andReturnNull();
    app()->instance(ManagementClient::class, $management);
    $channels->shouldReceive('closeChannel')->andReturnNull()->byDefault();
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

test('a standalone DLQ search protects the queue and closes its channel after a scan failure', function () {
    $management = Mockery::mock(ManagementClient::class);
    $management->shouldReceive('assertSafeDeadLetterQueue')->once()->with('test.dlq:default', 'broker');
    app()->instance(ManagementClient::class, $management);
    $channel = mockAMQPChannel();
    $calls = 0;
    $channel->shouldReceive('basic_get')->twice()->andReturnUsing(function () use (&$calls) {
        if (++$calls === 1) {
            return dlqActionMessage();
        }

        throw new AMQPTimeoutException('Scan interrupted');
    });
    $channels = Mockery::mock(ChannelManager::class);
    $channels->shouldReceive('channel')->once()->with('dlq-search', 'broker')->andReturn($channel);
    $channels->shouldReceive('closeChannel')->once()->with('dlq-search', 'broker');
    $queue = testRabbitMQQueue($channels, dlqActionConfig());

    expect(fn () => (new FindDlqMessage($channels, $queue))('test.dlq:default', 'missing'))
        ->toThrow(AMQPTimeoutException::class);
});

test('replay confirms the replacement before it acknowledges the DLQ message', function () {
    $dlq = mockAMQPChannel();
    $dlq->shouldReceive('queue_declare')->andReturn(['test.dlq:default', 1, 0])->byDefault();
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

            expect($exchange)->toBe('')
                ->and($routingKey)->toBe('test.default')
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
    $dlq->shouldReceive('queue_declare')->andReturn(['test.dlq:default', 1, 0])->byDefault();
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
    $dlq->shouldReceive('queue_declare')->andReturn(['test.dlq:default', 1, 0])->byDefault();
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
    $dlq->shouldReceive('queue_declare')->andReturn(['test.dlq:default', 1, 0])->byDefault();
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

test('an age-based purge uses a RabbitMQ x-death table before the publish timestamp', function () {
    $dlq = mockAMQPChannel();
    $dlq->shouldReceive('queue_declare')->andReturn(['test.dlq:default', 1, 0])->byDefault();
    $channels = Mockery::mock(ChannelManager::class);
    $channels->shouldReceive('channel')->once()->with('dlq-purge', 'broker')->andReturn($dlq);
    $events = Mockery::mock(Dispatcher::class);
    [, $purge] = makeDlqActions($channels, $events);
    $message = dlqActionMessage([
        'x-death' => [new AMQPTable(['time' => Carbon::now()->timestamp])],
    ]);
    $dlq->shouldReceive('basic_get')
        ->twice()
        ->with('test.dlq:default', false)
        ->andReturn($message, null);
    $dlq->shouldReceive('basic_reject')->once()->with(42, true);
    $dlq->shouldNotReceive('basic_ack');

    $result = $purge('default', olderThan: Carbon::now()->subDay());

    expect($result->purgedCount)->toBe(0)
        ->and($result->skippedCount)->toBe(1);
});

test('inspection keeps a malformed scalar JSON message visible', function () {
    $dlq = mockAMQPChannel();
    $dlq->shouldReceive('queue_declare')->andReturn(['test.dlq:default', 1, 0])->byDefault();
    $channels = Mockery::mock(ChannelManager::class);
    $channels->shouldReceive('channel')->once()->with('dlq-inspect', 'broker')->andReturn($dlq);
    $events = Mockery::mock(Dispatcher::class);
    [, , $inspect] = makeDlqActions($channels, $events);
    $message = new AMQPMessage('"invalid-job-shape"', ['message_id' => 'malformed-1']);
    $message->setDeliveryInfo(43, true, 'test.dlx', 'default');
    $dlq->shouldReceive('basic_get')
        ->twice()
        ->with('test.dlq:default', false)
        ->andReturn($message, null);
    $dlq->shouldReceive('basic_reject')->once()->with(43, true);

    $result = $inspect('default');

    expect($result->messages)->toHaveCount(1)
        ->and($result->messages[0]->id)->toBe('malformed-1')
        ->and($result->messages[0]->jobClass)->toBe('Unknown')
        ->and($result->messages[0]->payload)->toBe([]);
});

test('inspection uses the queue connection broker', function () {
    $dlq = mockAMQPChannel();
    $dlq->shouldReceive('queue_declare')->andReturn(['test.dlq:default', 1, 0])->byDefault();
    $channels = Mockery::mock(ChannelManager::class);
    $channels->shouldReceive('channel')->once()->with('dlq-inspect', 'broker')->andReturn($dlq);
    $events = Mockery::mock(Dispatcher::class);
    [, , $inspect] = makeDlqActions($channels, $events);
    $dlq->shouldReceive('basic_get')->once()->with('test.dlq:default', false)->andReturn(null);

    expect($inspect('default')->isEmpty())->toBeTrue();
});

test('expired retry deadlines stay in the DLQ during replay and dry run', function (bool $dryRun, ?string $id) {
    $dlq = mockAMQPChannel();
    $dlq->shouldReceive('queue_declare')->andReturn(['test.dlq:default', 1, 0]);
    $channels = Mockery::mock(ChannelManager::class);
    $channels->shouldReceive('channel')->with('dlq-replay', 'broker')->andReturn($dlq);
    $channels->shouldNotReceive('publishChannel');
    [$replay] = makeDlqActions($channels, Mockery::mock(Dispatcher::class));
    $message = dlqActionMessage();
    $payload = json_decode($message->getBody(), true);
    $payload['retryUntil'] = time() - 1;
    $message->setBody(json_encode($payload));
    $dlq->shouldReceive('basic_get')->andReturn($message, null);
    $dlq->shouldReceive('basic_reject')->once()->with(42, true);
    $dlq->shouldNotReceive('basic_ack');

    $result = $replay('default', messageId: $id, dryRun: $dryRun);
    expect($result->replayedCount)->toBe(0)->and($result->failedCount)->toBe(1)
        ->and($result->failures[0]['error'])->toContain('deadline has expired');
})->with([[false, 'job-1'], [true, 'job-1'], [false, null], [true, null]]);

test('a bounded ID search reports incomplete and releases the fetched deliveries', function () {
    config(['rabbitmq.dlq.max_scan_messages' => 1]);
    $dlq = mockAMQPChannel();
    $channels = Mockery::mock(ChannelManager::class);
    $channels->shouldReceive('channel')->with('dlq-inspect', 'broker')->andReturn($dlq);
    [, , $inspect] = makeDlqActions($channels, Mockery::mock(Dispatcher::class));
    $dlq->shouldReceive('basic_get')->once()->andReturn(dlqActionMessage());
    $dlq->shouldReceive('basic_reject')->once()->with(42, true);
    $channels->shouldReceive('closeChannel')->once()->with('dlq-inspect', 'broker');

    $result = $inspect('default', messageId: 'missing');
    expect($result->incomplete)->toBeTrue()->and($result->wasMessageNotFound())->toBeFalse();
});

test('a partial purge reports completed messages and an uncertain acknowledgement', function () {
    $dlq = mockAMQPChannel();
    $channels = Mockery::mock(ChannelManager::class);
    $channels->shouldReceive('channel')->with('dlq-purge', 'broker')->andReturn($dlq);
    [, $purge] = makeDlqActions($channels, Mockery::mock(Dispatcher::class));
    $first = dlqActionMessage();
    $second = dlqActionMessage();
    $second->setDeliveryInfo(43, true, 'test.dlx', 'default');
    $dlq->shouldReceive('basic_get')->times(3)->andReturn($first, $second, null);
    $dlq->shouldReceive('basic_ack')->once()->with(42);
    $dlq->shouldReceive('basic_ack')->once()->with(43)->andThrow(new RuntimeException('connection closed'));
    $channels->shouldReceive('closeChannel')->once()->with('dlq-purge', 'broker');

    $result = $purge('default');
    expect($result->purgedCount)->toBe(1)->and($result->purgedMessages)->toHaveCount(1)
        ->and($result->error)->toBe('connection closed')
        ->and($result->uncertain)->toBeTrue()->and($result->incomplete)->toBeTrue();
});

test('JSON replay reports an uncertain transfer as one result with a failure exit', function () {
    $dlq = mockAMQPChannel();
    $dlq->shouldReceive('queue_declare')->andReturn(['test.dlq:default', 1, 0])->byDefault();
    $dlq->shouldReceive('basic_get')->once()->andReturn(dlqActionMessage());
    $dlq->shouldReceive('basic_reject')->once()->with(42, true);
    $dlq->shouldNotReceive('basic_ack');
    $publisher = mockAMQPChannel();
    $publisher->shouldReceive('wait_for_pending_acks_returns')->once()->andThrow(new AMQPTimeoutException('uncertain'));
    $channels = Mockery::mock(ChannelManager::class);
    $channels->shouldReceive('channel')->once()->with('dlq-replay', 'broker')->andReturn($dlq);
    $channels->shouldReceive('publishChannel')->once()->with('broker')->andReturn($publisher);
    $events = Mockery::mock(Dispatcher::class);
    $events->shouldReceive('dispatch')->andReturnNull();
    [$replay] = makeDlqActions($channels, $events);
    app()->instance(ReplayDlqMessages::class, $replay);

    $code = Artisan::call('rabbitmq:replay-dlq', ['queue' => 'default', '--json' => true]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($code)->toBe(1)->and($result['uncertain'])->toBeTrue()
        ->and($result['failedCount'])->toBe(1)->and($result['replayedCount'])->toBe(0);
});

test('JSON inspection reports an incomplete search instead of message not found', function () {
    config(['rabbitmq.dlq.max_scan_messages' => 1]);
    $dlq = mockAMQPChannel();
    $dlq->shouldReceive('basic_get')->once()->andReturn(dlqActionMessage());
    $dlq->shouldReceive('basic_reject')->once()->with(42, true);
    $channels = Mockery::mock(ChannelManager::class);
    $channels->shouldReceive('channel')->once()->with('dlq-inspect', 'broker')->andReturn($dlq);
    [, , $inspect] = makeDlqActions($channels, Mockery::mock(Dispatcher::class));
    app()->instance(InspectDlqMessages::class, $inspect);

    $code = Artisan::call('rabbitmq:dlq-inspect', ['queue' => 'default', '--id' => 'missing', '--format' => 'json']);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($code)->toBe(1)->and($result['incomplete'])->toBeTrue()->and($result['not_found_id'])->toBeNull();
});

test('JSON purge requires explicit destructive intent before reading a message', function () {
    $code = Artisan::call('rabbitmq:dlq-purge', ['queue' => 'default', '--json' => true]);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($code)->toBe(1)->and($result['error'])->toBe('JSON purge requires --force or --dry-run.');
});
