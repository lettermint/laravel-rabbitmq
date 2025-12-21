<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Console\Commands;

use Illuminate\Console\Command;
use Lettermint\RabbitMQ\Monitoring\HealthCheck;

/**
 * Artisan command to check RabbitMQ health.
 */
class HealthCommand extends Command
{
    protected $signature = 'rabbitmq:health
        {--consumer : Check consumer-specific health}
        {--json : Output as JSON}';

    protected $description = 'Check RabbitMQ connection and cluster health';

    public function handle(HealthCheck $healthCheck): int
    {
        $results = $healthCheck->check();

        if ($this->option('json')) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT));

            return $results['healthy'] ? self::SUCCESS : self::FAILURE;
        }

        $this->components->info('RabbitMQ Health Check');
        $this->newLine();

        foreach ($results['checks'] as $check => $status) {
            $icon = $status['healthy'] ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $message = $status['message'];

            $this->line("  {$icon} {$check}: {$message}");
        }

        $this->newLine();

        if ($results['healthy']) {
            $this->components->success('All health checks passed');

            return self::SUCCESS;
        }

        $this->components->error('Some health checks failed');

        return self::FAILURE;
    }
}
