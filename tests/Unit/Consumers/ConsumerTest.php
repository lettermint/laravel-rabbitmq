<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Consumers\Consumer;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Exceptions\ConnectionException;
use Lettermint\RabbitMQ\Queue\RabbitMQJob;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;
use Mockery\MockInterface;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * A Consumer subclass that exposes failure handling for tests.
 */
class RetryProbeConsumer extends Consumer
{
    public function callHandleJobException(RabbitMQJob $job, Throwable $e): void
    {
        $this->handleJobException($job, $e);
    }
}

/**
 * Build a retry consumer with a real queue over a mock AMQP channel.
 *
 * @return array{0: RetryProbeConsumer, 1: MockInterface, 2: RabbitMQQueue}
 */
function makeRetryConsumer(MockInterface $scanner): array
{
    $connection = mockAMQPConnection(heartbeat: 0);
    $channel = mockAMQPChannel($connection);

    $channelManager = Mockery::mock(ChannelManager::class);
    $channelManager->shouldReceive('consumeChannel')->andReturn($channel);
    $channelManager->shouldReceive('publishChannel')->andReturn($channel);
    $channelManager->shouldReceive('getConnection')->andReturn($connection);

    $rabbitmq = new RabbitMQQueue($channelManager, $scanner, []);
    $rabbitmq->setContainer(new Container);

    $events = Mockery::mock(Dispatcher::class);
    $events->shouldReceive('dispatch')->andReturnNull();

    $exceptions = Mockery::mock(ExceptionHandler::class);
    $exceptions->shouldReceive('report')->andReturnNull();

    $consumer = new RetryProbeConsumer($channelManager, $scanner, $rabbitmq, $exceptions, $events);

    return [$consumer, $channel, $rabbitmq];
}

/**
 * Wrap a mock message as a RabbitMQ job.
 */
function retryJob(RabbitMQQueue $rabbitmq, MockInterface $channel, MockInterface $message, string $queue): RabbitMQJob
{
    return new RabbitMQJob(new Container, $rabbitmq, $channel, $message, 'rabbitmq', $queue);
}

/**
 * Build a consumer with a channel that reports an empty queue set.
 *
 * @return array{0: Consumer, 1: MockInterface}
 */
function makeMultiQueueConsumer(): array
{
    $connection = mockAMQPConnection(heartbeat: 0);
    $channel = mockAMQPChannel($connection);
    $channel->shouldReceive('wait')->andThrow(new AMQPTimeoutException('timeout'));

    $channelManager = Mockery::mock(ChannelManager::class);
    $channelManager->shouldReceive('consumeChannel')->with('rabbitmq')->andReturn($channel);
    $channelManager->shouldReceive('getConnection')->with('rabbitmq')->andReturn($connection);
    $channelManager->shouldReceive('closeChannel')->with('consume', 'rabbitmq')->andReturnNull()->byDefault();

    $events = Mockery::mock(Dispatcher::class);
    $events->shouldReceive('dispatch')->andReturnNull();

    $consumer = new Consumer(
        $channelManager,
        Mockery::mock(AttributeScanner::class),
        Mockery::mock(RabbitMQQueue::class),
        Mockery::mock(ExceptionHandler::class),
        $events,
    );

    return [$consumer, $channel];
}

