<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Console\Commands;

use Illuminate\Console\Command;
use Lettermint\RabbitMQ\Topology\TopologyRegistry;

final class TopologyCommand extends Command
{
    protected $signature = 'rabbitmq:topology
        {--format=table : Output format: table or json}';

    protected $description = 'Display the normalized RabbitMQ topology';

    public function handle(TopologyRegistry $registry): int
    {
        $topology = [
            'strict' => $registry->isStrict(),
            'exchanges' => $registry->exchanges(),
            'queues' => array_map(fn ($queue): array => [
                'logical_name' => $queue->logicalName,
                'physical_name' => $queue->physicalName,
                'bindings' => $queue->bindings,
                'arguments' => $queue->queueArguments(),
                'dead_letter_queue' => $queue->deadLetterEnabled ? $queue->deadLetterQueue : null,
                'dead_letter_exchange' => $queue->deadLetterEnabled ? $queue->deadLetterExchange : null,
            ], $registry->queues()),
        ];

        if ($this->option('format') === 'json') {
            $this->line((string) json_encode($topology, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($topology['queues'] as $queue) {
            $rows[] = [
                $queue['logical_name'],
                $queue['physical_name'],
                implode(', ', array_keys($queue['bindings'])),
                $queue['dead_letter_queue'] ?? '-',
            ];
        }

        $this->table(['Logical queue', 'Physical queue', 'Exchanges', 'Dead-letter queue'], $rows);

        return self::SUCCESS;
    }
}
