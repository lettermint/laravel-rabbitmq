<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Monitoring;

use Illuminate\Support\Facades\Log;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;

/**
 * Queue metrics service.
 *
 * Provides statistics about RabbitMQ queues using php-amqplib's passive
 * queue declaration feature, which returns message and consumer counts.
 */
class QueueMetrics
{
    public function __construct(
        protected ChannelManager $channelManager,
    ) {}

    /**
     * Get statistics for a specific queue.
     *
     * Uses passive queue declaration to retrieve queue statistics from RabbitMQ.
     * php-amqplib exposes the full response from queue.declare including
     * message and consumer counts.
     *
     * @return array{messages: int|null, consumers: int|null, rate: float|null, connected: bool, notice: string|null, error: string|null}
     */
    public function getQueueStats(string $queueName): array
    {
        try {
            $channel = $this->channelManager->topologyChannel();

            // Passive declare returns [queue_name, message_count, consumer_count]
            [$name, $messageCount, $consumerCount] = $channel->queue_declare(
                $queueName,
                true,   // passive - don't create, just check
                false,  // durable (ignored for passive)
                false,  // exclusive (ignored for passive)
                false   // auto_delete (ignored for passive)
            );

            return [
                'messages' => $messageCount,
                'consumers' => $consumerCount,
                'rate' => null, // Rate requires time-series sampling
                'connected' => true,
                'notice' => null,
                'error' => null,
            ];
        } catch (AMQPProtocolChannelException $e) {
            // Queue doesn't exist (404) or other protocol error
            Log::warning('Failed to get queue stats: queue may not exist', [
                'queue' => $queueName,
                'error' => $e->getMessage(),
            ]);

            return [
                'messages' => null,
                'consumers' => null,
                'rate' => null,
                'connected' => false,
                'notice' => null,
                'error' => $e->getMessage(),
            ];
        } catch (\Exception $e) {
            Log::warning('Failed to get queue stats', [
                'queue' => $queueName,
                'error' => $e->getMessage(),
            ]);

            return [
                'messages' => null,
                'consumers' => null,
                'rate' => null,
                'connected' => false,
                'notice' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get statistics for all queues.
     *
     * @param  array<string>  $queueNames
     * @return array<string, array{messages: int|null, consumers: int|null, rate: float|null, connected: bool, notice: string|null, error: string|null}>
     */
    public function getAllQueueStats(array $queueNames): array
    {
        $stats = [];

        foreach ($queueNames as $queueName) {
            $stats[$queueName] = $this->getQueueStats($queueName);
        }

        return $stats;
    }

    /**
     * Check if queue statistics are available.
     *
     * php-amqplib provides queue statistics via passive queue declaration.
     * This returns true as the library supports retrieving message/consumer counts.
     */
    public function hasStatistics(): bool
    {
        return true;
    }
}
