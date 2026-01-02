<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Artisan command to replay messages from a dead letter queue.
 *
 * This command moves messages from a DLQ back to their original queue
 * for reprocessing. Uses transactions to ensure atomic publish+ack
 * preventing message loss or duplication.
 */
class ReplayDlqCommand extends Command
{
    protected $signature = 'rabbitmq:replay-dlq
        {queue : The original queue name (not the DLQ name)}
        {--id= : Replay a specific message by ID}
        {--limit=0 : Maximum number of messages to replay (0 = all)}
        {--rate=0 : Maximum messages per second (0 = unlimited)}
        {--batch=0 : Process in batches of N messages with 1s pause between (0 = no batching)}
        {--dry-run : Show what would be replayed without making changes}';

    protected $description = 'Replay messages from a dead letter queue back to the original queue';

    public function handle(
        ChannelManager $channelManager,
        AttributeScanner $scanner,
        RabbitMQQueue $rabbitmq
    ): int {
        $queueName = $this->argument('queue');
        $targetId = $this->option('id');
        $limit = (int) $this->option('limit');
        $rate = (int) $this->option('rate');
        $batchSize = (int) $this->option('batch');
        $dryRun = (bool) $this->option('dry-run');

        // Find the queue configuration
        $topology = $scanner->getTopology();

        if (! isset($topology['queues'][$queueName])) {
            $this->showQueueNotFoundError($queueName, $topology);

            return self::FAILURE;
        }

        $attribute = $topology['queues'][$queueName]['attribute'];
        $dlqQueueName = $attribute->getDlqQueueName();

        $this->components->info("Replaying messages from DLQ: {$dlqQueueName}");

        if ($dryRun) {
            $this->components->warn('Dry run mode - no messages will be moved');
        }

        try {
            // Use a dedicated channel for replay operations
            $channel = $channelManager->channel('replay');

            // Handle specific message ID
            if ($targetId !== null) {
                return $this->replayById($channel, $channelManager, $dlqQueueName, $queueName, $targetId, $rabbitmq, $dryRun);
            }

            // Get message count for progress bar (if not dry run and no limit)
            $totalMessages = $this->getQueueMessageCount($channel, $dlqQueueName);

            // Handle bulk replay with rate limiting
            return $this->replayBulk(
                $channel,
                $channelManager,
                $dlqQueueName,
                $queueName,
                $rabbitmq,
                $limit,
                $rate,
                $batchSize,
                $dryRun,
                $totalMessages
            );
        } catch (\Exception $e) {
            $this->components->error("Failed to replay DLQ: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * Replay a specific message by ID.
     *
     * Uses "fetch all, then process" pattern to avoid the issue where
     * basic_reject(requeue=true) puts messages at the front of the queue.
     */
    protected function replayById(
        AMQPChannel $channel,
        ChannelManager $channelManager,
        string $dlqQueueName,
        string $queueName,
        string $targetId,
        RabbitMQQueue $rabbitmq,
        bool $dryRun
    ): int {
        $result = $this->findMessageById($channel, $dlqQueueName, $targetId);

        // Requeue all non-matching messages first
        foreach ($result['others'] as $msg) {
            $channel->basic_reject($msg->getDeliveryTag(), true);
        }

        if ($result['target'] === null) {
            $this->components->error("Message with ID '{$targetId}' not found in DLQ");

            return self::FAILURE;
        }

        $message = $result['target'];
        $payload = json_decode($message->getBody(), true);
        $jobName = $payload['displayName'] ?? 'Unknown';

        if ($dryRun) {
            $this->line("  Would replay: {$jobName} (ID: {$targetId})");
            $channel->basic_reject($message->getDeliveryTag(), true);
            $this->newLine();
            $this->components->info('1 message would be replayed');

            return self::SUCCESS;
        }

        try {
            $channel->tx_select();
            $preparedPayload = $this->preparePayloadForReplay($message);
            $rabbitmq->pushRaw($preparedPayload, $queueName);
            $channel->basic_ack($message->getDeliveryTag());
            $channel->tx_commit();

            $newAttempts = json_decode($preparedPayload, true)['attempts'] ?? 'unknown';
            Log::info('DLQ message replayed', [
                'queue' => $queueName,
                'dlq_queue' => $dlqQueueName,
                'message_id' => $targetId,
                'job_class' => $jobName,
                'attempts' => $newAttempts,
            ]);

            $this->components->success("Message '{$targetId}' replayed to '{$queueName}' (attempt #{$newAttempts})");

            return self::SUCCESS;
        } catch (\Exception $e) {
            try {
                $channel->tx_rollback();
            } catch (\Exception $rollbackException) {
                // Channel may be closed
            }

            // Requeue the message so it's not lost
            try {
                $channel->basic_reject($message->getDeliveryTag(), true);
            } catch (\Exception $rejectException) {
                // Message will be requeued automatically when channel closes
            }

            Log::error('DLQ replay failed for specific message', [
                'queue' => $queueName,
                'message_id' => $targetId,
                'error' => $e->getMessage(),
            ]);

            $this->components->error("Failed to replay message: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * Replay messages in bulk with rate limiting and batch support.
     *
     * Uses "fetch all, then process" pattern for dry-run mode to avoid
     * the issue where basic_reject(requeue=true) puts messages at the front.
     */
    protected function replayBulk(
        AMQPChannel $channel,
        ChannelManager $channelManager,
        string $dlqQueueName,
        string $queueName,
        RabbitMQQueue $rabbitmq,
        int $limit,
        int $rate,
        int $batchSize,
        bool $dryRun,
        int $totalMessages
    ): int {
        $replayed = 0;
        $failed = 0;
        $batchCount = 0;

        // Calculate delay between messages for rate limiting (in microseconds)
        $delayMicroseconds = $rate > 0 ? (int) (1_000_000 / $rate) : 0;

        // Determine effective limit
        $effectiveLimit = $limit > 0 ? min($limit, $totalMessages) : $totalMessages;

        // For dry-run, use "fetch all, then reject all" pattern
        if ($dryRun) {
            $fetchedMessages = [];
            $maxFetch = $limit > 0 ? $limit : 100000;

            while (count($fetchedMessages) < $maxFetch) {
                $message = $channel->basic_get($dlqQueueName, false);

                if ($message === null) {
                    break;
                }

                $fetchedMessages[] = $message;
            }

            // Display what would be replayed
            foreach ($fetchedMessages as $message) {
                $payload = json_decode($message->getBody(), true);
                $jobName = $payload['displayName'] ?? 'Unknown';
                $this->line("  Would replay: {$jobName}");
            }

            // Requeue all messages at the end
            foreach ($fetchedMessages as $message) {
                $channel->basic_reject($message->getDeliveryTag(), true);
            }

            $this->newLine();
            $this->components->info('Found '.count($fetchedMessages).' message(s) to replay');

            return self::SUCCESS;
        }

        // Non-dry-run: process messages one by one with rate limiting
        $progressBar = null;
        if ($effectiveLimit > 0 && ! $this->getOutput()->isQuiet()) {
            $progressBar = $this->output->createProgressBar($effectiveLimit);
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %message%');
            $progressBar->setMessage('Starting...');
            $progressBar->start();
        }

        $processed = 0;
        while (true) {
            if ($limit > 0 && $processed >= $limit) {
                break;
            }

            // Use basic_get to fetch a message from the DLQ
            $message = $channel->basic_get($dlqQueueName, false);

            if ($message === null) {
                break;
            }

            $processed++;
            $payload = json_decode($message->getBody(), true);
            $jobName = $payload['displayName'] ?? 'Unknown';

            // Use transaction for atomic publish+ack
            try {
                $channel->tx_select();

                // Prepare payload with updated attempts count
                $preparedPayload = $this->preparePayloadForReplay($message);

                // Republish to original queue
                $rabbitmq->pushRaw($preparedPayload, $queueName);

                // Acknowledge the DLQ message
                $channel->basic_ack($message->getDeliveryTag());

                // Commit both operations atomically
                $channel->tx_commit();

                $replayed++;
                $newAttempts = json_decode($preparedPayload, true)['attempts'] ?? '?';

                if ($progressBar !== null) {
                    $progressBar->setMessage("{$jobName} (#{$newAttempts})");
                    $progressBar->advance();
                } elseif ($this->getOutput()->isVerbose()) {
                    $this->line("  <fg=green>✓</> Replayed: {$jobName} (attempt #{$newAttempts})");
                }
            } catch (\Exception $e) {
                // Rollback on any failure
                try {
                    $channel->tx_rollback();
                } catch (\Exception $rollbackException) {
                    // Channel may be closed, need to get a fresh one
                    $channel = $channelManager->channel('replay');
                }

                $failed++;

                Log::error('DLQ replay failed for message', [
                    'queue' => $queueName,
                    'dlq_queue' => $dlqQueueName,
                    'job_name' => $jobName,
                    'error' => $e->getMessage(),
                ]);

                if ($progressBar !== null) {
                    $progressBar->setMessage("<fg=red>Failed: {$jobName}</>");
                    $progressBar->advance();
                } elseif ($this->getOutput()->isVerbose()) {
                    $this->line("  <fg=red>✗</> Failed: {$jobName} - {$e->getMessage()}");
                }

                // Requeue the message so it stays in DLQ
                try {
                    $channel->basic_reject($message->getDeliveryTag(), true);
                } catch (\Exception $rejectException) {
                    // Message will be requeued automatically when channel closes
                }
            }

            // Apply rate limiting
            if ($delayMicroseconds > 0 && $replayed < $effectiveLimit) {
                usleep($delayMicroseconds);
            }

            // Apply batch pausing
            if ($batchSize > 0) {
                $batchCount++;
                if ($batchCount >= $batchSize) {
                    $batchCount = 0;
                    if ($progressBar !== null) {
                        $progressBar->setMessage('Batch pause...');
                    }
                    sleep(1);
                }
            }
        }

        if ($progressBar !== null) {
            $progressBar->finish();
            $this->newLine(2);
        } else {
            $this->newLine();
        }

        $this->components->success("Replayed {$replayed} message(s) from DLQ to '{$queueName}'");

        if ($failed > 0) {
            $this->components->warn("Failed to replay {$failed} message(s) - see logs for details");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Find a message by its ID in the DLQ.
     *
     * Fetches messages without rejecting them, returning both the target
     * and all other messages so they can be properly requeued by the caller.
     *
     * @return array{target: AMQPMessage|null, others: array<AMQPMessage>}
     */
    protected function findMessageById(
        AMQPChannel $channel,
        string $dlqName,
        string $targetId
    ): array {
        $checked = 0;
        $maxMessages = 10000; // Safety limit
        $otherMessages = [];
        $targetMessage = null;

        while ($checked < $maxMessages) {
            $message = $channel->basic_get($dlqName, false);

            if ($message === null) {
                break;
            }

            $payload = json_decode($message->getBody(), true);
            $messageId = $payload['uuid'] ?? $payload['id'] ?? null;

            if ($messageId === $targetId) {
                $targetMessage = $message;
                break;
            }

            // Not the one we want - hold for later requeueing
            $otherMessages[] = $message;
            $checked++;
        }

        return [
            'target' => $targetMessage,
            'others' => $otherMessages,
        ];
    }

    /**
     * Get the message count for a queue using passive queue_declare.
     */
    protected function getQueueMessageCount(AMQPChannel $channel, string $queueName): int
    {
        try {
            // Passive declare returns [queue_name, message_count, consumer_count]
            [$name, $messageCount, $consumerCount] = $channel->queue_declare(
                $queueName,
                true,   // passive - don't create, just check
                false,  // durable
                false,  // exclusive
                false   // auto_delete
            );

            return $messageCount;
        } catch (\Exception $e) {
            // Queue might not exist or other error - return 0
            return 0;
        }
    }

    /**
     * Show error message when queue is not found, with available queues list.
     *
     * @param  array<string, mixed>  $topology
     */
    protected function showQueueNotFoundError(string $queueName, array $topology): void
    {
        $this->components->error("Queue '{$queueName}' not found in topology");
        $this->newLine();

        if (! empty($topology['queues'])) {
            $this->components->info('Available queues:');
            foreach (array_keys($topology['queues']) as $name) {
                $this->line("  - {$name}");
            }
        } else {
            $this->components->warn('No queues discovered. Run attribute scanning first.');
        }
    }

    /**
     * Prepare message payload for replay by injecting the attempts count.
     *
     * When replaying from DLQ, we need to preserve the attempt count because
     * x-death headers are lost when using pushRaw(). This injects the attempts
     * directly into the payload so RabbitMQJob::attempts() can read it.
     */
    protected function preparePayloadForReplay(AMQPMessage $message): string
    {
        $payload = json_decode($message->getBody(), true) ?? [];
        $currentAttempts = $this->getAttemptsFromMessage($message);

        // Increment attempts for the upcoming retry
        $payload['attempts'] = $currentAttempts + 1;

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * Get the current attempts count from a DLQ message.
     *
     * Checks both the payload (for previously replayed messages) and
     * x-death headers (for fresh DLQ arrivals).
     */
    protected function getAttemptsFromMessage(AMQPMessage $message): int
    {
        $payload = json_decode($message->getBody(), true) ?? [];

        // Check payload first (for previously replayed messages)
        if (isset($payload['attempts']) && is_int($payload['attempts'])) {
            return $payload['attempts'];
        }

        // Fall back to x-death headers
        $headers = $message->has('application_headers')
            ? $message->get('application_headers')
            : null;

        if ($headers instanceof AMQPTable) {
            $nativeHeaders = $headers->getNativeData();

            if (isset($nativeHeaders['x-death']) && is_array($nativeHeaders['x-death'])) {
                $totalCount = 0;
                foreach ($nativeHeaders['x-death'] as $death) {
                    $deathData = $death instanceof AMQPTable ? $death->getNativeData() : $death;
                    $totalCount += (int) ($deathData['count'] ?? 0);
                }

                return $totalCount + 1;
            }
        }

        return 1;
    }
}
