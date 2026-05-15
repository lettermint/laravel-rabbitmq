<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

/**
 * Artisan command to publish a diagnostic event to RabbitMQ.
 */
class TestEventCommand extends Command
{
    protected $signature = 'rabbitmq:test-event
        {queue=test-events : The queue to publish the test event to}
        {--message= : Custom message content}
        {--roundtrip : Publish to a temporary queue and consume it back}
        {--json : Output as JSON}';

    protected $description = 'Publish a RabbitMQ diagnostic event and optionally verify a round trip';

    public function handle(ChannelManager $channelManager): int
    {
        $queue = (string) $this->argument('queue');
        $roundtrip = (bool) $this->option('roundtrip');
        $jsonOutput = (bool) $this->option('json');
        $messageId = Str::uuid()->toString();
        $timestamp = now()->toIso8601String();

        $payload = [
            'uuid' => $messageId,
            'type' => 'rabbitmq_test_event',
            'message' => $this->option('message') ?: 'Test event from rabbitmq:test-event',
            'timestamp' => $timestamp,
            'metadata' => [
                'source' => 'rabbitmq:test-event',
                'environment' => app()->environment(),
            ],
        ];

        try {
            $publishQueue = $this->prepareQueue($channelManager, $queue, $roundtrip);

            $message = new AMQPMessage(json_encode($payload, JSON_THROW_ON_ERROR), [
                'delivery_mode' => $roundtrip
                    ? AMQPMessage::DELIVERY_MODE_NON_PERSISTENT
                    : AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'content_type' => 'application/json',
                'message_id' => $messageId,
                'timestamp' => time(),
            ]);

            $channelManager->publishChannel()->basic_publish($message, '', $publishQueue);

            $result = [
                'success' => true,
                'action' => 'published',
                'queue' => $publishQueue,
                'message_id' => $messageId,
                'timestamp' => $timestamp,
            ];

            if ($roundtrip) {
                $result = array_merge(
                    $result,
                    $this->consumeAndVerify($channelManager, $publishQueue, $messageId),
                );
            }

            $this->writeResult($result, $jsonOutput);

            return $result['success'] === true ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->writeResult([
                'success' => false,
                'error' => $e->getMessage(),
            ], $jsonOutput);

            return self::FAILURE;
        }
    }

    private function prepareQueue(ChannelManager $channelManager, string $queue, bool $roundtrip): string
    {
        $channel = $channelManager->topologyChannel();

        if ($roundtrip) {
            $temporaryQueue = 'rabbitmq-test-event-'.Str::random(8);

            $channel->queue_declare(
                $temporaryQueue,
                false,
                false,
                true,
                true,
            );

            return $temporaryQueue;
        }

        $channel->queue_declare(
            $queue,
            false,
            true,
            false,
            false,
        );

        return $queue;
    }

    /**
     * @return array{success: bool, consumed?: bool, round_trip_ms?: float, error?: string}
     */
    private function consumeAndVerify(ChannelManager $channelManager, string $queue, string $expectedMessageId): array
    {
        $startedAt = microtime(true);
        $channel = $channelManager->consumeChannel();

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $message = $channel->basic_get($queue, false);

            if ($message instanceof AMQPMessage) {
                $payload = json_decode($message->getBody(), true);
                $receivedId = is_array($payload) ? ($payload['uuid'] ?? null) : null;

                $channel->basic_ack($message->getDeliveryTag());

                if ($receivedId !== $expectedMessageId) {
                    return [
                        'success' => false,
                        'error' => "Message ID mismatch: expected {$expectedMessageId}, got {$receivedId}",
                    ];
                }

                return [
                    'success' => true,
                    'consumed' => true,
                    'round_trip_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                ];
            }

            usleep(100000);
        }

        return [
            'success' => false,
            'error' => 'No message received within timeout',
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function writeResult(array $result, bool $jsonOutput): void
    {
        if ($jsonOutput) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        if (($result['success'] ?? false) !== true) {
            $this->components->error((string) ($result['error'] ?? 'RabbitMQ test event failed'));

            return;
        }

        $this->components->success('RabbitMQ test event published');
        $this->line('  Queue: '.$result['queue']);
        $this->line('  Message ID: '.$result['message_id']);

        if (($result['consumed'] ?? false) === true) {
            $this->line('  Round trip: '.$result['round_trip_ms'].' ms');
        }
    }
}
