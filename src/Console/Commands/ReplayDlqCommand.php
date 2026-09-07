<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Console\Commands;

use Illuminate\Console\Command;
use Lettermint\RabbitMQ\Actions\Dlq\ReplayDlqMessages;
use Lettermint\RabbitMQ\Actions\Dlq\Results\DlqMessageData;
use Lettermint\RabbitMQ\Exceptions\DlqOperationException;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Artisan command to replay messages from a dead letter queue.
 *
 * This command moves messages from a DLQ back to their original queue
 * for reprocessing. It confirms the replacement publish before it acknowledges
 * the DLQ message. A lost acknowledgement can cause a duplicate message.
 */
class ReplayDlqCommand extends Command
{
    protected $signature = 'rabbitmq:replay-dlq
        {queue : The original queue name (not the DLQ name)}
        {--id= : Replay a specific message by ID}
        {--limit=0 : Maximum messages to replay within configured scan limits (0 = scan limit)}
        {--rate=0 : Maximum messages per second (0 = unlimited)}
        {--batch=0 : Process in batches of N messages with 1s pause between (0 = no batching)}
        {--dry-run : Show what would be replayed without making changes}
        {--json : Write one machine-readable result}';

    protected $description = 'Replay messages from a dead letter queue back to the original queue';

    private ?ProgressBar $progressBar = null;

    public function handle(ReplayDlqMessages $replayDlq): int
    {
        foreach (['limit', 'rate', 'batch'] as $option) {
            if (filter_var($this->option($option), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
                $error = "The --{$option} option must be a non-negative integer.";
                $this->option('json') ? $this->line((string) json_encode(['error' => $error])) : $this->error($error);

                return self::FAILURE;
            }
        }

        if ($this->option('json')) {
            return $this->handleJson($replayDlq);
        }
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

        if ($result->incomplete) {
            $this->components->warn('The scan limit was reached. The operation is incomplete.');
        }

        if ($result->uncertain) {
            $this->components->warn('A transfer is uncertain. Check the destination before replaying again.');
        }

        foreach ($result->failures as $failure) {
            $this->components->error($failure['error']);
        }

        // Display results for dry-run or single message
        if ($dryRun || $targetId !== null) {
            foreach ($result->replayedMessages as $msg) {
                if ($dryRun) {
                    $this->line("  Would replay: {$msg->jobClass}");
                } else {
                    $this->components->success("Message '{$msg->id}' replayed to '{$queueName}'");
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

        return $result->hasFailures() || $result->incomplete || $result->uncertain ? self::FAILURE : self::SUCCESS;
    }

    private function handleJson(ReplayDlqMessages $replayDlq): int
    {
        try {
            $result = $replayDlq(
                queueName: $this->argument('queue'),
                messageId: $this->option('id'),
                limit: (int) $this->option('limit'),
                rate: (int) $this->option('rate'),
                batchSize: (int) $this->option('batch'),
                dryRun: (bool) $this->option('dry-run'),
            );
            $this->line((string) json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES));

            return $result->wasMessageNotFound() || $result->hasFailures() || $result->incomplete || $result->uncertain ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->line((string) json_encode(['error' => $exception->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE));

            return self::FAILURE;
        }
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
                        $this->line("  <fg=green>OK</> Replayed: {$msg->jobClass}");
                    } else {
                        $this->line("  <fg=red>FAILED</> {$msg->jobClass} - {$error}");
                    }
                }
            };
        }

        return function (DlqMessageData $msg, bool $success, ?string $error): void {
            if ($success) {
                $this->progressBar->setMessage($msg->jobClass);
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
