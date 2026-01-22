<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Console\Commands;

use Illuminate\Console\Command;
use Lettermint\RabbitMQ\Actions\Dlq\InspectDlqMessages;
use Lettermint\RabbitMQ\Actions\Dlq\Results\DlqMessageData;
use Lettermint\RabbitMQ\Exceptions\DlqOperationException;

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

    public function handle(InspectDlqMessages $inspectDlq): int
    {
        $queueName = $this->argument('queue');
        $targetId = $this->option('id');
        $limit = (int) $this->option('limit');
        $format = $this->option('format');

        $this->components->info("Inspecting DLQ for queue: {$queueName}");

        try {
            $result = $inspectDlq(
                queueName: $queueName,
                messageId: $targetId,
                limit: $limit,
            );
        } catch (DlqOperationException $e) {
            $this->showQueueNotFoundError($e);

            return self::FAILURE;
        } catch (\Exception $e) {
            $this->components->error("Failed to inspect DLQ: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($result->wasMessageNotFound()) {
            $this->components->error("Message with ID '{$result->notFoundId}' not found in DLQ");

            return self::FAILURE;
        }

        if ($result->isEmpty()) {
            $this->components->info('No messages in DLQ');

            return self::SUCCESS;
        }

        $this->displayMessages($result->messages, $format);

        return self::SUCCESS;
    }

    /**
     * Display messages in the requested format.
     *
     * @param  array<DlqMessageData>  $messages
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
     * @param  array<DlqMessageData>  $messages
     */
    protected function displayTable(array $messages): void
    {
        $rows = [];

        foreach ($messages as $msg) {
            $exceptionSummary = '';
            if ($msg->exception) {
                $exceptionMessage = $msg->exception['message'] ?? '';
                $exceptionSummary = mb_strlen($exceptionMessage) > 50
                    ? mb_substr($exceptionMessage, 0, 50).'...'
                    : $exceptionMessage;
            }

            $rows[] = [
                mb_substr($msg->id, 0, 8),
                class_basename($msg->jobClass),
                $msg->attempts,
                $msg->failedAt?->format('Y-m-d H:i:s') ?? '-',
                $msg->reason,
                $exceptionSummary ?: '-',
            ];
        }

        $this->table(
            ['ID', 'Job Class', 'Attempts', 'Failed At', 'Reason', 'Exception'],
            $rows
        );

        $this->newLine();
        $this->line('<fg=gray>Showing '.count($messages).' message(s). Use --format=json for full details.</>');
    }

    /**
     * Display messages as JSON.
     *
     * @param  array<DlqMessageData>  $messages
     */
    protected function displayJson(array $messages): void
    {
        foreach ($messages as $msg) {
            $this->line(json_encode([
                'id' => $msg->id,
                'job_class' => $msg->jobClass,
                'attempts' => $msg->attempts,
                'failed_at' => $msg->failedAt?->toIso8601String(),
                'reason' => $msg->reason,
                'exception' => $msg->exception,
                'payload' => $msg->payload,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $this->newLine();
        }
    }

    /**
     * Show error message when queue is not found, with available queues list.
     */
    protected function showQueueNotFoundError(DlqOperationException $e): void
    {
        $this->components->error($e->getMessage());
        $this->newLine();

        if (! empty($e->availableQueues)) {
            $this->components->info('Available queues:');
            foreach ($e->availableQueues as $name) {
                $this->line("  - {$name}");
            }
        } else {
            $this->components->warn('No queues discovered. Run attribute scanning first.');
        }
    }
}
