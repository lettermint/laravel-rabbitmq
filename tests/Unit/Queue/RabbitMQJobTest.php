<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Log;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Queue\RabbitMQJob;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;

describe('RabbitMQJob', function () {
    beforeEach(function () {
        $this->container = new Container;
        $this->mockQueue = mockAMQPQueue('test-queue');

        $channelManager = Mockery::mock(ChannelManager::class);
        $scanner = Mockery::mock(AttributeScanner::class);

        $this->rabbitmq = new RabbitMQQueue($channelManager, $scanner, []);
        $this->rabbitmq->setContainer($this->container);
    });

    describe('job identification', function () {
        it('returns job ID from envelope message ID', function () {
            $envelope = mockAMQPEnvelope([
                'messageId' => 'msg-12345',
                'body' => json_encode(['uuid' => 'payload-uuid']),
            ]);

            $job = new RabbitMQJob(
                $this->container,
                $this->rabbitmq,
                $this->mockQueue,
                $envelope,
                'rabbitmq',
                'test-queue'
            );

            expect($job->getJobId())->toBe('msg-12345');
        });

        it('falls back to payload UUID when message ID empty', function () {
            $envelope = mockAMQPEnvelope([
                'messageId' => '',
                'body' => json_encode(['uuid' => 'payload-uuid']),
            ]);

            $job = new RabbitMQJob(
                $this->container,
                $this->rabbitmq,
                $this->mockQueue,
                $envelope,
                'rabbitmq',
                'test-queue'
            );

            expect($job->getJobId())->toBe('payload-uuid');
        });

        it('returns queue name', function () {
            $envelope = mockAMQPEnvelope();

            $job = new RabbitMQJob(
                $this->container,
                $this->rabbitmq,
                $this->mockQueue,
                $envelope,
                'rabbitmq',
                'my-queue'
            );

            expect($job->getQueue())->toBe('my-queue');
        });
    });

    describe('raw body', function () {
        it('returns raw body from envelope', function () {
            $body = '{"uuid":"test","displayName":"TestJob"}';
            $envelope = mockAMQPEnvelope(['body' => $body]);

            $job = new RabbitMQJob(
                $this->container,
                $this->rabbitmq,
                $this->mockQueue,
                $envelope,
                'rabbitmq',
                'test-queue'
            );

            expect($job->getRawBody())->toBe($body);
        });
    });

    describe('attempts tracking', function () {
        it('returns 1 for first delivery', function () {
            $envelope = mockAMQPEnvelope(['headers' => []]);

            $job = new RabbitMQJob(
                $this->container,
                $this->rabbitmq,
                $this->mockQueue,
                $envelope,
                'rabbitmq',
                'test-queue'
            );

            expect($job->attempts())->toBe(1);
        });

        it('calculates attempts from x-death header', function () {
            $envelope = mockAMQPEnvelope([
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
                $this->mockQueue,
                $envelope,
                'rabbitmq',
                'test-queue'
            );

            // 2 + 1 from x-death + 1 current = 4
            expect($job->attempts())->toBe(4);
        });
    });

    describe('payload decoding', function () {
        it('decodes payload correctly', function () {
            $payload = [
                'uuid' => 'test-uuid',
                'displayName' => 'ProcessEmail',
                'job' => 'ProcessEmail@handle',
                'data' => ['email_id' => 123],
            ];

            $envelope = mockAMQPEnvelope(['body' => json_encode($payload)]);

            $job = new RabbitMQJob(
                $this->container,
                $this->rabbitmq,
                $this->mockQueue,
                $envelope,
                'rabbitmq',
                'test-queue'
            );

            expect($job->payload())->toBe($payload);
        });

        it('handles invalid JSON gracefully', function () {
            Log::spy();

            $envelope = mockAMQPEnvelope(['body' => 'not-valid-json']);

            $job = new RabbitMQJob(
                $this->container,
                $this->rabbitmq,
                $this->mockQueue,
                $envelope,
                'rabbitmq',
                'test-queue'
            );

            expect($job->payload())->toBe([]);

            Log::shouldHaveReceived('error')
                ->withArgs(fn ($msg) => str_contains($msg, 'decode'));
        });

        it('returns job name from payload', function () {
            $envelope = mockAMQPEnvelope([
                'body' => json_encode([
                    'displayName' => 'SendNotification',
                    'job' => 'SendNotification@handle',
                ]),
            ]);

            $job = new RabbitMQJob(
                $this->container,
                $this->rabbitmq,
                $this->mockQueue,
                $envelope,
                'rabbitmq',
                'test-queue'
            );

            expect($job->getName())->toBe('SendNotification');
        });

        it('returns resolved name from payload', function () {
            $envelope = mockAMQPEnvelope([
                'body' => json_encode([
                    'displayName' => 'ShortName',
                    'data' => ['commandName' => 'App\\Jobs\\FullClassName'],
                ]),
            ]);

            $job = new RabbitMQJob(
                $this->container,
                $this->rabbitmq,
                $this->mockQueue,
                $envelope,
                'rabbitmq',
                'test-queue'
            );

            expect($job->resolveName())->toBe('ShortName');
        });
    });

    describe('dead letter detection', function () {
        it('detects message was dead-lettered', function () {
            $envelope = mockAMQPEnvelope([
                'headers' => [
                    'x-death' => [['queue' => 'original', 'count' => 1]],
                ],
            ]);

            $job = new RabbitMQJob(
                $this->container,
                $this->rabbitmq,
                $this->mockQueue,
                $envelope,
                'rabbitmq',
                'test-queue'
            );

            expect($job->wasDeadLettered())->toBeTrue();
        });

        it('detects message was not dead-lettered', function () {
            $envelope = mockAMQPEnvelope(['headers' => []]);

            $job = new RabbitMQJob(
                $this->container,
                $this->rabbitmq,
                $this->mockQueue,
                $envelope,
                'rabbitmq',
                'test-queue'
            );

            expect($job->wasDeadLettered())->toBeFalse();
        });

        it('returns original queue from x-death header', function () {
            $envelope = mockAMQPEnvelope([
                'headers' => [
                    'x-death' => [
                        ['queue' => 'emails:outbound', 'count' => 1],
                    ],
                ],
            ]);

            $job = new RabbitMQJob(
                $this->container,
                $this->rabbitmq,
                $this->mockQueue,
                $envelope,
                'rabbitmq',
                'dlq-queue'
            );

            expect($job->getOriginalQueue())->toBe('emails:outbound');
        });

        it('returns null when no original queue', function () {
            $envelope = mockAMQPEnvelope(['headers' => []]);

            $job = new RabbitMQJob(
                $this->container,
                $this->rabbitmq,
                $this->mockQueue,
                $envelope,
                'rabbitmq',
                'test-queue'
            );

            expect($job->getOriginalQueue())->toBeNull();
        });
    });

    describe('job options from payload', function () {
        it('returns max tries from payload', function () {
            $envelope = mockAMQPEnvelope([
                'body' => json_encode(['maxTries' => 5]),
            ]);

            $job = new RabbitMQJob(
                $this->container,
                $this->rabbitmq,
                $this->mockQueue,
                $envelope,
                'rabbitmq',
                'test-queue'
            );

            expect($job->maxTries())->toBe(5);
        });

        it('returns null when max tries not set', function () {
            $envelope = mockAMQPEnvelope([
                'body' => json_encode([]),
            ]);

            $job = new RabbitMQJob(
                $this->container,
                $this->rabbitmq,
                $this->mockQueue,
                $envelope,
                'rabbitmq',
                'test-queue'
            );

            expect($job->maxTries())->toBeNull();
        });

        it('returns timeout from payload', function () {
            $envelope = mockAMQPEnvelope([
                'body' => json_encode(['timeout' => 120]),
            ]);

            $job = new RabbitMQJob(
                $this->container,
                $this->rabbitmq,
                $this->mockQueue,
                $envelope,
                'rabbitmq',
                'test-queue'
            );

            expect($job->timeout())->toBe(120);
        });

        it('returns backoff from payload', function () {
            $envelope = mockAMQPEnvelope([
                'body' => json_encode(['backoff' => [60, 120, 300]]),
            ]);

            $job = new RabbitMQJob(
                $this->container,
                $this->rabbitmq,
                $this->mockQueue,
                $envelope,
                'rabbitmq',
                'test-queue'
            );

            expect($job->backoff())->toBe([60, 120, 300]);
        });
    });

    describe('envelope accessors', function () {
        it('returns envelope', function () {
            $envelope = mockAMQPEnvelope();

            $job = new RabbitMQJob(
                $this->container,
                $this->rabbitmq,
                $this->mockQueue,
                $envelope,
                'rabbitmq',
                'test-queue'
            );

            expect($job->getEnvelope())->toBe($envelope);
        });

        it('returns AMQP queue', function () {
            $envelope = mockAMQPEnvelope();

            $job = new RabbitMQJob(
                $this->container,
                $this->rabbitmq,
                $this->mockQueue,
                $envelope,
                'rabbitmq',
                'test-queue'
            );

            expect($job->getAMQPQueue())->toBe($this->mockQueue);
        });

        it('returns priority from envelope', function () {
            $envelope = mockAMQPEnvelope(['priority' => 7]);

            $job = new RabbitMQJob(
                $this->container,
                $this->rabbitmq,
                $this->mockQueue,
                $envelope,
                'rabbitmq',
                'test-queue'
            );

            expect($job->getPriority())->toBe(7);
        });

        it('returns timestamp from envelope', function () {
            $timestamp = time();
            $envelope = mockAMQPEnvelope(['timestamp' => $timestamp]);

            $job = new RabbitMQJob(
                $this->container,
                $this->rabbitmq,
                $this->mockQueue,
                $envelope,
                'rabbitmq',
                'test-queue'
            );

            expect($job->getTimestamp())->toBe($timestamp);
        });

        it('returns headers from envelope', function () {
            $headers = ['x-custom' => 'value'];
            $envelope = mockAMQPEnvelope(['headers' => $headers]);

            $job = new RabbitMQJob(
                $this->container,
                $this->rabbitmq,
                $this->mockQueue,
                $envelope,
                'rabbitmq',
                'test-queue'
            );

            expect($job->getHeaders())->toBe($headers);
        });
    });
});
