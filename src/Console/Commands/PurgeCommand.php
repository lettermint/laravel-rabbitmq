<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Console\Commands;

use Illuminate\Console\Command;
use Lettermint\RabbitMQ\Topology\TopologyManager;

/**
 * Artisan command to purge messages from a RabbitMQ queue.
 */
class PurgeCommand extends Command
{
    protected $signature = 'rabbitmq:purge
        {queue : The queue to purge}
        {--force : Skip confirmation prompt}';

    protected $description = 'Purge all messages from a RabbitMQ queue';

    public function handle(TopologyManager $topologyManager): int
    {
        $queue = $this->argument('queue');

        if (! $this->option('force')) {
            if (! $this->components->confirm("Are you sure you want to purge all messages from '{$queue}'?")) {
                $this->components->info('Operation cancelled.');

                return self::SUCCESS;
            }
        }

        try {
            $count = $topologyManager->purgeQueue($queue);

            $this->components->success("Purged {$count} message(s) from queue '{$queue}'");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->components->error("Failed to purge queue: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
