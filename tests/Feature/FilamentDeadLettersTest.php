<?php

declare(strict_types=1);

use Filament\Notifications\NotificationMailbox;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Lettermint\RabbitMQ\Actions\Dlq\FindDlqMessage;
use Lettermint\RabbitMQ\Actions\Dlq\InspectDlqMessages;
use Lettermint\RabbitMQ\Actions\Dlq\PurgeDlqMessages;
use Lettermint\RabbitMQ\Actions\Dlq\ReplayDlqMessages;
use Lettermint\RabbitMQ\Actions\Dlq\ResolveDlqQueue;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Filament\Pages\RabbitMQDeadLetters;
use Lettermint\RabbitMQ\Filament\RabbitMQPlugin;
use Lettermint\RabbitMQ\Monitoring\ManagementClient;
use Lettermint\RabbitMQ\Monitoring\QueueMetrics;
use Lettermint\RabbitMQ\Topology\TopologyRegistry;
use PhpAmqpLib\Message\AMQPMessage;

test('the Filament plugin has a stable identifier', function () {
    expect(RabbitMQPlugin::make()->getId())->toBe('lettermint-rabbitmq');
});

test('the dead-letter page denies access without a valid application gate', function (string $ability) {
    config()->set('rabbitmq.filament.gate', $ability);
    Auth::setUser(new GenericUser(['id' => 1]));

    expect(RabbitMQDeadLetters::canAccess())->toBeFalse();
})->with([
    'undefined ability' => 'manageRabbitMQDeadLetters',
    'empty ability' => '',
]);

test('the dead-letter page permits a user authorized by the application gate', function () {
    config()->set('rabbitmq.filament.gate', 'manageRabbitMQDeadLetters');
    Auth::setUser(new GenericUser(['id' => 1]));
    Gate::define('manageRabbitMQDeadLetters', fn (GenericUser $user): bool => $user->getAuthIdentifier() === 1);

    expect(RabbitMQDeadLetters::canAccess())->toBeTrue();
});

test('the dead-letter page shows broker message counts and puts queues with failures first', function () {
    $config = [
        'physical_prefix' => 'test.',
        'queue' => ['default' => 'default'],
        'connection' => 'broker',
        'strict_topology' => true,
        'dead_letter' => [
            'enabled' => true,
            'exchange' => 'dlx',
            'queue_prefix' => 'dlq:',
        ],
        'publisher' => [
            'confirm' => true,
            'mandatory' => true,
        ],
        'topology' => [
            'exchanges' => [
                'jobs' => ['type' => 'direct'],
                'dlx' => ['type' => 'direct'],
            ],
            'queues' => [
                'default' => ['bindings' => ['jobs' => ['default']]],
                'busy' => ['bindings' => ['jobs' => ['busy']]],
                'missing' => ['bindings' => ['jobs' => ['missing']]],
            ],
        ],
    ];
    $registry = testTopologyRegistry($config);
    app()->instance(TopologyRegistry::class, $registry);

    $available = static fn (int $messages): array => [
        'messages' => $messages,
        'consumers' => 0,
        'rate' => null,
        'connected' => true,
        'notice' => null,
        'error' => null,
    ];
    $unavailable = [
        'messages' => null,
        'consumers' => null,
        'rate' => null,
        'connected' => false,
        'notice' => null,
        'error' => 'Queue not found',
    ];
    $metrics = Mockery::mock(QueueMetrics::class);
    $metrics->shouldReceive('getPhysicalQueueStats')->once()->with('test.dlq:default')->andReturn($available(0));
    $metrics->shouldReceive('getPhysicalQueueStats')->once()->with('test.dlq:busy')->andReturn($available(7));
    $metrics->shouldReceive('getPhysicalQueueStats')->once()->with('test.dlq:missing')->andReturn($unavailable);
    app()->instance(QueueMetrics::class, $metrics);

    $page = new RabbitMQDeadLetters;
    $page->mount();
    $method = new ReflectionMethod($page, 'queueRecords');
    $records = $method->invoke($page);

    expect($page->queue)->toBe('')
        ->and(array_column($records, 'queue'))->toBe(['busy', 'default', 'missing'])
        ->and($records[0]['messages'])->toBe(7)
        ->and($records[0]['status'])->toBe('Available')
        ->and($records[2]['messages'])->toBeNull()
        ->and($records[2]['status'])->toBe('Unavailable')
        ->and($records[2]['error'])->toBe('Queue not found');
});

