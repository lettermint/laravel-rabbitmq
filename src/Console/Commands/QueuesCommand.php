<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Console\Commands;

use Illuminate\Console\Command;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Monitoring\QueueMetrics;

/**
 * Artisan command to list RabbitMQ queues with statistics.
 */
class QueuesCommand extends Command
{
    protected $signature = 'rabbitmq:queues
        {--include-dlq : Include DLQ queues in the list}
        {--watch : Continuously watch queue statistics}
        {--interval=5 : Refresh interval in seconds when watching}';

    protected $description = 'List all RabbitMQ queues with statistics';

    public function handle(AttributeScanner $scanner, QueueMetrics $metrics): int
    {
        $topology = $scanner->getTopology();
        $includeDlq = (bool) $this->option('include-dlq');

        if ($this->option('watch')) {
            return $this->watchQueues($topology, $metrics, $includeDlq);
        }

        return $this->displayQueues($topology, $metrics, $includeDlq);
    }

    /**
     * Display queue information once.
     *
     * @param  array<string, mixed>  $topology
     */
    protected function displayQueues(array $topology, QueueMetrics $metrics, bool $includeDlq): int
    {
        $rows = $this->buildQueueRows($topology, $metrics, $includeDlq);

        if (empty($rows)) {
            $this->components->warn('No queues discovered');

            return self::SUCCESS;
        }

        $this->table(
            ['Queue', 'Messages', 'Consumers', 'Rate/sec', 'Job Class'],
            $rows
        );

        return self::SUCCESS;
    }

    /**
     * Continuously watch queue statistics.
     *
     * Handles SIGINT (Ctrl+C) and SIGTERM signals for graceful shutdown.
     *
     * @param  array<string, mixed>  $topology
     */
    protected function watchQueues(array $topology, QueueMetrics $metrics, bool $includeDlq): int
    {
        $interval = (int) $this->option('interval');
        $shouldStop = false;

        // Set up signal handling for graceful shutdown
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);

            $signalHandler = function () use (&$shouldStop): void {
                $shouldStop = true;
            };

            pcntl_signal(SIGINT, $signalHandler);
            pcntl_signal(SIGTERM, $signalHandler);
        }

        $this->components->info('Watching queues (Ctrl+C to stop)...');
        $this->newLine();

        while (! $shouldStop) {
            // Clear screen and move cursor to top
            $this->output->write("\033[H\033[J");

            $this->components->info('RabbitMQ Queues - '.now()->format('H:i:s'));
            $this->newLine();

            $rows = $this->buildQueueRows($topology, $metrics, $includeDlq);
            $this->table(
                ['Queue', 'Messages', 'Consumers', 'Rate/sec', 'Job Class'],
                $rows
            );

            // Sleep in smaller increments to be more responsive to signals
            for ($i = 0; $i < $interval && ! $shouldStop; $i++) {
                sleep(1);

                // Process any pending signals
                if (extension_loaded('pcntl')) {
                    pcntl_signal_dispatch();
                }
            }
        }

        $this->newLine();
        $this->components->info('Watch mode terminated gracefully');

        return self::SUCCESS;
    }

    /**
     * Build table rows for queue display.
     *
     * @param  array<string, mixed>  $topology
     * @return array<int, array<string>>
     */
    protected function buildQueueRows(array $topology, QueueMetrics $metrics, bool $includeDlq): array
    {
        $rows = [];

        foreach ($topology['queues'] as $queueName => $queueData) {
            $stats = $metrics->getQueueStats($queueName);

            $rows[] = [
                $queueName,
                $this->formatStat($stats['messages']),
                $this->formatStat($stats['consumers']),
                $this->formatRate($stats['rate']),
                class_basename($queueData['class']),
            ];

            // Include DLQ queue
            if ($includeDlq) {
                $dlqName = $queueData['attribute']->getDlqQueueName();
                $dlqStats = $metrics->getQueueStats($dlqName);

                // Highlight DLQ messages in red if non-zero
                $dlqMessages = $dlqStats['messages'];
                $dlqMessageDisplay = $dlqMessages === null
                    ? '<fg=gray>-</>'
                    : ($dlqMessages > 0 ? '<fg=red>'.number_format($dlqMessages).'</>' : '0');

                $rows[] = [
                    "<fg=gray>{$dlqName}</>",
                    $dlqMessageDisplay,
                    $this->formatStat($dlqStats['consumers'], 'gray'),
                    $this->formatRate($dlqStats['rate'], 'gray'),
                    '<fg=gray>DLQ</>',
                ];
            }
        }

        return $rows;
    }

    /**
     * Format a statistic value for display, handling null (unavailable) values.
     */
    protected function formatStat(?int $value, ?string $color = null): string
    {
        if ($value === null) {
            return $color ? "<fg={$color}>-</>" : '-';
        }

        $formatted = number_format($value);

        return $color ? "<fg={$color}>{$formatted}</>" : $formatted;
    }

    /**
     * Format a rate value for display, handling null (unavailable) values.
     */
    protected function formatRate(?float $value, ?string $color = null): string
    {
        if ($value === null) {
            return $color ? "<fg={$color}>-</>" : '-';
        }

        $formatted = number_format($value, 1);

        return $color ? "<fg={$color}>{$formatted}</>" : $formatted;
    }
}
