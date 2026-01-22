<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Lettermint\RabbitMQ\Actions\Dlq\ReplayDlqMessages;
use Lettermint\RabbitMQ\Actions\Dlq\Results\DlqMessageData;
use Lettermint\RabbitMQ\Exceptions\DlqOperationException;
use Symfony\Component\Console\Helper\ProgressBar;

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

    private ?ProgressBar $progressBar = null;

    public function handle(ReplayDlqMessages $replayDlq): int
    {
        $queueName = $this->argument('queue');
        $targetId = $this->option('id');
        $limit = (int) $this->option('limit');
        $rate = (int) $this->option('rate');
        $batchSize = (int) $this->option('batch');
        $dryRun = (bool) $this->option('dry-run');

        $this->components->info("Replaying messages from DLQ for queue: {$queueName}");

        if ($dryRun) {
            $this->components->warn('Dry run mode - no messages will be moved');
        }

        try {
            // Get message count for progress bar (only for bulk non-dry-run)
            $totalMessages = 0;
            if ($targetId === null && ! $dryRun) {
                $totalMessages = $replayDlq->getQueueMessageCount($queueName);
                $effectiveLimit = $limit > 0 ? min($limit, $totalMessages) : $totalMessages;

                if ($effectiveLimit > 0 && ! $this->getOutput()->isQuiet()) {
                    $this->progressBar = $this->output->createProgressBar($effectiveLimit);
                    $this->progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %message%');
                    $this->progressBar->setMessage('Starting...');
                    $this->progressBar->start();
                }
            }

            $result = $replayDlq(
                queueName: $queueName,
                messageId: $targetId,
                limit: $limit,
                rate: $rate,
                batchSize: $batchSize,
                dryRun: $dryRun,
                onProgress: $this->createProgressCallback(),
            );
        } catch (DlqOperationException $e) {
            $this->finishProgressBar();
            $this->showQueueNotFoundError($e);

            return self::FAILURE;
        } catch (\Exception $e) {
            $this->finishProgressBar();
            $this->components->error("Failed to replay DLQ: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->finishProgressBar();

        if ($result->wasMessageNotFound()) {
            $this->components->error("Message with ID '{$result->notFoundId}' not found in DLQ");

            return self::FAILURE;
        }

        // Display results for dry-run or single message
        if ($dryRun || $targetId !== null) {
            foreach ($result->replayedMessages as $msg) {
                if ($dryRun) {
                    $this->line("  Would replay: {$msg->jobClass}");
                } else {
                    $newAttempts = $msg->attempts + 1;
                    $this->components->success("Message '{$msg->id}' replayed to '{$queueName}' (attempt #{$newAttempts})");
                }
            }

            if ($dryRun) {
                $this->newLine();
                $this->components->info("Found {$result->replayedCount} message(s) to replay");
            }
        } else {
            // Bulk non-dry-run summary
            $this->newLine();
            $this->components->success("Replayed {$result->replayedCount} message(s) from DLQ to '{$queueName}'");

            if ($result->hasFailures()) {
                $this->components->warn("Failed to replay {$result->failedCount} message(s) - see logs for details");
            }
        }

        // Log bulk replays
        if (! $dryRun && $targetId === null && $result->replayedCount > 0) {
            Log::info('DLQ bulk replay completed', [
                'queue' => $queueName,
                'replayed_count' => $result->replayedCount,
                'failed_count' => $result->failedCount,
            ]);
        }

        return $result->hasFailures() ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Create a progress callback for the replay action.
     *
     * @return callable(DlqMessageData, bool, ?string): void
     */
    private function createProgressCallback(): callable
    {
        if ($this->progressBar === null) {
            return function (DlqMessageData $msg, bool $success, ?string $error): void {
                if ($this->getOutput()->isVerbose()) {
                    if ($success) {
                        $newAttempts = $msg->attempts + 1;
                        $this->line("  <fg=green>✓</> Replayed: {$msg->jobClass} (attempt #{$newAttempts})");
                    } else {
                        $this->line("  <fg=red>✗</> Failed: {$msg->jobClass} - {$error}");
                    }
                }
            };
        }

        return function (DlqMessageData $msg, bool $success, ?string $error): void {
            if ($success) {
                $newAttempts = $msg->attempts + 1;
                $this->progressBar->setMessage("{$msg->jobClass} (#{$newAttempts})");
            } else {
                $this->progressBar->setMessage("<fg=red>Failed: {$msg->jobClass}</>");
            }
            $this->progressBar->advance();
        };
    }

    private function finishProgressBar(): void
    {
        if ($this->progressBar !== null) {
            $this->progressBar->finish();
            $this->newLine(2);
            $this->progressBar = null;
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