test('the dead-letter page loads full details only when a message is inspected', function () {
    $config = [
        'queue' => ['default' => 'default'],
        'connection' => 'broker',
        'strict_topology' => true,
        'dead_letter' => [
            'enabled' => true,
            'exchange' => 'dlx',
            'queue_prefix' => 'dlq:',
        ],
        'publisher' => [
            'confirm' => true,
            'mandatory' => true,
        ],
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
    $management = Mockery::mock(ManagementClient::class);
    $management->shouldReceive('assertSafeDeadLetterQueue')->andReturnNull();
    app()->instance(ManagementClient::class, $management);
    Gate::define('viewRabbitMQDeadLetters', fn () => true);
    Auth::setUser(new GenericUser(['id' => 1]));
    $channel = mockAMQPChannel();
    $message = mockAMQPMessage([
        'body' => (string) json_encode([
            'uuid' => 'job-1',
            'displayName' => 'App\\Jobs\\ExampleJob',
            'exception' => ['message' => 'Payload exception'],
        ], JSON_THROW_ON_ERROR),
        'messageId' => 'job-1',
    ]);
    $channel->shouldReceive('basic_get')
        ->times(3)
        ->with('dlq:default', false)
        ->andReturn($message, null, $message);
    $channel->shouldReceive('basic_reject')->twice()->with($message->getDeliveryTag(), true);
    $channels = Mockery::mock(ChannelManager::class);
    $channels->shouldReceive('closeChannel')->andReturnNull()->byDefault();
    $channels->shouldReceive('channel')->twice()->with('dlq-inspect', 'broker')->andReturn($channel);
    $registry = testTopologyRegistry($config);
    $queue = testRabbitMQQueue($channels, $config, $registry);
    $inspect = new InspectDlqMessages(
        $channels,
        new ResolveDlqQueue($registry),
        new FindDlqMessage($channels, $queue),
        $queue,
    );
    app()->instance(InspectDlqMessages::class, $inspect);

    $failedProvider = Mockery::mock(FailedJobProviderInterface::class);
    $failedProvider->shouldReceive('find')->once()->with('job-1')->andThrow(new RuntimeException('provider unavailable'));
    app()->instance('queue.failer', $failedProvider);

    $page = new RabbitMQDeadLetters;
    $page->queue = 'default';
    $recordsMethod = new ReflectionMethod($page, 'messageRecords');
    $records = $recordsMethod->invoke($page);

    expect($records)->toHaveCount(1)
        ->and($records[0]['id'])->toBe('job-1')
        ->and($records[0]['logical_queue'])->toBe('default')
        ->and($records[0])->not->toHaveKeys(['exception', 'payload']);

    $detailsMethod = new ReflectionMethod($page, 'messageDetails');
    $details = $detailsMethod->invoke($page, $records[0]);

    expect($details['id'])->toBe('job-1')
        ->and($details['logical_queue'])->toBe('default')
        ->and($details['exception'])->toContain('Payload exception')
        ->and($details['payload'])->toContain('App\\\\Jobs\\\\ExampleJob');
});

/** @return array<string, mixed> */
function deadLetterPageConfig(): array
{
    return [
        'queue' => ['default' => 'default'],
        'connection' => 'broker',
        'strict_topology' => true,
        'dead_letter' => [
            'enabled' => true,
            'exchange' => 'dlx',
            'queue_prefix' => 'dlq:',
        ],
        'publisher' => [
            'confirm' => true,
            'mandatory' => true,
        ],
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

function deadLetterPageMessage(string $id, int $deliveryTag): AMQPMessage
{
    $message = new AMQPMessage((string) json_encode([
        'uuid' => $id,
        'displayName' => 'App\\Jobs\\ExampleJob',
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => [],
    ], JSON_THROW_ON_ERROR), ['message_id' => $id]);
    $message->setDeliveryInfo($deliveryTag, false, 'dlx', 'default');

    return $message;
}

function deadLetterPage(ChannelManager $channels): RabbitMQDeadLetters
{
    $management = Mockery::mock(ManagementClient::class);
    $management->shouldReceive('assertSafeDeadLetterQueue')->andReturnNull();
    app()->instance(ManagementClient::class, $management);
    Gate::define('viewRabbitMQDeadLetters', fn () => true);
    Auth::setUser(new GenericUser(['id' => 1]));

    $channels->shouldReceive('closeChannel')->andReturnNull()->byDefault();
    $config = deadLetterPageConfig();
    $registry = testTopologyRegistry($config);
    $queue = testRabbitMQQueue($channels, $config, $registry);
    $resolve = new ResolveDlqQueue($registry);
    $find = new FindDlqMessage($channels, $queue);
    $events = Mockery::mock(Dispatcher::class);
    $events->shouldReceive('dispatch')->andReturnNull();

    app()->instance(ReplayDlqMessages::class, new ReplayDlqMessages($channels, $resolve, $find, $queue, $events));
    app()->instance(PurgeDlqMessages::class, new PurgeDlqMessages($channels, $resolve, $find, $queue));
    app()->instance(InspectDlqMessages::class, new InspectDlqMessages($channels, $resolve, $find, $queue));

    recordDeadLetterPageNotifications();

    $page = new RabbitMQDeadLetters;
    $page->queue = 'default';

    return $page;
}

/**
 * Record sent notifications. Filament 5.10 and later buffer them in a
 * NotificationMailbox; earlier releases push them to the session.
 */
function recordDeadLetterPageNotifications(): void
{
    if (! class_exists(NotificationMailbox::class)) {
        return;
    }

    app()->instance(NotificationMailbox::class, new class extends NotificationMailbox
    {
        /** @var list<array<string, mixed>> */
        public array $sent = [];

        public function push(array $notification): void
        {
            $this->sent[] = $notification;
        }
    });
}

/** @return list<array<string, mixed>> */
function sentDeadLetterPageNotifications(): array
{
    if (class_exists(NotificationMailbox::class)) {
        return app(NotificationMailbox::class)->sent;
    }

    return array_values(session('filament.notifications', []));
}

function invokeDeadLetterPage(RabbitMQDeadLetters $page, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod($page, $method))->invoke($page, ...$arguments);
}

test('bulk forget sends one summary notification', function () {
    $dlq = mockAMQPChannel();
    $dlq->shouldReceive('basic_get')
        ->times(3)
        ->with('dlq:default', false)
        ->andReturn(
            deadLetterPageMessage('job-1', 1),
            deadLetterPageMessage('job-2', 2),
            deadLetterPageMessage('job-3', 3),
        );
    $dlq->shouldReceive('basic_ack')->times(3);
    $channels = Mockery::mock(ChannelManager::class);
    $channels->shouldReceive('channel')->with(Mockery::type('string'), 'broker')->andReturn($dlq);
    $page = deadLetterPage($channels);

    invokeDeadLetterPage($page, 'forgetMany', collect([
        ['id' => 'job-1'],
        ['id' => 'job-2'],
        ['id' => 'job-3'],
    ]));

    $notifications = sentDeadLetterPageNotifications();

    expect($notifications)->toHaveCount(1)
        ->and($notifications[0]['title'])->toBe('Removed 3 of 3 jobs')
        ->and($notifications[0]['status'])->toBe('success');
});

test('bulk forget stops at the first failure and reports it in one notification', function () {
    $dlq = mockAMQPChannel();
    $dlq->shouldReceive('basic_get')
        ->with('dlq:default', false)
        ->andReturn(deadLetterPageMessage('job-1', 1), null);
    $dlq->shouldReceive('basic_ack')->once()->with(1);
    $channels = Mockery::mock(ChannelManager::class);
    $channels->shouldReceive('channel')->with(Mockery::type('string'), 'broker')->andReturn($dlq);
    $page = deadLetterPage($channels);

    invokeDeadLetterPage($page, 'forgetMany', collect([
        ['id' => 'job-1'],
        ['id' => 'job-2'],
        ['id' => 'job-3'],
    ]));

    $notifications = sentDeadLetterPageNotifications();

    expect($notifications)->toHaveCount(1)
        ->and($notifications[0]['title'])->toBe('Removed 1 of 3 jobs')
        ->and($notifications[0]['status'])->toBe('warning')
        ->and($notifications[0]['body'])->toBe('Stopped because a job was not removed: The message was not found.');
});

test('retry all replays the messages that are in the dead-letter queue when it starts', function () {
    $dlq = mockAMQPChannel();
    $publisher = mockAMQPChannel();
    $dlq->shouldReceive('queue_declare')->once()->with('dlq:default', true)->andReturn(['dlq:default', 2, 0]);
    $dlq->shouldReceive('basic_get')
        ->twice()
        ->with('dlq:default', false)
        ->andReturn(deadLetterPageMessage('job-1', 1), deadLetterPageMessage('job-2', 2));
    $dlq->shouldReceive('basic_ack')->twice();
    $publisher->shouldReceive('basic_publish')->twice();
    $publisher->shouldReceive('wait_for_pending_acks_returns')->twice();
    $channels = Mockery::mock(ChannelManager::class);
    $channels->shouldReceive('channel')->with(Mockery::type('string'), 'broker')->andReturn($dlq);
    $channels->shouldReceive('publishChannel')->with('broker')->andReturn($publisher);
    $page = deadLetterPage($channels);

    invokeDeadLetterPage($page, 'retryAll');

    $notifications = sentDeadLetterPageNotifications();

    expect($notifications)->toHaveCount(1)
        ->and($notifications[0]['title'])->toBe('Retried 2 jobs')
        ->and($notifications[0]['status'])->toBe('success');
});

test('retry all reports the jobs that remain after the scan limit', function () {
    config()->set('rabbitmq.dlq.max_scan_messages', 1);
    $dlq = mockAMQPChannel();
    $publisher = mockAMQPChannel();
    $dlq->shouldReceive('queue_declare')->once()->andReturn(['dlq:default', 3, 0]);
    $dlq->shouldReceive('basic_get')->once()->with('dlq:default', false)->andReturn(deadLetterPageMessage('job-1', 1));
    $dlq->shouldReceive('basic_ack')->once()->with(1);
    $publisher->shouldReceive('basic_publish')->once();
    $publisher->shouldReceive('wait_for_pending_acks_returns')->once();
    $channels = Mockery::mock(ChannelManager::class);
    $channels->shouldReceive('channel')->with(Mockery::type('string'), 'broker')->andReturn($dlq);
    $channels->shouldReceive('publishChannel')->with('broker')->andReturn($publisher);
    $page = deadLetterPage($channels);

    invokeDeadLetterPage($page, 'retryAll');

    $notifications = sentDeadLetterPageNotifications();

    expect($notifications)->toHaveCount(1)
        ->and($notifications[0]['title'])->toBe('Retried 1 job')
        ->and($notifications[0]['status'])->toBe('warning')
        ->and($notifications[0]['body'])->toBe('Some jobs remain. Run retry all again to continue.');
});

test('retry all reports a broker failure instead of failing the request', function () {
    $dlq = mockAMQPChannel();
    $dlq->shouldReceive('queue_declare')->once()->andThrow(new RuntimeException('broker unavailable'));
    $channels = Mockery::mock(ChannelManager::class);
    $channels->shouldReceive('channel')->with(Mockery::type('string'), 'broker')->andReturn($dlq);
    $page = deadLetterPage($channels);

    invokeDeadLetterPage($page, 'retryAll');

    $notifications = sentDeadLetterPageNotifications();

    expect($notifications)->toHaveCount(1)
        ->and($notifications[0]['title'])->toBe('Retry all did not finish')
        ->and($notifications[0]['status'])->toBe('danger');
});