describe('failure handling', function () {
    it('publishes an intentional retry with the next package attempt', function () {
        $scanner = Mockery::mock(AttributeScanner::class);
        $scanner->shouldReceive('getQueueForJob')->andReturnNull();
        $scanner->shouldReceive('getQueues')->andReturn(collect([]));

        [$consumer, $channel, $rabbitmq] = makeRetryConsumer($scanner);

        $message = mockAMQPMessage(['deliveryTag' => 7, 'headers' => []]);

        $channel->shouldReceive('basic_publish')
            ->once()
            ->withArgs(function (AMQPMessage $published): bool {
                $headers = $published->get('application_headers');

                return $headers instanceof AMQPTable
                    && $headers->getNativeData()[RabbitMQJob::ATTEMPT_HEADER] === 2;
            });
        $channel->shouldReceive('basic_ack')->once()->with(7);
        $channel->shouldNotReceive('basic_reject');
        $channel->shouldNotReceive('tx_select');

        $consumer->callHandleJobException(
            retryJob($rabbitmq, $channel, $message, 'events:ordered'),
            new RuntimeException('transient failure'),
        );

        expect(true)->toBeTrue();
    });

    it('does not treat broker redelivery after a crash as an application retry', function () {
        $scanner = Mockery::mock(AttributeScanner::class);
        $scanner->shouldReceive('getQueueForJob')->andReturnNull();
        $scanner->shouldReceive('getQueues')->andReturn(collect([]));

        [$consumer, $channel, $rabbitmq] = makeRetryConsumer($scanner);

        $message = mockAMQPMessage([
            'deliveryTag' => 9,
            'headers' => ['x-delivery-count' => 12],
        ]);

        $channel->shouldReceive('basic_publish')->once();
        $channel->shouldReceive('basic_ack')->once()->with(9);
        $channel->shouldNotReceive('basic_reject');

        $consumer->callHandleJobException(
            retryJob($rabbitmq, $channel, $message, 'events:ordered'),
            new RuntimeException('transient failure'),
        );

        expect(true)->toBeTrue();
    });

    it('dead-letters a job that reaches the application tries limit', function () {
        $scanner = Mockery::mock(AttributeScanner::class);

        [$consumer, $channel, $rabbitmq] = makeRetryConsumer($scanner);

        $message = mockAMQPMessage([
            'deliveryTag' => 5,
            'headers' => [RabbitMQJob::ATTEMPT_HEADER => 3],
        ]);

        $channel->shouldReceive('basic_reject')->once()->with(5, false)->andReturnNull();
        $channel->shouldNotReceive('basic_publish');

        $consumer->callHandleJobException(
            retryJob($rabbitmq, $channel, $message, 'events:ordered'),
            new RuntimeException('poison'),
        );

        expect(true)->toBeTrue();
    });
});

