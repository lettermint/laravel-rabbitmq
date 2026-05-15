<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Connection\ConnectionManager;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;
use PhpAmqpLib\Message\AMQPMessage;

pest()->group('integration');

describe('RabbitMQ publish and consume integration', function () {
    beforeEach(function () {
        if (! canConnectToRabbitMQ()) {
            $this->markTestSkipped('RabbitMQ is not available');
        }

        config()->set('rabbitmq.connections.default.hosts', [
            [
                'host' => env('RABBITMQ_HOST', 'localhost'),
                'port' => (int) env('RABBITMQ_PORT', 5672),
                'user' => env('RABBITMQ_USER', 'guest'),
                'password' => env('RABBITMQ_PASSWORD', 'guest'),
                'vhost' => env('RABBITMQ_VHOST', '/'),
            ],
        ]);
    });

    afterEach(function () {
        try {
            app(ChannelManager::class)->topologyChannel()->queue_delete('integration-test-queue');
        } catch (Throwable) {
            // The queue may not exist if a test failed before declaration.
        }

        try {
            app(ConnectionManager::class)->disconnect();
        } catch (Throwable) {
            // Ignore cleanup failures after integration tests.
        }
    });

    it('publishes and consumes one message', function () {
        $channelManager = app(ChannelManager::class);
        $topologyChannel = $channelManager->topologyChannel();
        $topologyChannel->queue_declare('integration-test-queue', false, true, false, false);

        $payload = json_encode([
            'uuid' => 'integration-test-uuid',
            'displayName' => 'IntegrationTestJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [
                'commandName' => 'IntegrationTestCommand',
                'command' => serialize((object) ['message' => 'hello']),
            ],
            'attempts' => 0,
        ], JSON_THROW_ON_ERROR);

        $channelManager->publishChannel()->basic_publish(
            new AMQPMessage($payload, [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'content_type' => 'application/json',
            ]),
            '',
            'integration-test-queue',
        );

        $receivedMessage = $channelManager->consumeChannel()->basic_get('integration-test-queue', false);

        expect($receivedMessage)->toBeInstanceOf(AMQPMessage::class);
        expect(json_decode($receivedMessage->getBody(), true)['uuid'])->toBe('integration-test-uuid');

        $channelManager->consumeChannel()->basic_ack($receivedMessage->getDeliveryTag());
    });

    it('reports queue size after confirmed publishes', function () {
        $channelManager = app(ChannelManager::class);
        $topologyChannel = $channelManager->topologyChannel();
        $topologyChannel->queue_declare('integration-test-queue', false, true, false, false);

        $publishChannel = $channelManager->publishChannel();
        $publishChannel->confirm_select();

        foreach (range(1, 5) as $id) {
            $publishChannel->basic_publish(
                new AMQPMessage(json_encode(['id' => $id], JSON_THROW_ON_ERROR), [
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'content_type' => 'application/json',
                ]),
                '',
                'integration-test-queue',
            );
        }

        $publishChannel->wait_for_pending_acks(5.0);

        expect(app(RabbitMQQueue::class)->size('integration-test-queue'))->toBe(5);
    });

    it('connects to the RabbitMQ service', function () {
        $connection = app(ConnectionManager::class)->connection();

        expect($connection->isConnected())->toBeTrue();
    });
});

function canConnectToRabbitMQ(): bool
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
