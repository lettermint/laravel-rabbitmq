<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;

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
        {--limit=0 : Maximum number of messages to replay (0 = all)}
        {--dry-run : Show what would be replayed without making changes}';

    protected $description = 'Replay messages from a dead letter queue back to the original queue';

    public function handle(
        ChannelManager $channelManager,
        AttributeScanner $scanner,
        RabbitMQQueue $rabbitmq
    ): int {
        $queueName = $this->argument('queue');
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        // Find the queue configuration
        $topology = $scanner->getTopology();

        if (! isset($topology['queues'][$queueName])) {
            $this->components->error("Queue '{$queueName}' not found in topology");

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

            $replayed = 0;
            $failed = 0;
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

                if ($dryRun) {
                    $this->line("  Would replay: {$jobName}");
                    // Reject with requeue to put it back
                    $channel->basic_reject($message->getDeliveryTag(), true);

                    continue;
                }

                // Use transaction for atomic publish+ack
                // This prevents message loss (ack fails) or duplication (publish fails after ack)
                try {
                    $channel->tx_select();

                    // Republish to original queue
                    $rabbitmq->pushRaw($message->getBody(), $queueName);

                    // Acknowledge the DLQ message
                    $channel->basic_ack($message->getDeliveryTag());

                    // Commit both operations atomically
                    $channel->tx_commit();

                    $replayed++;

                    if ($this->getOutput()->isVerbose()) {
                        $this->line("  <fg=green>✓</> Replayed: {$jobName}");
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

                    if ($this->getOutput()->isVerbose()) {
                        $this->line("  <fg=red>✗</> Failed: {$jobName} - {$e->getMessage()}");
                    }

                    // Reject with requeue so message stays in DLQ for retry
                    try {
                        $message = $channel->basic_get($dlqQueueName, false);
                        if ($message !== null) {
                            $channel->basic_reject($message->getDeliveryTag(), true);
                        }
                    } catch (\Exception $rejectException) {
                        // Message will be requeued automatically when channel closes
                    }
                }
            }

            $this->newLine();

            if ($dryRun) {
                $this->components->info("Found {$processed} message(s) to replay");
            } else {
                $this->components->success("Replayed {$replayed} message(s) from DLQ to '{$queueName}'");

                if ($failed > 0) {
                    $this->components->warn("Failed to replay {$failed} message(s) - see logs for details");
                }
            }

            return $failed > 0 ? self::FAILURE : self::SUCCESS;
        } catch (\Exception $e) {
            $this->components->error("Failed to replay DLQ: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
