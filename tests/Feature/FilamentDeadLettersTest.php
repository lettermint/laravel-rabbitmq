<?php

declare(strict_types=1);

use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Lettermint\RabbitMQ\Actions\Dlq\FindDlqMessage;
use Lettermint\RabbitMQ\Actions\Dlq\InspectDlqMessages;
use Lettermint\RabbitMQ\Actions\Dlq\ResolveDlqQueue;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Filament\Pages\RabbitMQDeadLetters;
use Lettermint\RabbitMQ\Filament\RabbitMQPlugin;

test('the Filament plugin has a stable identifier', function () {
    expect(RabbitMQPlugin::make()->getId())->toBe('lettermint-rabbitmq');
});

test('the dead-letter page keeps RabbitMQ records available when the optional failed provider fails', function () {
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
        ->twice()
        ->with('dlq:default', false)
        ->andReturn($message, null);
    $channel->shouldReceive('basic_reject')->once()->with($message->getDeliveryTag(), true);
    $channels = Mockery::mock(ChannelManager::class);
    $channels->shouldReceive('channel')->once()->with('dlq-inspect', 'broker')->andReturn($channel);
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
    $method = new ReflectionMethod($page, 'records');
    $records = $method->invoke($page);

    expect($records)->toHaveCount(1)
        ->and($records[0]['id'])->toBe('job-1')
        ->and($records[0]['exception'])->toBe('Payload exception');
});
