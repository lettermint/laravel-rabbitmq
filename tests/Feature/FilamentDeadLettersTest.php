<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Lettermint\RabbitMQ\Actions\Dlq\FindDlqMessage;
use Lettermint\RabbitMQ\Actions\Dlq\InspectDlqMessages;
use Lettermint\RabbitMQ\Actions\Dlq\ResolveDlqQueue;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Filament\Pages\RabbitMQDeadLetters;
use Lettermint\RabbitMQ\Filament\RabbitMQPlugin;
use Lettermint\RabbitMQ\Monitoring\QueueMetrics;
use Lettermint\RabbitMQ\Topology\TopologyRegistry;

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
        'physical_prefix' => 'lm.test.',
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
    $metrics->shouldReceive('getPhysicalQueueStats')->once()->with('lm.test.dlq:default')->andReturn($available(0));
    $metrics->shouldReceive('getPhysicalQueueStats')->once()->with('lm.test.dlq:busy')->andReturn($available(7));
    $metrics->shouldReceive('getPhysicalQueueStats')->once()->with('lm.test.dlq:missing')->andReturn($unavailable);
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
        ->and($records[0])->not->toHaveKeys(['exception', 'payload']);

    $detailsMethod = new ReflectionMethod($page, 'messageDetails');
    $details = $detailsMethod->invoke($page, $records[0]);

    expect($details['id'])->toBe('job-1')
        ->and($details['queue'])->toBe('default')
        ->and($details['exception'])->toContain('Payload exception')
        ->and($details['payload'])->toContain('App\\\\Jobs\\\\ExampleJob');
});
