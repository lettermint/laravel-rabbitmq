<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Log;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Queue\RabbitMQJob;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;

beforeEach(function () {
    $this->container = new Container;
    $this->mockChannel = mockAMQPChannel();

    $channelManager = Mockery::mock(ChannelManager::class);
    $scanner = Mockery::mock(AttributeScanner::class);

    $this->rabbitmq = new RabbitMQQueue($channelManager, $scanner, []);
    $this->rabbitmq->setContainer($this->container);
});

test('returns job ID from message ID', function () {
    $message = mockAMQPMessage([
        'messageId' => 'msg-12345',
        'body' => json_encode(['uuid' => 'payload-uuid']),
    ]);

    $job = new RabbitMQJob(
        $this->container,
        $this->rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'test-queue'
    );

    expect($job->getJobId())->toBe('msg-12345');
});

test('falls back to payload UUID when message ID empty', function () {
    $message = mockAMQPMessage([
        'messageId' => '',
        'body' => json_encode(['uuid' => 'payload-uuid']),
    ]);

    $job = new RabbitMQJob(
        $this->container,
        $this->rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'test-queue'
    );

    expect($job->getJobId())->toBe('payload-uuid');
});

test('returns queue name', function () {
    $message = mockAMQPMessage();

    $job = new RabbitMQJob(
        $this->container,
        $this->rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'my-queue'
    );

    expect($job->getQueue())->toBe('my-queue');
});

test('returns raw body from message', function () {
    $body = '{"uuid":"test","displayName":"TestJob"}';
    $message = mockAMQPMessage(['body' => $body]);

    $job = new RabbitMQJob(
        $this->container,
        $this->rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'test-queue'
    );

    expect($job->getRawBody())->toBe($body);
});

test('returns 1 for first delivery', function () {
    $message = mockAMQPMessage(['headers' => []]);

    $job = new RabbitMQJob(
        $this->container,
        $this->rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'test-queue'
    );

    expect($job->attempts())->toBe(1);
});

test('calculates attempts from x-death header', function () {
    $message = mockAMQPMessage([
        'headers' => [
            'x-death' => [
                ['queue' => 'original-queue', 'count' => 2, 'reason' => 'rejected'],
                ['queue' => 'retry-queue', 'count' => 1, 'reason' => 'expired'],
            ],
        ],
    ]);

    $job = new RabbitMQJob(
        $this->container,
        $this->rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'test-queue'
    );

    // 2 + 1 from x-death + 1 current = 4
    expect($job->attempts())->toBe(4);
});

test('decodes payload correctly', function () {
    $payload = [
        'uuid' => 'test-uuid',
        'displayName' => 'ProcessEmail',
        'job' => 'ProcessEmail@handle',
        'data' => ['email_id' => 123],
    ];

    $message = mockAMQPMessage(['body' => json_encode($payload)]);

    $job = new RabbitMQJob(
        $this->container,
        $this->rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'test-queue'
    );

    expect($job->payload())->toBe($payload);
});

test('throws exception on invalid JSON', function () {
    Log::spy();

    $message = mockAMQPMessage(['body' => 'not-valid-json']);

    $job = new RabbitMQJob(
        $this->container,
        $this->rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'test-queue'
    );

    expect(fn () => $job->payload())->toThrow(RuntimeException::class, 'invalid JSON payload');

    Log::shouldHaveReceived('critical')
        ->withArgs(fn ($msg) => str_contains($msg, 'decode'));
});

test('returns job name from payload', function () {
    $message = mockAMQPMessage([
        'body' => json_encode([
            'displayName' => 'SendNotification',
            'job' => 'SendNotification@handle',
        ]),
    ]);

    $job = new RabbitMQJob(
        $this->container,
        $this->rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'test-queue'
    );

    expect($job->getName())->toBe('SendNotification');
});

