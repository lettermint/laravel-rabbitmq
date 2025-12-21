<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Console\Commands;

use AMQPQueue;
use Illuminate\Console\Command;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;

/**
 * Artisan command to replay messages from a dead letter queue.
 *
 * This command moves messages from a DLQ back to their original queue
 * for reprocessing.
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
            $channel = $channelManager->consumeChannel();
            $dlqQueue = new AMQPQueue($channel);
            $dlqQueue->setName($dlqQueueName);

            $replayed = 0;
            $processed = 0;

            while (true) {
                if ($limit > 0 && $processed >= $limit) {
                    break;
                }

                $envelope = $dlqQueue->get();

                if ($envelope === null) {
                    break;
                }

                $processed++;

                if ($dryRun) {
                    $payload = json_decode($envelope->getBody(), true);
                    $jobName = $payload['displayName'] ?? 'Unknown';
                    $this->line("  Would replay: {$jobName}");
                    $dlqQueue->reject($envelope->getDeliveryTag(), AMQP_REQUEUE);

                    continue;
                }

                // Republish to original queue
                $rabbitmq->pushRaw($envelope->getBody(), $queueName);

                // Acknowledge the DLQ message
                $dlqQueue->ack($envelope->getDeliveryTag());

                $replayed++;

                if ($this->getOutput()->isVerbose()) {
                    $payload = json_decode($envelope->getBody(), true);
                    $jobName = $payload['displayName'] ?? 'Unknown';
                    $this->line("  <fg=green>✓</> Replayed: {$jobName}");
                }
            }

            $this->newLine();

            if ($dryRun) {
                $this->components->info("Found {$processed} message(s) to replay");
            } else {
                $this->components->success("Replayed {$replayed} message(s) from DLQ to '{$queueName}'");
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->components->error("Failed to replay DLQ: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
