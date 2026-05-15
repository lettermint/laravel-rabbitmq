<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Connection\ConnectionManager;
use Lettermint\RabbitMQ\Consumers\Consumer;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\Heartbeat\AbstractSignalHeartbeatSender;

pest()->group('integration');

it('keeps the RabbitMQ connection alive during long blocking PHP work', function () {
    if (! extension_loaded('pcntl')) {
        $this->markTestSkipped('pcntl is required for signal-based heartbeat senders');
    }

    if (! canConnectToRabbitMQForHeartbeatTest()) {
        $this->markTestSkipped('RabbitMQ is not available');
    }

    config()->set('rabbitmq.heartbeat_sender.driver', 'signal');
    config()->set('rabbitmq.heartbeat_sender.signal', 'SIGUSR1');

    $connectionManager = new ConnectionManager([
        'default' => 'default',
        'connections' => [
            'default' => [
                'hosts' => [
                    [
                        'host' => env('RABBITMQ_HOST', 'localhost'),
                        'port' => (int) env('RABBITMQ_PORT', 5672),
                        'user' => env('RABBITMQ_USER', 'guest'),
                        'password' => env('RABBITMQ_PASSWORD', 'guest'),
                        'vhost' => env('RABBITMQ_VHOST', '/'),
                    ],
                ],
                'options' => [
                    'heartbeat' => 2,
                    'connection_timeout' => 5,
                    'read_timeout' => 10,
                    'write_timeout' => 10,
                    'channel_rpc_timeout' => 5,
                ],
                'ssl' => ['enabled' => false],
            ],
        ],
    ]);

    $connection = $connectionManager->connection();
    $heartbeatSender = heartbeatIntegrationConsumer()->heartbeatSenderFor($connection);
    $heartbeatSender->register();

    try {
        blockForHeartbeatTestSeconds(5);

        $channel = $connection->channel();
        $channel->queue_declare('heartbeat-survival-test', false, false, false, true);
        $channel->queue_delete('heartbeat-survival-test');
        $channel->close();

        expect($connection->isConnected())->toBeTrue();
    } finally {
        $heartbeatSender->unregister();
        $connectionManager->disconnectAll();
    }
});

function blockForHeartbeatTestSeconds(int $seconds): void
{
    $deadline = microtime(true) + $seconds;

    while (microtime(true) < $deadline) {
        usleep(100_000);
    }
}

function heartbeatIntegrationConsumer(): Consumer
{
    return new class(Mockery::mock(ChannelManager::class), Mockery::mock(AttributeScanner::class), Mockery::mock(RabbitMQQueue::class), Mockery::mock(ExceptionHandler::class), Mockery::mock(Dispatcher::class)) extends Consumer
    {
        public function heartbeatSenderFor(AbstractConnection $connection): AbstractSignalHeartbeatSender
        {
            return $this->createHeartbeatSender($connection);
        }
    };
}

function canConnectToRabbitMQForHeartbeatTest(): bool
{
    $socket = @fsockopen(
        env('RABBITMQ_HOST', 'localhost'),
        (int) env('RABBITMQ_PORT', 5672),
        $errorCode,
        $errorMessage,
        2,
    );

    if ($socket === false) {
        return false;
    }

    fclose($socket);

    return true;
}
