<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Consumers\Consumer;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Queue\RabbitMQJob;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;
use Mockery\MockInterface;
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
 * Wrap a mock message as a RabbitMQJob on the given queue and real transport.
 */
function retryJob(RabbitMQQueue $rabbitmq, MockInterface $channel, MockInterface $message, string $queue): RabbitMQJob
{
    return new RabbitMQJob(new Container, $rabbitmq, $channel, $message, 'rabbitmq', $queue);
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
