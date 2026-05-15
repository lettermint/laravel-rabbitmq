<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Ramsey\Uuid\Uuid;

test('test event command publishes a JSON test payload to the requested queue', function () {
    $topologyChannel = Mockery::mock(AMQPChannel::class);
    $publishChannel = Mockery::mock(AMQPChannel::class);

    $manager = Mockery::mock(ChannelManager::class);
    $manager->shouldReceive('topologyChannel')->andReturn($topologyChannel);
    $manager->shouldReceive('publishChannel')->andReturn($publishChannel);

    app()->instance(ChannelManager::class, $manager);

    $topologyChannel->shouldReceive('queue_declare')
        ->with('diagnostics', false, true, false, false)
        ->once();

    $publishChannel->shouldReceive('basic_publish')
        ->with(
            Mockery::on(function (AMQPMessage $message): bool {
                $payload = json_decode($message->getBody(), true);

                return $payload['type'] === 'rabbitmq_test_event'
                    && $payload['message'] === 'hello'
                    && $payload['metadata']['source'] === 'rabbitmq:test-event';
            }),
            '',
            'diagnostics',
        )
        ->once();

    $exitCode = Artisan::call('rabbitmq:test-event', [
        'queue' => 'diagnostics',
        '--message' => 'hello',
        '--json' => true,
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('"success": true');
    expect($output)->toContain('"queue": "diagnostics"');
});

test('test event command can verify a round trip on a temporary queue', function () {
    $topologyChannel = Mockery::mock(AMQPChannel::class);
    $publishChannel = Mockery::mock(AMQPChannel::class);
    $consumeChannel = Mockery::mock(AMQPChannel::class);

    $manager = Mockery::mock(ChannelManager::class);
    $manager->shouldReceive('topologyChannel')->andReturn($topologyChannel);
    $manager->shouldReceive('publishChannel')->andReturn($publishChannel);
    $manager->shouldReceive('consumeChannel')->andReturn($consumeChannel);

    app()->instance(ChannelManager::class, $manager);

    $topologyChannel->shouldReceive('queue_declare')
        ->with(Mockery::pattern('/^rabbitmq-test-event-/'), false, false, true, true)
        ->once();

    $publishedQueue = null;
    $messageId = '00000000-0000-4000-8000-000000000001';

    $publishChannel->shouldReceive('basic_publish')
        ->with(
            Mockery::type(AMQPMessage::class),
            '',
            Mockery::on(function (string $queue) use (&$publishedQueue): bool {
                $publishedQueue = $queue;

                return str_starts_with($queue, 'rabbitmq-test-event-');
            }),
        )
        ->once();

    $consumeChannel->shouldReceive('basic_get')
        ->with(Mockery::pattern('/^rabbitmq-test-event-/'), false)
        ->andReturnUsing(fn () => mockAMQPMessage([
            'body' => json_encode(['uuid' => $messageId]),
            'deliveryTag' => 99,
        ]));
    $consumeChannel->shouldReceive('basic_ack')->with(99)->once();

    Str::createUuidsUsing(fn () => Uuid::fromString($messageId));

    try {
        $exitCode = Artisan::call('rabbitmq:test-event', [
            '--roundtrip' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();
    } finally {
        Str::createUuidsNormally();
    }

    expect($exitCode)->toBe(0);
    expect($output)->toContain('"consumed": true');
    expect($output)->toContain('"round_trip_ms"');
});
