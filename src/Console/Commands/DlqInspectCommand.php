<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Artisan command to inspect messages in a dead letter queue.
 *
 * This command allows viewing DLQ messages without removing them,
 * useful for debugging failed jobs and understanding failure patterns.
 */
class DlqInspectCommand extends Command
{
    protected $signature = 'rabbitmq:dlq-inspect
        {queue : The original queue name (not the DLQ name)}
        {--id= : Inspect a specific message by ID}
        {--limit=10 : Maximum number of messages to display}
        {--format=table : Output format (table or json)}';

    protected $description = 'Inspect messages in a dead letter queue without removing them';

    public function handle(
        ChannelManager $channelManager,
        AttributeScanner $scanner
    ): int {
        $queueName = $this->argument('queue');
        $targetId = $this->option('id');
        $limit = (int) $this->option('limit');
        $format = $this->option('format');

        // Find the queue configuration
        $topology = $scanner->getTopology();

        if (! isset($topology['queues'][$queueName])) {
            $this->showQueueNotFoundError($queueName, $topology);

            return self::FAILURE;
        }

        $attribute = $topology['queues'][$queueName]['attribute'];
        $dlqQueueName = $attribute->getDlqQueueName();

        $this->components->info("Inspecting DLQ: {$dlqQueueName}");

        try {
            $channel = $channelManager->channel('dlq-inspect');
            $messages = [];
            $inspected = 0;

            // If looking for specific ID, search until found
            if ($targetId !== null) {
                $message = $this->findMessageById($channel, $dlqQueueName, $targetId);

                if ($message === null) {
                    $this->components->error("Message with ID '{$targetId}' not found in DLQ");

                    return self::FAILURE;
                }

                $messages[] = $this->extractMessageData($message);

                // Requeue the message after inspection
                $channel->basic_reject($message->getDeliveryTag(), true);
            } else {
                // Fetch up to limit messages
                while ($inspected < $limit) {
                    $message = $channel->basic_get($dlqQueueName, false);

                    if ($message === null) {
                        break;
                    }

                    $messages[] = $this->extractMessageData($message);
                    $inspected++;

                    // Requeue the message - we're just peeking
                    $channel->basic_reject($message->getDeliveryTag(), true);
                }
            }

            if (empty($messages)) {
                $this->components->info('No messages in DLQ');

                return self::SUCCESS;
            }

            $this->displayMessages($messages, $format);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->components->error("Failed to inspect DLQ: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * Find a message by its ID in the DLQ.
     *
     * Iterates through all messages, requeueing non-matches until the target is found.
     */
    protected function findMessageById(
        \PhpAmqpLib\Channel\AMQPChannel $channel,
        string $dlqName,
        string $targetId
    ): ?AMQPMessage {
        $checked = 0;
        $maxMessages = 10000; // Safety limit to prevent infinite loops

        while ($checked < $maxMessages) {
            $message = $channel->basic_get($dlqName, false);

            if ($message === null) {
                return null;
            }

            $payload = json_decode($message->getBody(), true);
            $messageId = $payload['uuid'] ?? $payload['id'] ?? null;

            if ($messageId === $targetId) {
                return $message;
            }

            // Not the one we want - requeue it
            $channel->basic_reject($message->getDeliveryTag(), true);
            $checked++;
        }

        return null;
    }

    /**
     * Extract relevant data from a message for display.
     *
     * @return array<string, mixed>
     */
    protected function extractMessageData(AMQPMessage $message): array
    {
        $payload = json_decode($message->getBody(), true) ?? [];

        // Parse x-death header for failure information
        $headers = $message->has('application_headers')
            ? $message->get('application_headers')->getNativeData()
            : [];

        $xDeath = $headers['x-death'][0] ?? null;
        $attempts = $xDeath['count'] ?? 1;
        $failedAt = null;
        $reason = $xDeath['reason'] ?? 'unknown';

        if (isset($xDeath['time'])) {
            // x-death time is an AMQPTimestamp object
            $timestamp = $xDeath['time'];
            if (is_object($timestamp) && method_exists($timestamp, 'getTimestamp')) {
                $failedAt = Carbon::createFromTimestamp($timestamp->getTimestamp());
            } elseif (is_numeric($timestamp)) {
                $failedAt = Carbon::createFromTimestamp($timestamp);
            }
        }

        // Extract exception information
        $exception = null;
        if (isset($payload['exception'])) {
            $exception = $payload['exception'];
        }

        return [
            'id' => $payload['uuid'] ?? $payload['id'] ?? 'unknown',
            'job_class' => $payload['displayName'] ?? $payload['job'] ?? 'Unknown',
            'attempts' => $attempts,
            'failed_at' => $failedAt,
            'reason' => $reason,
            'exception' => $exception,
            'payload' => $payload,
            'raw_body' => $message->getBody(),
        ];
    }

    /**
     * Display messages in the requested format.
     *
     * @param  array<array<string, mixed>>  $messages
     */
    protected function displayMessages(array $messages, string $format): void
    {
        if ($format === 'json') {
            $this->displayJson($messages);

            return;
        }

        $this->displayTable($messages);
    }

    /**
     * Display messages as a table.
     *
     * @param  array<array<string, mixed>>  $messages
     */
    protected function displayTable(array $messages): void
    {
        $rows = [];

        foreach ($messages as $msg) {
            $exceptionSummary = '';
            if ($msg['exception']) {
                $exceptionMessage = $msg['exception']['message'] ?? '';
                $exceptionSummary = mb_strlen($exceptionMessage) > 50
                    ? mb_substr($exceptionMessage, 0, 50).'...'
                    : $exceptionMessage;
            }

            $rows[] = [
                mb_substr($msg['id'], 0, 8),
                class_basename($msg['job_class']),
                $msg['attempts'],
                $msg['failed_at']?->format('Y-m-d H:i:s') ?? '-',
                $msg['reason'],
                $exceptionSummary ?: '-',
            ];
        }

        $this->table(
            ['ID', 'Job Class', 'Attempts', 'Failed At', 'Reason', 'Exception'],
            $rows
        );

        $this->newLine();
        $this->line("<fg=gray>Showing {$this->count($messages)} message(s). Use --format=json for full details.</>");
    }

    /**
     * Display messages as JSON.
     *
     * @param  array<array<string, mixed>>  $messages
     */
    protected function displayJson(array $messages): void
    {
        foreach ($messages as $msg) {
            $this->line(json_encode([
                'id' => $msg['id'],
                'job_class' => $msg['job_class'],
                'attempts' => $msg['attempts'],
                'failed_at' => $msg['failed_at']?->toIso8601String(),
                'reason' => $msg['reason'],
                'exception' => $msg['exception'],
                'payload' => $msg['payload'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $this->newLine();
        }
    }

    /**
     * Count items in array (helper for readability).
     *
     * @param  array<mixed>  $items
     */
    protected function count(array $items): int
    {
        return count($items);
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
