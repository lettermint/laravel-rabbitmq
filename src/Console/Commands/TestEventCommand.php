<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Exceptions\ConnectionException;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Artisan command to send a test event to RabbitMQ.
 *
 * This command is useful for verifying RabbitMQ connectivity and
 * testing the publish/consume flow manually.
 */
class TestEventCommand extends Command
{
    protected $signature = 'rabbitmq:test-event
        {queue=test-events : The queue to publish the test event to}
        {--message= : Custom message content}
        {--consume : Also consume and verify the message}
        {--json : Output as JSON}';

    protected $description = 'Send a test event to RabbitMQ and optionally consume it';

    public function handle(ChannelManager $channelManager): int
    {
        $queue = $this->argument('queue');
        $customMessage = $this->option('message');
        $shouldConsume = $this->option('consume');
        $jsonOutput = $this->option('json');

        $messageId = Str::uuid()->toString();
        $timestamp = now()->toIso8601String();

        // When consuming, use a temporary exclusive queue to avoid conflicts with workers
        $useTemporaryQueue = $shouldConsume;
        $temporaryQueue = null;

        $payload = [
            'uuid' => $messageId,
            'type' => 'test_event',
            'message' => $customMessage ?? 'Test event from rabbitmq:test-event command',
            'timestamp' => $timestamp,
            'metadata' => [
                'source' => 'rabbitmq:test-event',
                'environment' => app()->environment(),
            ],
        ];

        try {
            $topologyChannel = $channelManager->topologyChannel();

            if ($useTemporaryQueue) {
                // Create a temporary exclusive queue for round-trip testing
                // This avoids conflicts with workers consuming from the target queue
                $temporaryQueue = 'test-event-'.Str::random(8);
                $topologyChannel->queue_declare(
                    $temporaryQueue,
                    false,  // passive
                    false,  // durable (temporary queue)
                    true,   // exclusive (only this connection)
                    true    // auto_delete (deleted when connection closes)
                );
                $publishQueue = $temporaryQueue;
            } else {
                // Declare the target queue if it doesn't exist
                $topologyChannel->queue_declare(
                    $queue,
                    false,  // passive
                    true,   // durable
                    false,  // exclusive
                    false   // auto_delete
                );
                $publishQueue = $queue;
            }

            // Publish the message
            $publishChannel = $channelManager->publishChannel();

            $message = new AMQPMessage(json_encode($payload), [
                'delivery_mode' => $useTemporaryQueue
                    ? AMQPMessage::DELIVERY_MODE_NON_PERSISTENT
                    : AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'content_type' => 'application/json',
                'message_id' => $messageId,
                'timestamp' => time(),
            ]);

            $publishChannel->basic_publish($message, '', $publishQueue);

            $result = [
                'success' => true,
                'action' => 'published',
                'queue' => $useTemporaryQueue ? $temporaryQueue : $queue,
                'message_id' => $messageId,
                'timestamp' => $timestamp,
            ];

            if (! $jsonOutput) {
                $this->components->info('Test event published to RabbitMQ');
                $this->newLine();
                $this->line("  <fg=gray>Queue:</> {$publishQueue}".($useTemporaryQueue ? ' (temporary)' : ''));
                $this->line("  <fg=gray>Message ID:</> {$messageId}");
                $this->line("  <fg=gray>Timestamp:</> {$timestamp}");
            }

            // Consume and verify from the temporary queue
            if ($shouldConsume) {
                $consumeResult = $this->consumeAndVerify($channelManager, $publishQueue, $messageId, $jsonOutput);

                if (! $consumeResult['success']) {
                    $result['success'] = false;
                    $result['consume_error'] = $consumeResult['error'] ?? 'Unknown error';

                    if ($jsonOutput) {
                        $this->line(json_encode($result, JSON_PRETTY_PRINT));
                    }

                    return self::FAILURE;
                }

                $result['consumed'] = true;
                $result['round_trip_ms'] = $consumeResult['round_trip_ms'] ?? null;
            }

            if ($jsonOutput) {
                $this->line(json_encode($result, JSON_PRETTY_PRINT));
            } else {
                $this->newLine();
                $this->components->success('Test event sent successfully');
            }

            return self::SUCCESS;
        } catch (ConnectionException $e) {
            $error = [
                'success' => false,
                'error' => $e->getMessage(),
            ];

            if ($jsonOutput) {
                $this->line(json_encode($error, JSON_PRETTY_PRINT));
            } else {
                $this->components->error("Failed to send test event: {$e->getMessage()}");
            }

            return self::FAILURE;
        }
    }

    /**
     * Consume a message from the queue and verify it matches the sent message.
     *
     * @return array{success: bool, error?: string, round_trip_ms?: float}
     */
    protected function consumeAndVerify(
        ChannelManager $channelManager,
        string $queue,
        string $expectedMessageId,
        bool $jsonOutput
    ): array {
        $startTime = microtime(true);

        try {
            $consumeChannel = $channelManager->consumeChannel();

            // Try to get the message with a short timeout
            $maxAttempts = 10;
            $receivedMessage = null;

            for ($i = 0; $i < $maxAttempts; $i++) {
                $receivedMessage = $consumeChannel->basic_get($queue, false);

                if ($receivedMessage !== null) {
                    break;
                }

                usleep(100000); // 100ms
            }

            if ($receivedMessage === null) {
                return [
                    'success' => false,
                    'error' => 'No message received within timeout',
                ];
            }

            $payload = json_decode($receivedMessage->getBody(), true);
            $receivedId = $payload['uuid'] ?? null;

            if ($receivedId !== $expectedMessageId) {
                // Acknowledge and report mismatch
                $consumeChannel->basic_ack($receivedMessage->getDeliveryTag());

                return [
                    'success' => false,
                    'error' => "Message ID mismatch: expected {$expectedMessageId}, got {$receivedId}",
                ];
            }

            // Acknowledge the message
            $consumeChannel->basic_ack($receivedMessage->getDeliveryTag());

            $roundTripMs = (microtime(true) - $startTime) * 1000;

            if (! $jsonOutput) {
                $this->newLine();
                $this->components->info('Message consumed and verified');
                $this->line(sprintf('  <fg=gray>Round-trip time:</> %.2f ms', $roundTripMs));
            }

            return [
                'success' => true,
                'round_trip_ms' => round($roundTripMs, 2),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
