<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Console\Commands;

use Illuminate\Console\Command;
use Lettermint\RabbitMQ\Topology\TopologyManager;

/**
 * Artisan command to declare RabbitMQ topology.
 *
 * This command scans for Exchange and ConsumesQueue attributes and declares
 * all exchanges, queues, and bindings in RabbitMQ.
 */
class DeclareCommand extends Command
{
    protected $signature = 'rabbitmq:declare
        {--dry-run : Show what would be declared without making changes}';

    protected $description = 'Declare the registered RabbitMQ exchanges, queues, and bindings';

    public function handle(TopologyManager $topologyManager): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->components->info('Dry run mode - no changes will be made');
        }

        $this->components->info('Reading the RabbitMQ topology');

        try {
            $result = $topologyManager->declare($dryRun);

            $this->newLine();
            $this->displaySection('Exchanges', $result['exchanges']);
            $this->displaySection('Queues', $result['queues']);
            $this->displaySection('Bindings', $result['bindings']);

            $this->newLine();

            if ($dryRun) {
                $this->components->info('Dry run complete. Run without --dry-run to apply changes.');
            } else {
                $this->components->success('Topology declared successfully');
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->components->error("Failed to declare topology: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * Display a section of topology items.
     *
     * @param  array<string>  $items
     */
    protected function displaySection(string $title, array $items): void
    {
        if (empty($items)) {
            return;
        }

        $this->components->twoColumnDetail("<fg=yellow>{$title}</>");

        foreach ($items as $item) {
            $this->components->twoColumnDetail("  {$item}", '<fg=green>OK</>');
        }
    }
}
