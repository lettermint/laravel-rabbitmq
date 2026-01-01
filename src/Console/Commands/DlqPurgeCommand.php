<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Console\Commands;

use Carbon\CarbonInterval;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Artisan command to purge messages from a dead letter queue.
 *
 * This command permanently deletes messages from a DLQ. Use with caution
 * as deleted messages cannot be recovered.
 */
class DlqPurgeCommand extends Command
{
    protected $signature = 'rabbitmq:dlq-purge
        {queue : The original queue name (not the DLQ name)}
        {--id= : Purge a specific message by ID}
        {--older-than= : Only purge messages older than this duration (e.g., 24h, 7d, 1w)}
        {--dry-run : Show what would be purged without making changes}
        {--force : Skip confirmation prompt}';

    protected $description = 'Purge messages from a dead letter queue (permanently deletes messages)';

    public function handle(
        ChannelManager $channelManager,
        AttributeScanner $scanner
    ): int {
        $queueName = $this->argument('queue');
        $targetId = $this->option('id');
        $olderThan = $this->option('older-than');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        // Find the queue configuration
        $topology = $scanner->getTopology();

        if (! isset($topology['queues'][$queueName])) {
            $this->showQueueNotFoundError($queueName, $topology);

            return self::FAILURE;
        }

        $attribute = $topology['queues'][$queueName]['attribute'];
        $dlqQueueName = $attribute->getDlqQueueName();

        // Parse older-than duration if provided
        $cutoffTime = null;
        if ($olderThan !== null) {
            try {
                $interval = CarbonInterval::make($olderThan);
                if ($interval === null) {
                    $this->components->error("Invalid duration format: '{$olderThan}'. Use formats like 24h, 7d, 1w, 30m");

                    return self::FAILURE;
                }
                $cutoffTime = Carbon::now()->sub($interval);
                $this->components->info("Will purge messages older than: {$cutoffTime->format('Y-m-d H:i:s')}");
            } catch (\Exception $e) {
                $this->components->error("Invalid duration format: '{$olderThan}'. Use formats like 24h, 7d, 1w, 30m");

                return self::FAILURE;
            }
        }

        // Confirmation prompt unless --force or --dry-run
        if (! $dryRun && ! $force) {
            $confirmMessage = $targetId !== null
                ? "Are you sure you want to permanently delete message '{$targetId}' from DLQ?"
                : "Are you sure you want to permanently delete messages from '{$dlqQueueName}'?";

            if (! $this->confirm($confirmMessage)) {
                $this->components->info('Operation cancelled');

                return self::SUCCESS;
            }
        }

        if ($dryRun) {
            $this->components->warn('Dry run mode - no messages will be deleted');
        }

        $this->components->info("Purging DLQ: {$dlqQueueName}");

        try {
            $channel = $channelManager->channel('dlq-purge');

            // Handle specific message ID
            if ($targetId !== null) {
                return $this->purgeById($channel, $dlqQueueName, $targetId, $dryRun);
            }

            // Handle bulk purge (optionally filtered by age)
            return $this->purgeBulk($channel, $dlqQueueName, $cutoffTime, $dryRun);
        } catch (\Exception $e) {
            $this->components->error("Failed to purge DLQ: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * Purge a specific message by ID.
     *
     * Uses "fetch all, then process" pattern to avoid the issue where
     * basic_reject(requeue=true) puts messages at the front of the queue.
     */
    protected function purgeById(
        AMQPChannel $channel,
        string $dlqQueueName,
        string $targetId,
        bool $dryRun
    ): int {
        $checked = 0;
        $maxMessages = 10000; // Safety limit
        $otherMessages = [];
        $targetMessage = null;
        $targetPayload = null;

        // Fetch messages until we find the target (hold all unacked)
        while ($checked < $maxMessages) {
            $message = $channel->basic_get($dlqQueueName, false);

            if ($message === null) {
                break;
            }

            $payload = json_decode($message->getBody(), true);
            $messageId = $payload['uuid'] ?? $payload['id'] ?? null;

            if ($messageId === $targetId) {
                $targetMessage = $message;
                $targetPayload = $payload;
                break;
            }

            // Not the one we want - hold for later requeueing
            $otherMessages[] = $message;
            $checked++;
        }

        // Requeue all non-matching messages first
        foreach ($otherMessages as $msg) {
            $channel->basic_reject($msg->getDeliveryTag(), true);
        }

        // Handle target message
        if ($targetMessage === null) {
            $this->components->error("Message with ID '{$targetId}' not found in DLQ");

            return self::FAILURE;
        }

        if ($dryRun) {
            $jobName = $targetPayload['displayName'] ?? 'Unknown';
            $this->line("  Would purge: {$jobName} (ID: {$targetId})");
            $channel->basic_reject($targetMessage->getDeliveryTag(), true);
            $this->newLine();
            $this->components->info('1 message would be purged');
        } else {
            $channel->basic_ack($targetMessage->getDeliveryTag());

            Log::info('DLQ message purged', [
                'dlq_queue' => $dlqQueueName,
                'message_id' => $targetId,
                'job_class' => $targetPayload['displayName'] ?? 'Unknown',
            ]);

            $this->components->success("Message '{$targetId}' purged from DLQ");
        }

        return self::SUCCESS;
    }

    /**
     * Purge messages in bulk, optionally filtered by age.
     *
     * Uses "fetch all, then process" pattern to avoid the issue where
     * basic_reject(requeue=true) puts messages at the front of the queue.
     */
    protected function purgeBulk(
        AMQPChannel $channel,
        string $dlqQueueName,
        ?Carbon $cutoffTime,
        bool $dryRun
    ): int {
        $maxMessages = 100000; // Safety limit

        // Fetch all messages first (hold all unacked)
        $fetchedMessages = [];
        while (count($fetchedMessages) < $maxMessages) {
            $message = $channel->basic_get($dlqQueueName, false);

            if ($message === null) {
                break;
            }

            $fetchedMessages[] = $message;
        }

        // Categorize messages into purge vs skip
        $toPurge = [];
        $toSkip = [];

        foreach ($fetchedMessages as $message) {
            $payload = json_decode($message->getBody(), true) ?? [];

            // Check age filter if provided
            if ($cutoffTime !== null) {
                $messageTime = $this->getMessageTime($message);

                if ($messageTime !== null && $messageTime->isAfter($cutoffTime)) {
                    // Message is newer than cutoff - skip it
                    $toSkip[] = ['message' => $message, 'payload' => $payload];

                    continue;
                }
            }

            $toPurge[] = ['message' => $message, 'payload' => $payload];
        }

        // Display what would be purged (dry run) or actually purge
        foreach ($toPurge as $item) {
            $jobName = $item['payload']['displayName'] ?? 'Unknown';

            if ($dryRun) {
                $this->line("  Would purge: {$jobName}");
            } elseif ($this->getOutput()->isVerbose()) {
                $this->line("  <fg=red>x</> Purged: {$jobName}");
            }
        }

        // Process all messages at the end
        foreach ($toSkip as $item) {
            $channel->basic_reject($item['message']->getDeliveryTag(), true);
        }

        foreach ($toPurge as $item) {
            if ($dryRun) {
                $channel->basic_reject($item['message']->getDeliveryTag(), true);
            } else {
                $channel->basic_ack($item['message']->getDeliveryTag());
            }
        }

        $purged = count($toPurge);
        $skipped = count($toSkip);

        $this->newLine();

        if ($dryRun) {
            $this->components->info("{$purged} message(s) would be purged");
            if ($skipped > 0) {
                $this->components->info("{$skipped} message(s) would be skipped (newer than cutoff)");
            }
        } else {
            if ($purged > 0) {
                Log::info('DLQ bulk purge completed', [
                    'dlq_queue' => $dlqQueueName,
                    'purged_count' => $purged,
                    'skipped_count' => $skipped,
                ]);
            }

            $this->components->success("{$purged} message(s) purged from DLQ");
            if ($skipped > 0) {
                $this->components->info("{$skipped} message(s) skipped (newer than cutoff)");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Get the timestamp of a message from x-death header or message properties.
     */
    protected function getMessageTime(AMQPMessage $message): ?Carbon
    {
        // Try x-death header first (when the message failed)
        $headers = $message->has('application_headers')
            ? $message->get('application_headers')->getNativeData()
            : [];

        $xDeath = $headers['x-death'][0] ?? null;

        if (isset($xDeath['time'])) {
            $timestamp = $xDeath['time'];
            if (is_object($timestamp) && method_exists($timestamp, 'getTimestamp')) {
                return Carbon::createFromTimestamp($timestamp->getTimestamp());
            }
            if (is_numeric($timestamp)) {
                return Carbon::createFromTimestamp($timestamp);
            }
        }

        // Fall back to message timestamp property
        if ($message->has('timestamp')) {
            $timestamp = $message->get('timestamp');
            if (is_numeric($timestamp)) {
                return Carbon::createFromTimestamp($timestamp);
            }
        }

        return null;
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
}
