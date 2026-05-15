<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Consumers\Consumer;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\Heartbeat\PCNTLHeartbeatSender;
use PhpAmqpLib\Connection\Heartbeat\SIGHeartbeatSender;

function heartbeatTestConsumer(): Consumer
{
    return new class(Mockery::mock(ChannelManager::class), Mockery::mock(AttributeScanner::class), Mockery::mock(RabbitMQQueue::class), Mockery::mock(ExceptionHandler::class), Mockery::mock(Dispatcher::class)) extends Consumer
    {
        public function heartbeatSenderFor(AbstractConnection $connection): object
        {
            return $this->createHeartbeatSender($connection);
        }
    };
}

test('consumer uses pcntl heartbeat sender by default', function () {
    config()->set('rabbitmq.heartbeat_sender.driver', 'pcntl');

    $sender = heartbeatTestConsumer()->heartbeatSenderFor(mockAMQPConnection(true, 10));

    expect($sender)->toBeInstanceOf(PCNTLHeartbeatSender::class);
});

test('consumer can use signal heartbeat sender to avoid SIGALRM conflicts', function () {
    config()->set('rabbitmq.heartbeat_sender.driver', 'signal');
    config()->set('rabbitmq.heartbeat_sender.signal', 'SIGUSR1');

    $sender = heartbeatTestConsumer()->heartbeatSenderFor(mockAMQPConnection(true, 10));

    expect($sender)->toBeInstanceOf(SIGHeartbeatSender::class);
});

test('consumer rejects unknown heartbeat sender drivers', function () {
    config()->set('rabbitmq.heartbeat_sender.driver', 'unknown');

    heartbeatTestConsumer()->heartbeatSenderFor(mockAMQPConnection(true, 10));
})->throws(InvalidArgumentException::class, 'Unsupported RabbitMQ heartbeat sender driver');
