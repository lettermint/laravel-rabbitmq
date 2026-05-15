<?php

declare(strict_types=1);

use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Lettermint\RabbitMQ\Attributes\ConsumesQueue;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Queue\Failed\RabbitMQDlqFailedJobProvider;
use Lettermint\RabbitMQ\Tests\Fixtures\Jobs\SimpleJob;

beforeEach(function () {
    $this->channel = mockAMQPChannel();

    $this->channelManager = Mockery::mock(ChannelManager::class);
    $this->channelManager->shouldReceive('channel')
        ->with('failed-jobs')
        ->andReturn($this->channel)
        ->byDefault();

    $attribute = new ConsumesQueue(
        queue: 'emails',
        bindings: ['emails' => 'email.*'],
    );

    $this->scanner = Mockery::mock(AttributeScanner::class);
    $this->scanner->shouldReceive('getTopology')
        ->andReturn([
            'exchanges' => [],
            'queues' => [
                'emails' => [
                    'class' => SimpleJob::class,
                    'attribute' => $attribute,
                ],
            ],
        ])
        ->byDefault();

    $this->provider = new RabbitMQDlqFailedJobProvider(
        channelManager: $this->channelManager,
        scanner: $this->scanner,
        connectionName: 'rabbitmq',
        scanLimit: 10,
    );
});

test('it implements Laravel failed job provider contract', function () {
    expect($this->provider)->toBeInstanceOf(FailedJobProviderInterface::class);
});

test('it maps DLQ messages to Laravel failed job rows without removing them', function () {
    $message = mockAMQPMessage([
        'body' => createFailedJobPayload(SimpleJob::class, 'emails', 3, [
            'uuid' => 'failed-uuid',
            'exception' => [
                'class' => RuntimeException::class,
                'message' => 'SMTP unavailable',
            ],
        ]),
        'deliveryTag' => 42,
        'headers' => [
            'x-death' => createXDeathHeader('emails', 2, 'rejected'),
        ],
    ]);

    $this->channel->shouldReceive('basic_get')
        ->with('dlq:emails', false)
        ->andReturn($message, null);
    $this->channel->shouldReceive('basic_reject')
        ->with(42, true)
        ->once();

    $rows = $this->provider->all();

    expect($rows)->toHaveCount(1);
    expect($rows[0])->toMatchObject([
        'id' => 'failed-uuid',
        'connection' => 'rabbitmq',
        'queue' => 'emails',
        'payload' => $message->getBody(),
    ]);
    expect($rows[0]->exception)->toContain('SMTP unavailable');
    expect($rows[0]->failed_at)->not->toBeEmpty();
});

test('it returns failed job IDs for a queue', function () {
    $message = mockAMQPMessage([
        'body' => createFailedJobPayload(SimpleJob::class, 'emails', 2, [
            'uuid' => 'failed-uuid',
        ]),
        'deliveryTag' => 43,
    ]);

    $this->channel->shouldReceive('basic_get')
        ->with('dlq:emails', false)
        ->andReturn($message, null);
    $this->channel->shouldReceive('basic_reject')
        ->with(43, true)
        ->once();

    expect($this->provider->ids('emails'))->toBe(['failed-uuid']);
});

test('it finds one failed job by ID and leaves the DLQ intact', function () {
    $other = mockAMQPMessage([
        'body' => createFailedJobPayload(SimpleJob::class, 'emails', 1, [
            'uuid' => 'other-uuid',
        ]),
        'deliveryTag' => 44,
    ]);
    $target = mockAMQPMessage([
        'body' => createFailedJobPayload(SimpleJob::class, 'emails', 4, [
            'uuid' => 'target-uuid',
        ]),
        'deliveryTag' => 45,
    ]);

    $this->channel->shouldReceive('basic_get')
        ->with('dlq:emails', false)
        ->andReturn($other, $target);
    $this->channel->shouldReceive('basic_reject')
        ->with(44, true)
        ->once();
    $this->channel->shouldReceive('basic_reject')
        ->with(45, true)
        ->once();

    $job = $this->provider->find('target-uuid');

    expect($job)->not->toBeNull();
    expect($job->id)->toBe('target-uuid');
    expect($job->queue)->toBe('emails');
});

test('it forgets a failed job by acknowledging the matching DLQ message', function () {
    $other = mockAMQPMessage([
        'body' => createFailedJobPayload(SimpleJob::class, 'emails', 1, [
            'uuid' => 'other-uuid',
        ]),
        'deliveryTag' => 46,
    ]);
    $target = mockAMQPMessage([
        'body' => createFailedJobPayload(SimpleJob::class, 'emails', 4, [
            'uuid' => 'target-uuid',
        ]),
        'deliveryTag' => 47,
    ]);

    $this->channel->shouldReceive('basic_get')
        ->with('dlq:emails', false)
        ->andReturn($other, $target);
    $this->channel->shouldReceive('basic_reject')
        ->with(46, true)
        ->once();
    $this->channel->shouldReceive('basic_ack')
        ->with(47)
        ->once();

    expect($this->provider->forget('target-uuid'))->toBeTrue();
});
