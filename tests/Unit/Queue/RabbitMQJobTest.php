<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Log;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Exceptions\ConnectionException;
use Lettermint\RabbitMQ\Exceptions\PublishException;
use Lettermint\RabbitMQ\Queue\RabbitMQJob;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

beforeEach(function () {
    $this->container = new Container;
    $this->mockChannel = mockAMQPChannel();

    $channelManager = Mockery::mock(ChannelManager::class);
    $this->rabbitmq = testRabbitMQQueue($channelManager);
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

test('does not use RabbitMQ delivery metadata as Laravel attempts', function () {
    $message = mockAMQPMessage([
        'headers' => [
            'x-delivery-count' => 9,
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

    expect($job->attempts())->toBe(1)
        ->and($job->brokerDeliveryCount())->toBe(9);
});

test('reads Laravel attempts from the package header', function () {
    $message = mockAMQPMessage([
        'headers' => [RabbitMQJob::ATTEMPT_HEADER => 3, 'x-delivery-count' => 8],
    ]);

    $job = new RabbitMQJob(
        $this->container,
        $this->rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'test-queue'
    );

    expect($job->attempts())->toBe(3);
});

test('ignores an invalid package attempt header', function () {
    $message = mockAMQPMessage([
        'headers' => [RabbitMQJob::ATTEMPT_HEADER => 0],
    ]);

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

test('uses legacy payload attempts when the package header is absent', function () {
    $message = mockAMQPMessage([
        'body' => createFailedJobPayload('App\\Jobs\\Thing', 'test-queue', attemptCount: 7),
        'headers' => ['x-delivery-count' => 2],
    ]);

    $job = new RabbitMQJob(
        $this->container,
        $this->rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'test-queue'
    );

    expect($job->attempts())->toBe(7);
});

test('release publishes the next attempt before it acknowledges the original message', function () {
    $message = mockAMQPMessage([
        'deliveryTag' => 42,
        'messageId' => 'message-123',
        'correlationId' => 'correlation-456',
        'timestamp' => 123456789,
        'priority' => 7,
        'headers' => [
            'x-custom' => 'keep-me',
            'x-delivery-count' => 4,
        ],
    ]);

    $publishChannel = mockAMQPChannel();
    $channelManager = Mockery::mock(ChannelManager::class);
    $channelManager->shouldReceive('topologyChannel')->with('default')->andReturn($publishChannel);
    $channelManager->shouldReceive('publishChannel')->with('default')->andReturn($publishChannel);
    $rabbitmq = testRabbitMQQueue($channelManager);
    $publishChannel->shouldReceive('basic_publish')
        ->once()
        ->ordered()
        ->withArgs(function (AMQPMessage $published, string $exchange, string $routingKey): bool {
            $headers = $published->get('application_headers');

            expect(json_decode($published->getBody(), true)['uuid'])->toBe('test-uuid')
                ->and($exchange)->toBe('')
                ->and($routingKey)->toContain('delay-v2:test-queue:15000:')
                ->and($published->get('message_id'))->toBe('message-123')
                ->and($published->get('correlation_id'))->toBe('correlation-456')
                ->and($published->get('timestamp'))->toBe(123456789)
                ->and($published->get('priority'))->toBe(7)
                ->and($headers)->toBeInstanceOf(AMQPTable::class)
                ->and($headers->getNativeData()['x-custom'])->toBe('keep-me')
                ->and($headers->getNativeData()[RabbitMQJob::ATTEMPT_HEADER])->toBe(2)
                ->and($headers->getNativeData())->not->toHaveKey('x-delivery-count');

            return true;
        });
    $this->mockChannel->shouldReceive('basic_ack')->once()->ordered()->with(42);

    $job = new RabbitMQJob(
        $this->container,
        $rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'test-queue'
    );

    $job->release(15);

    expect($job->isReleased())->toBeTrue();
});

test('release preserves a recorded exception in the replacement payload', function () {
    $message = mockAMQPMessage(['deliveryTag' => 42]);
    $publishChannel = mockAMQPChannel();
    $channelManager = Mockery::mock(ChannelManager::class);
    $channelManager->shouldReceive('topologyChannel')->with('default')->andReturn($publishChannel);
    $channelManager->shouldReceive('publishChannel')->with('default')->andReturn($publishChannel);
    $rabbitmq = testRabbitMQQueue($channelManager);
    $publishChannel->shouldReceive('basic_publish')
        ->once()
        ->withArgs(function (AMQPMessage $published): bool {
            expect(json_decode($published->getBody(), true)['exception'])->toBe([
                'class' => RuntimeException::class,
                'message' => 'Retry this job.',
                'code' => 0,
            ]);

            return true;
        });
    $this->mockChannel->shouldReceive('basic_ack')->once()->with(42);

    $job = new RabbitMQJob(
        $this->container,
        $rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'test-queue'
    );
    $job->recordReleaseException(new RuntimeException('Retry this job.'));

    $job->release();
});

test('release leaves the original unacknowledged when publish fails', function () {
    Log::spy();

    $message = mockAMQPMessage(['deliveryTag' => 42]);
    $publishChannel = mockAMQPChannel();
    $publishChannel->shouldReceive('wait_for_pending_acks_returns')
        ->once()
        ->andThrow(new AMQPTimeoutException('publish failed'));
    $channelManager = Mockery::mock(ChannelManager::class);
    $channelManager->shouldReceive('publishChannel')->with('default')->andReturn($publishChannel);
    $channelManager->shouldReceive('closeChannel')->once()->with('publish', 'default');
    $rabbitmq = testRabbitMQQueue($channelManager);
    $this->mockChannel->shouldNotReceive('basic_ack');
    $this->mockChannel->shouldNotReceive('basic_reject');

    $job = new RabbitMQJob(
        $this->container,
        $rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'test-queue'
    );

    expect(fn () => $job->release(0))
        ->toThrow(PublishException::class, 'result is uncertain');

    Log::shouldHaveReceived('critical')
        ->withArgs(fn (string $message): bool => str_contains($message, 'remains unacknowledged'));
});

test('propagates an acknowledgement failure after successful work', function () {
    Log::spy();
    $message = mockAMQPMessage(['deliveryTag' => 42]);
    $this->mockChannel->shouldReceive('basic_ack')
        ->once()
        ->with(42)
        ->andThrow(new AMQPIOException('ack failed'));
    $job = new RabbitMQJob(
        $this->container,
        $this->rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'test-queue',
    );

    expect(fn () => $job->delete())
        ->toThrow(ConnectionException::class, 'acknowledge');
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

test('returns Laravel backoff from payload', function () {
    $message = mockAMQPMessage([
        'body' => json_encode(['backoff' => '60,120,300']),
    ]);

    $job = new RabbitMQJob(
        $this->container,
        $this->rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'test-queue'
    );

    expect($job->backoff())->toBe('60,120,300');
});

test('normalizes an array backoff from a legacy payload', function () {
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

    expect($job->backoff())->toBe('60,120,300');
});

test('uses the legacy delay when backoff is absent', function () {
    $message = mockAMQPMessage([
        'body' => json_encode(['delay' => 45]),
    ]);

    $job = new RabbitMQJob(
        $this->container,
        $this->rabbitmq,
        $this->mockChannel,
        $message,
        'rabbitmq',
        'test-queue'
    );

    expect($job->backoff())->toBe(45);
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
