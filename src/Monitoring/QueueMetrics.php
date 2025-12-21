<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Monitoring;

use AMQPQueue;
use Illuminate\Support\Facades\Log;
use Lettermint\RabbitMQ\Connection\ChannelManager;

/**
 * Queue metrics service.
 *
 * Provides statistics about RabbitMQ queues.
 *
 * **Important**: The ext-amqp PHP extension does not expose detailed queue
 * statistics like message counts. For accurate metrics, use the RabbitMQ
 * Management API (HTTP) or implement a custom metrics collector.
 */
class QueueMetrics
{
    public function __construct(
        protected ChannelManager $channelManager,
    ) {}

    /**
     * Get statistics for a specific queue.
     *
     * Note: ext-amqp doesn't expose message counts from declareQueue().
     * Values are null to indicate data is unavailable (not zero).
     * Use RabbitMQ Management API for accurate statistics.
     *
     * @return array{messages: int|null, consumers: int|null, rate: float|null, connected: bool, notice: string|null, error: string|null}
     */
    public function getQueueStats(string $queueName): array
    {
        try {
            $channel = $this->channelManager->topologyChannel();
            $queue = new AMQPQueue($channel);
            $queue->setName($queueName);

            // ext-amqp doesn't expose count info from declareQueue()
            // Return null to clearly indicate data is unavailable (not zero)
            return [
                'messages' => null,
                'consumers' => null,
                'rate' => null,
                'connected' => true,
                'notice' => 'Queue statistics unavailable via ext-amqp. Use RabbitMQ Management API for accurate counts.',
                'error' => null,
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
     * ext-amqp does not provide queue statistics. This method always returns
     * false. Use RabbitMQ Management API for statistics availability.
     */
    public function hasStatistics(): bool
    {
        return false;
    }
}