describe('multi-queue consumption', function () {
    it('closes the selected consume channel when QoS setup fails', function () {
        $channel = mockAMQPChannel();
        $channel->shouldReceive('basic_qos')
            ->once()
            ->andThrow(new AMQPIOException('qos failed'));

        $channelManager = Mockery::mock(ChannelManager::class);
        $channelManager->shouldReceive('consumeChannel')->with('custom')->once()->andReturn($channel);
        $channelManager->shouldReceive('closeChannel')->with('consume', 'custom')->once();
        $channelManager->shouldNotReceive('getConnection');

        $consumer = new Consumer(
            $channelManager,
            Mockery::mock(AttributeScanner::class),
            Mockery::mock(RabbitMQQueue::class),
            Mockery::mock(ExceptionHandler::class),
            Mockery::mock(Dispatcher::class),
        );

        expect(fn () => $consumer->setConnection('custom')->consume())
            ->toThrow(ConnectionException::class, 'qos failed');
    });

    it('closes the selected consume channel when connection lookup fails', function () {
        $channel = mockAMQPChannel();
        $channelManager = Mockery::mock(ChannelManager::class);
        $channelManager->shouldReceive('consumeChannel')->with('custom')->once()->andReturn($channel);
        $channelManager->shouldReceive('getConnection')
            ->with('custom')
            ->once()
            ->andThrow(new ConnectionException('connection failed'));
        $channelManager->shouldReceive('closeChannel')->with('consume', 'custom')->once();

        $consumer = new Consumer(
            $channelManager,
            Mockery::mock(AttributeScanner::class),
            Mockery::mock(RabbitMQQueue::class),
            Mockery::mock(ExceptionHandler::class),
            Mockery::mock(Dispatcher::class),
        );

        expect(fn () => $consumer->setConnection('custom')->consume())
            ->toThrow(ConnectionException::class, 'connection failed');
    });

    it('rejects an empty queue list', function () {
        $consumer = new Consumer(
            Mockery::mock(ChannelManager::class),
            Mockery::mock(AttributeScanner::class),
            Mockery::mock(RabbitMQQueue::class),
            Mockery::mock(ExceptionHandler::class),
            Mockery::mock(Dispatcher::class),
        );

        expect(fn () => $consumer->setQueues([]))
            ->toThrow(InvalidArgumentException::class, 'At least one');
    });

    it('rejects blank and duplicate queue names', function (array $queues, string $message) {
        $consumer = new Consumer(
            Mockery::mock(ChannelManager::class),
            Mockery::mock(AttributeScanner::class),
            Mockery::mock(RabbitMQQueue::class),
            Mockery::mock(ExceptionHandler::class),
            Mockery::mock(Dispatcher::class),
        );

        expect(fn () => $consumer->setQueues($queues))
            ->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'blank' => [['queue-a', '  '], 'non-empty'],
        'duplicate' => [['queue-a', 'queue-a'], 'unique'],
    ]);

    it('rejects invalid QoS and wait values', function (string $method, int $value, string $message) {
        $consumer = new Consumer(
            Mockery::mock(ChannelManager::class),
            Mockery::mock(AttributeScanner::class),
            Mockery::mock(RabbitMQQueue::class),
            Mockery::mock(ExceptionHandler::class),
            Mockery::mock(Dispatcher::class),
        );

        expect(fn () => $consumer->{$method}($value))
            ->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'prefetch' => ['setPrefetch', 0, 'prefetch'],
        'timeout' => ['setTimeout', 0, 'wait timeout'],
    ]);

    it('registers one consumer per queue', function () {
        [$consumer, $channel] = makeMultiQueueConsumer();

        $channel->shouldReceive('basic_qos')->once()->with(0, 2, false);

        $channel->shouldReceive('basic_consume')
            ->withArgs(fn ($queue) => $queue === 'queue-a')
            ->once()
            ->andReturn('tag-a');
        $channel->shouldReceive('basic_consume')
            ->withArgs(fn ($queue) => $queue === 'queue-b')
            ->once()
            ->andReturn('tag-b');

        $consumer->setQueues(['queue-a', 'queue-b'])
            ->setPrefetch(2)
            ->setStopWhenEmpty(true)
            ->consume();

        expect(true)->toBeTrue();
    });

    it('cancels every registered consumer on cleanup', function () {
        [$consumer, $channel] = makeMultiQueueConsumer();

        $channel->shouldReceive('basic_consume')->andReturn('tag-a', 'tag-b');
        $channel->shouldReceive('basic_cancel')->with('tag-a')->once();
        $channel->shouldReceive('basic_cancel')->with('tag-b')->once();

        $consumer->setQueues(['queue-a', 'queue-b'])
            ->setStopWhenEmpty(true)
            ->consume();

        expect(true)->toBeTrue();
    });

    it('cancels a registered consumer when a later registration fails', function () {
        [$consumer, $channel] = makeMultiQueueConsumer();

        $channel->shouldReceive('basic_consume')
            ->withArgs(fn ($queue) => $queue === 'queue-a')
            ->once()
            ->andReturn('tag-a');
        $channel->shouldReceive('basic_consume')
            ->withArgs(fn ($queue) => $queue === 'queue-b')
            ->once()
            ->andThrow(new AMQPIOException('registration failed'));
        $channel->shouldReceive('basic_cancel')->with('tag-a')->once();

        expect(fn () => $consumer->setQueues(['queue-a', 'queue-b'])->consume())
            ->toThrow(ConnectionException::class, 'registration failed');
    });

    it('clears consumer tags before the consumer is used again', function () {
        [$consumer, $channel] = makeMultiQueueConsumer();

        $channel->shouldReceive('basic_consume')->andReturn('tag-a', 'tag-b');
        $channel->shouldReceive('basic_cancel')->with('tag-a')->once();
        $channel->shouldReceive('basic_cancel')->with('tag-b')->once();

        $consumer->setQueue('queue-a')->setStopWhenEmpty(true)->consume();
        $consumer->setQueue('queue-b')->setStopWhenEmpty(true)->consume();

        expect(true)->toBeTrue();
    });

    it('still supports a single queue through setQueue', function () {
        [$consumer, $channel] = makeMultiQueueConsumer();

        $channel->shouldReceive('basic_consume')
            ->withArgs(fn ($queue) => $queue === 'solo')
            ->once()
            ->andReturn('tag-solo');

        $consumer->setQueue('solo')
            ->setStopWhenEmpty(true)
            ->consume();

        expect(true)->toBeTrue();
    });

    it('tags each job with its source queue', function () {
        $consumer = new class(Mockery::mock(ChannelManager::class), Mockery::mock(AttributeScanner::class), Mockery::mock(RabbitMQQueue::class), Mockery::mock(ExceptionHandler::class), Mockery::mock(Dispatcher::class)) extends Consumer
        {
            /** @var list<string> */
            public array $handledQueues = [];

            public function dispatchTo(AMQPMessage $message, string $queue): void
            {
                $this->handleMessage($message, $queue);
            }

            protected function processJob(RabbitMQJob $job): void
            {
                $this->handledQueues[] = $job->getQueue();
            }
        };

        $consumer->dispatchTo(mockAMQPMessage(), 'queue-b');
        $consumer->dispatchTo(mockAMQPMessage(), 'queue-a');

        expect($consumer->handledQueues)->toBe(['queue-b', 'queue-a']);
    });
});