test('returns resolved name from payload', function () {
    $message = mockAMQPMessage([
        'body' => json_encode([
            'displayName' => 'ShortName',
            'data' => ['commandName' => 'App\\Jobs\\FullClassName'],
        ]),
    ]);

    $job = new RabbitMQJob(
        $this->container,
        $this->rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'test-queue'
    );

    expect($job->resolveName())->toBe('ShortName');
});

test('detects message was dead-lettered', function () {
    $message = mockAMQPMessage([
        'headers' => [
            'x-death' => [['queue' => 'original', 'count' => 1]],
        ],
    ]);

    $job = new RabbitMQJob(
        $this->container,
        $this->rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'test-queue'
    );

    expect($job->wasDeadLettered())->toBeTrue();
});

test('detects message was not dead-lettered', function () {
    $message = mockAMQPMessage(['headers' => []]);

    $job = new RabbitMQJob(
        $this->container,
        $this->rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'test-queue'
    );

    expect($job->wasDeadLettered())->toBeFalse();
});

test('returns original queue from x-death header', function () {
    $message = mockAMQPMessage([
        'headers' => [
            'x-death' => [
                ['queue' => 'emails:outbound', 'count' => 1],
            ],
        ],
    ]);

    $job = new RabbitMQJob(
        $this->container,
        $this->rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'dlq-queue'
    );

    expect($job->getOriginalQueue())->toBe('emails:outbound');
});

test('returns null when no original queue', function () {
    $message = mockAMQPMessage(['headers' => []]);

    $job = new RabbitMQJob(
        $this->container,
        $this->rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'test-queue'
    );

    expect($job->getOriginalQueue())->toBeNull();
});

test('returns max tries from payload', function () {
    $message = mockAMQPMessage([
        'body' => json_encode(['maxTries' => 5]),
    ]);

    $job = new RabbitMQJob(
        $this->container,
        $this->rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'test-queue'
    );

    expect($job->maxTries())->toBe(5);
});

test('returns null when max tries not set', function () {
    $message = mockAMQPMessage([
        'body' => json_encode([]),
    ]);

    $job = new RabbitMQJob(
        $this->container,
        $this->rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'test-queue'
    );

    expect($job->maxTries())->toBeNull();
});

test('returns timeout from payload', function () {
    $message = mockAMQPMessage([
        'body' => json_encode(['timeout' => 120]),
    ]);

    $job = new RabbitMQJob(
        $this->container,
        $this->rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'test-queue'
    );

    expect($job->timeout())->toBe(120);
});

test('returns backoff from payload', function () {
    $message = mockAMQPMessage([
        'body' => json_encode(['backoff' => [60, 120, 300]]),
    ]);

    $job = new RabbitMQJob(
        $this->container,
        $this->rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'test-queue'
    );

    expect($job->backoff())->toBe([60, 120, 300]);
});

test('returns message', function () {
    $message = mockAMQPMessage();

    $job = new RabbitMQJob(
        $this->container,
        $this->rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'test-queue'
    );

    expect($job->getMessage())->toBe($message);
});

test('returns AMQP channel', function () {
    $message = mockAMQPMessage();

    $job = new RabbitMQJob(
        $this->container,
        $this->rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'test-queue'
    );

    expect($job->getChannel())->toBe($this->mockChannel);
});

test('returns priority from message', function () {
    $message = mockAMQPMessage(['priority' => 7]);

    $job = new RabbitMQJob(
        $this->container,
        $this->rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'test-queue'
    );

    expect($job->getPriority())->toBe(7);
});

test('returns timestamp from message', function () {
    $timestamp = time();
    $message = mockAMQPMessage(['timestamp' => $timestamp]);

    $job = new RabbitMQJob(
        $this->container,
        $this->rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'test-queue'
    );

    expect($job->getTimestamp())->toBe($timestamp);
});

test('returns headers from message', function () {
    $headers = ['x-custom' => 'value'];
    $message = mockAMQPMessage(['headers' => $headers]);

    $job = new RabbitMQJob(
        $this->container,
        $this->rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'test-queue'
    );

    expect($job->getHeaders())->toBe($headers);
});
