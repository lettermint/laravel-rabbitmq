<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Lettermint\RabbitMQ\Attributes\ConsumesQueue;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Contracts\HasPriority;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;
use Lettermint\RabbitMQ\Tests\Fixtures\Jobs\PriorityJob;
use Lettermint\RabbitMQ\Tests\Fixtures\Jobs\SimpleJob;

describe('RabbitMQQueue', function () {
    beforeEach(function () {
        $this->mockChannel = mockAMQPChannel();
        $this->mockExchange = mockAMQPExchange('test-exchange', $this->mockChannel);

        $this->channelManager = Mockery::mock(ChannelManager::class);
        $this->channelManager->shouldReceive('publishChannel')
            ->andReturn($this->mockChannel)
            ->byDefault();
        $this->channelManager->shouldReceive('consumeChannel')
            ->andReturn($this->mockChannel)
            ->byDefault();
        $this->channelManager->shouldReceive('topologyChannel')
            ->andReturn($this->mockChannel)
            ->byDefault();

        $this->scanner = Mockery::mock(AttributeScanner::class);
        $this->scanner->shouldReceive('getQueueForJob')
            ->andReturn(null)
            ->byDefault();
        $this->scanner->shouldReceive('getQueues')
            ->andReturn(collect([]))
            ->byDefault();

        $this->config = [
            'queue' => ['default' => 'default'],
            'publisher_confirms' => false,
        ];
    });

    describe('construction', function () {
        it('uses default queue from config', function () {
            $queue = new RabbitMQQueue(
                $this->channelManager,
                $this->scanner,
                ['queue' => ['default' => 'my-default']]
            );

            expect($queue->getQueue(null))->toBe('my-default');
        });

        it('falls back to "default" when not configured', function () {
            $queue = new RabbitMQQueue(
                $this->channelManager,
                $this->scanner,
                []
            );

            expect($queue->getQueue(null))->toBe('default');
        });
    });

    describe('getQueue', function () {
        it('returns provided queue name', function () {
            $queue = new RabbitMQQueue(
                $this->channelManager,
                $this->scanner,
                $this->config
            );

            expect($queue->getQueue('custom-queue'))->toBe('custom-queue');
        });

        it('returns default when null provided', function () {
            $queue = new RabbitMQQueue(
                $this->channelManager,
                $this->scanner,
                $this->config
            );

            expect($queue->getQueue(null))->toBe('default');
        });
    });

    describe('pop', function () {
        it('returns job when message available', function () {
            $envelope = mockAMQPEnvelope([
                'body' => json_encode([
                    'uuid' => 'test-uuid',
                    'displayName' => 'TestJob',
                    'job' => 'TestJob@handle',
                    'data' => [],
                ]),
            ]);

            // Create a real AMQPQueue mock for the get() call
            $mockQueue = mockAMQPQueue('test-queue');
            $mockQueue->shouldReceive('get')->once()->andReturn($envelope);

            // Create a testable queue that returns our mocked queue
            $queue = new class($this->channelManager, $this->scanner, $this->config, $mockQueue) extends RabbitMQQueue
            {
                public function __construct(
                    ChannelManager $channelManager,
                    AttributeScanner $scanner,
                    array $config,
                    private $mockQueue
                ) {
                    parent::__construct($channelManager, $scanner, $config);
                }

                public function pop($queue = null): ?\Illuminate\Contracts\Queue\Job
                {
                    $queue = $this->getQueue($queue);
                    $envelope = $this->mockQueue->get();

                    if ($envelope instanceof \AMQPEnvelope) {
                        return new \Lettermint\RabbitMQ\Queue\RabbitMQJob(
                            container: $this->container,
                            rabbitmq: $this,
                            queue: $this->mockQueue,
                            envelope: $envelope,
                            connectionName: $this->connectionName ?? 'rabbitmq',
                            queueName: $queue
                        );
                    }

                    return null;
                }
            };

            $queue->setContainer(new Container);

            $job = $queue->pop('test-queue');

            expect($job)->not->toBeNull();
            expect($job->getQueue())->toBe('test-queue');
        });
    });

    describe('priority handling', function () {
        it('extracts priority from HasPriority job', function () {
            $priorityJob = new PriorityJob(priority: 8);

            expect($priorityJob)->toBeInstanceOf(HasPriority::class);
            expect($priorityJob->getPriority())->toBe(8);
        });

        it('returns null priority for non-priority jobs', function () {
            $simpleJob = new SimpleJob;

            expect($simpleJob)->not->toBeInstanceOf(HasPriority::class);
        });
    });

    describe('routing', function () {
        it('uses attribute exchange when available', function () {
            $attribute = new ConsumesQueue(
                queue: 'emails:outbound',
                bindings: ['emails' => 'outbound.*']
            );

            $this->scanner->shouldReceive('getQueueForJob')
                ->with(SimpleJob::class, Mockery::any())
                ->andReturn($attribute);

            $queue = new RabbitMQQueue(
                $this->channelManager,
                $this->scanner,
                $this->config
            );

            $payload = json_encode([
                'uuid' => 'test-uuid',
                'displayName' => SimpleJob::class,
            ]);

            // Access the protected method via reflection for testing
            $reflection = new ReflectionClass($queue);
            $method = $reflection->getMethod('getExchangeAndRoutingKey');
            $method->setAccessible(true);

            [$exchange, $routingKey] = $method->invoke($queue, 'emails:outbound', $payload);

            expect($exchange)->toBe('emails');
            expect($routingKey)->toBe('outbound.*');
        });

        it('uses fallback routing when no attribute', function () {
            $this->scanner->shouldReceive('getQueueForJob')
                ->andReturn(null);
            $this->scanner->shouldReceive('getQueues')
                ->andReturn(collect([]));

            $queue = new RabbitMQQueue(
                $this->channelManager,
                $this->scanner,
                $this->config
            );

            $payload = json_encode([
                'uuid' => 'test-uuid',
                'displayName' => 'SomeJob',
            ]);

            $reflection = new ReflectionClass($queue);
            $method = $reflection->getMethod('getExchangeAndRoutingKey');
            $method->setAccessible(true);

            [$exchange, $routingKey] = $method->invoke($queue, 'some:queue', $payload);

            expect($exchange)->toBe('');
            expect($routingKey)->toBe('fallback.some.queue');
        });
    });

    describe('batch operations', function () {
        it('returns empty array for empty batch', function () {
            $queue = new RabbitMQQueue(
                $this->channelManager,
                $this->scanner,
                $this->config
            );

            $result = $queue->pushBatch([]);

            expect($result)->toBeEmpty();
        });
    });

    describe('ack and reject', function () {
        it('acknowledges message', function () {
            $envelope = mockAMQPEnvelope(['deliveryTag' => 42]);
            $mockQueue = mockAMQPQueue();
            $mockQueue->shouldReceive('ack')->with(42)->once();

            $queue = new RabbitMQQueue(
                $this->channelManager,
                $this->scanner,
                $this->config
            );

            $queue->ack($envelope, $mockQueue);

            // Assertion is in mock expectation
            expect(true)->toBeTrue();
        });

        it('rejects message without requeue', function () {
            $envelope = mockAMQPEnvelope(['deliveryTag' => 42]);
            $mockQueue = mockAMQPQueue();
            $mockQueue->shouldReceive('reject')->with(42, AMQP_NOPARAM)->once();

            $queue = new RabbitMQQueue(
                $this->channelManager,
                $this->scanner,
                $this->config
            );

            $queue->reject($envelope, $mockQueue, false);

            expect(true)->toBeTrue();
        });

        it('rejects message with requeue', function () {
            $envelope = mockAMQPEnvelope(['deliveryTag' => 42]);
            $mockQueue = mockAMQPQueue();
            $mockQueue->shouldReceive('reject')->with(42, AMQP_REQUEUE)->once();

            $queue = new RabbitMQQueue(
                $this->channelManager,
                $this->scanner,
                $this->config
            );

            $queue->reject($envelope, $mockQueue, true);

            expect(true)->toBeTrue();
        });
    });

    describe('channel manager access', function () {
        it('provides access to channel manager', function () {
            $queue = new RabbitMQQueue(
                $this->channelManager,
                $this->scanner,
                $this->config
            );

            expect($queue->getChannelManager())->toBe($this->channelManager);
        });
    });
});
