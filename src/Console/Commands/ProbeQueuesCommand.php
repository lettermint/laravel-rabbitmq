<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Str;
use Lettermint\RabbitMQ\Diagnostics\QueueProbeJob;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;
use Lettermint\RabbitMQ\Topology\TopologyRegistry;

final class ProbeQueuesCommand extends Command
{
    protected $signature = 'rabbitmq:probe
        {queue?* : Logical queues to probe}
        {--all : Probe every registered queue}
        {--connection=rabbitmq : Laravel queue connection}
        {--json : Write machine-readable output}';

    protected $description = 'Publish a safe probe job to registered RabbitMQ queues';

    public function handle(QueueManager $manager, TopologyRegistry $registry): int
    {
        $requested = $this->option('all')
            ? $registry->logicalQueueNames()
            : array_values($this->argument('queue'));
        $requested = $requested === [] ? ['default'] : $requested;
        $connectionName = (string) $this->option('connection');
        $connection = $manager->connection($connectionName);

        if (! $connection instanceof RabbitMQQueue) {
            $this->error("Queue connection [{$connectionName}] does not use this RabbitMQ driver.");

            return self::FAILURE;
        }

        $results = [];

        foreach ($requested as $logicalQueue) {
            $registry->queue($logicalQueue);
            $probeId = (string) Str::uuid();
            $dispatchedAt = time();
            $connection->push(
                new QueueProbeJob($probeId, $logicalQueue, $dispatchedAt),
                '',
                $logicalQueue,
            );
            $results[] = ['queue' => $logicalQueue, 'probe_id' => $probeId, 'dispatched_at' => $dispatchedAt];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['probes' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($results as $result) {
                $this->line("PUBLISHED {$result['queue']} {$result['probe_id']}");
            }
        }

        return self::SUCCESS;
    }
}
