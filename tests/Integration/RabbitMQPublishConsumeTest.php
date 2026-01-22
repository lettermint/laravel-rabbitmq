<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Connection\ConnectionManager;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;
use PhpAmqpLib\Message\AMQPMessage;

pest()->group('integration');

/**
 * Integration tests for RabbitMQ publish/consume flow.
 *
 * These tests require a running RabbitMQ instance and are only run
 * in CI environments where the RabbitMQ service is available.
 */
describe('RabbitMQ Publish/Consume Integration', function () {
    beforeEach(function () {
        // Skip if RabbitMQ is not available
        if (! canConnectToRabbitMQ()) {
            $this->markTestSkipped('RabbitMQ is not available');
        }

        // Configure RabbitMQ connection from environment
        config([
            'rabbitmq.connections.default.hosts' => [
                [
                    'host' => env('RABBITMQ_HOST', 'localhost'),
                    'port' => (int) env('RABBITMQ_PORT', 5672),
                    'user' => env('RABBITMQ_USER', 'guest'),
                    'password' => env('RABBITMQ_PASSWORD', 'guest'),
                    'vhost' => env('RABBITMQ_VHOST', '/'),
                ],
            ],
        ]);
    });

    afterEach(function () {
        // Clean up: delete test queue if it exists
        try {
            $channelManager = app(ChannelManager::class);
            $channel = $channelManager->topologyChannel();
            $channel->queue_delete('integration-test-queue');
        } catch (\Throwable $e) {
            // Queue may not exist, ignore
        }

        // Close connections
        try {
            $connectionManager = app(ConnectionManager::class);
            $connectionManager->disconnect();
        } catch (\Throwable $e) {
            // Ignore cleanup errors
        }
    });

    it('can publish a message and consume it from RabbitMQ', function () {
        // Arrange: Set up a simple queue
        $channelManager = app(ChannelManager::class);
        $channel = $channelManager->topologyChannel();

        // Declare a simple queue for testing
        $channel->queue_declare(
            'integration-test-queue',
            false,  // passive
            true,   // durable
            false,  // exclusive
            false   // auto_delete
        );

        // Bind to the default exchange (direct routing by queue name)
        // For this simple test, we'll publish directly to the default exchange

        $rabbitmqQueue = app(RabbitMQQueue::class);

        // Create a test payload
        $testPayload = json_encode([
            'uuid' => 'test-uuid-'.time(),
            'displayName' => 'TestJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [
                'commandName' => 'TestCommand',
                'command' => serialize((object) ['message' => 'Hello from integration test']),
            ],
            'attempts' => 0,
        ]);

        // Act: Publish the message
        $publishChannel = $channelManager->publishChannel();

        $message = new AMQPMessage($testPayload, [
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'content_type' => 'application/json',
        ]);

        $publishChannel->basic_publish($message, '', 'integration-test-queue');

        // Consume the message
        $consumeChannel = $channelManager->consumeChannel();
        $receivedMessage = $consumeChannel->basic_get('integration-test-queue', false);

        // Assert
        expect($receivedMessage)->not->toBeNull();
        expect($receivedMessage)->toBeInstanceOf(AMQPMessage::class);

        $receivedPayload = json_decode($receivedMessage->getBody(), true);
        expect($receivedPayload['uuid'])->toStartWith('test-uuid-');
        expect($receivedPayload['displayName'])->toBe('TestJob');

        // Acknowledge the message
        $consumeChannel->basic_ack($receivedMessage->getDeliveryTag());

        // Verify queue is now empty
        $queueInfo = $channel->queue_declare(
            'integration-test-queue',
            true,   // passive - just check
            false,
            false,
            false
        );

        expect($queueInfo[1])->toBe(0); // message count should be 0
    });

    it('can publish multiple messages and consume them in order', function () {
        // Arrange
        $channelManager = app(ChannelManager::class);
        $channel = $channelManager->topologyChannel();

        $channel->queue_declare(
            'integration-test-queue',
            false,
            true,
            false,
            false
        );

        // Act: Publish multiple messages
        $publishChannel = $channelManager->publishChannel();
        $messageIds = [];

        for ($i = 1; $i <= 3; $i++) {
            $payload = json_encode([
                'uuid' => "test-uuid-{$i}",
                'displayName' => "TestJob{$i}",
                'sequence' => $i,
            ]);

            $message = new AMQPMessage($payload, [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'content_type' => 'application/json',
            ]);

            $publishChannel->basic_publish($message, '', 'integration-test-queue');
            $messageIds[] = "test-uuid-{$i}";
        }

        // Consume all messages
        $consumeChannel = $channelManager->consumeChannel();
        $receivedIds = [];

        for ($i = 0; $i < 3; $i++) {
            $receivedMessage = $consumeChannel->basic_get('integration-test-queue', false);
            expect($receivedMessage)->not->toBeNull();

            $payload = json_decode($receivedMessage->getBody(), true);
            $receivedIds[] = $payload['uuid'];

            $consumeChannel->basic_ack($receivedMessage->getDeliveryTag());
        }

        // Assert messages received in order
        expect($receivedIds)->toBe($messageIds);
    });

    it('reports correct queue size after publishing', function () {
        // Arrange
        $channelManager = app(ChannelManager::class);
        $channel = $channelManager->topologyChannel();

        $channel->queue_declare(
            'integration-test-queue',
            false,
            true,
            false,
            false
        );

        // Act: Publish 5 messages with publisher confirms to ensure delivery
        $publishChannel = $channelManager->publishChannel();
        $publishChannel->confirm_select();

        for ($i = 1; $i <= 5; $i++) {
            $message = new AMQPMessage(json_encode(['id' => $i]), [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            ]);
            $publishChannel->basic_publish($message, '', 'integration-test-queue');
        }

        // Wait for all messages to be confirmed
        $publishChannel->wait_for_pending_acks(5.0);

        // Use RabbitMQQueue to check size
        $rabbitmqQueue = app(RabbitMQQueue::class);

        // Assert
        expect($rabbitmqQueue->size('integration-test-queue'))->toBe(5);

        // Clean up - consume all messages
        $consumeChannel = $channelManager->consumeChannel();
        for ($i = 0; $i < 5; $i++) {
            $msg = $consumeChannel->basic_get('integration-test-queue', true); // auto-ack
        }
    });

    it('handles connection to RabbitMQ service', function () {
        // This test verifies basic connectivity
        $connectionManager = app(ConnectionManager::class);
        $connection = $connectionManager->connection();

        expect($connection)->not->toBeNull();
        expect($connection->isConnected())->toBeTrue();
    });
});

/**
 * Check if RabbitMQ is available for integration tests.
 */
function canConnectToRabbitMQ(): bool
{
    $host = env('RABBITMQ_HOST', 'localhost');
    $port = (int) env('RABBITMQ_PORT', 5672);

    $socket = @fsockopen($host, $port, $errno, $errstr, 2);

    if ($socket !== false) {
        fclose($socket);

        return true;
    }

    return false;
}
