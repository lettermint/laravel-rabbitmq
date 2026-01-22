<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Console\Commands;

use Carbon\CarbonInterval;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Lettermint\RabbitMQ\Actions\Dlq\PurgeDlqMessages;
use Lettermint\RabbitMQ\Exceptions\DlqOperationException;

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

    public function handle(PurgeDlqMessages $purgeDlq): int
    {
        $queueName = $this->argument('queue');
        $targetId = $this->option('id');
        $olderThan = $this->option('older-than');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

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
                : "Are you sure you want to permanently delete messages from the DLQ for '{$queueName}'?";

            if (! $this->confirm($confirmMessage)) {
                $this->components->info('Operation cancelled');

                return self::SUCCESS;
            }
        }

        if ($dryRun) {
            $this->components->warn('Dry run mode - no messages will be deleted');
        }

        $this->components->info("Purging DLQ for queue: {$queueName}");

        try {
            $result = $purgeDlq(
                queueName: $queueName,
                messageId: $targetId,
                olderThan: $cutoffTime,
                dryRun: $dryRun,
            );
        } catch (DlqOperationException $e) {
            $this->showQueueNotFoundError($e);

            return self::FAILURE;
        } catch (\Exception $e) {
            $this->components->error("Failed to purge DLQ: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($result->wasMessageNotFound()) {
            $this->components->error("Message with ID '{$result->notFoundId}' not found in DLQ");

            return self::FAILURE;
        }

        // Display what would be / was purged
        foreach ($result->purgedMessages as $msg) {
            if ($dryRun) {
                $this->line("  Would purge: {$msg->jobClass}");
            } elseif ($this->getOutput()->isVerbose()) {
                $this->line("  <fg=red>x</> Purged: {$msg->jobClass}");
            }
        }

        $this->newLine();

        if ($dryRun) {
            $this->components->info("{$result->purgedCount} message(s) would be purged");
            if ($result->skippedCount > 0) {
                $this->components->info("{$result->skippedCount} message(s) would be skipped (newer than cutoff)");
            }
        } else {
            if ($result->purgedCount > 0) {
                Log::info('DLQ purge completed', [
                    'queue' => $queueName,
                    'purged_count' => $result->purgedCount,
                    'skipped_count' => $result->skippedCount,
                ]);
            }

            $this->components->success("{$result->purgedCount} message(s) purged from DLQ");
            if ($result->skippedCount > 0) {
                $this->components->info("{$result->skippedCount} message(s) skipped (newer than cutoff)");
            }
        }

        return self::SUCCESS;
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
