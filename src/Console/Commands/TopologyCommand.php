<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Console\Commands;

use Illuminate\Console\Command;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;

/**
 * Artisan command to display RabbitMQ topology.
 *
 * This command shows the complete topology that would be declared from
 * the discovered attributes, in a visual tree format.
 */
class TopologyCommand extends Command
{
    protected $signature = 'rabbitmq:topology
        {--format=tree : Output format: tree, json, or table}';

    protected $description = 'Display the RabbitMQ topology from discovered attributes';

    public function handle(AttributeScanner $scanner): int
    {
        $topology = $scanner->getTopology();
        $format = $this->option('format');

        return match ($format) {
            'json' => $this->outputJson($topology),
            'table' => $this->outputTable($topology),
            default => $this->outputTree($topology),
        };
    }

    /**
     * Output topology as a tree structure.
     *
     * @param  array<string, mixed>  $topology
     */
    protected function outputTree(array $topology): int
    {
        $this->components->info('RabbitMQ Topology');
        $this->newLine();

        // Output exchanges
        $this->line('<fg=yellow>Exchanges:</>');

        if (empty($topology['exchanges'])) {
            $this->line('  (none discovered)');
        } else {
            foreach ($topology['exchanges'] as $name => $exchange) {
                $type = $exchange->getTypeValue();
                $this->line("  <fg=green>{$name}</> <fg=gray>({$type})</>");

                if ($exchange->bindTo !== null) {
                    $this->line("  │ └── binds to: {$exchange->bindTo} [{$exchange->bindRoutingKey}]");
                }

                // Show auto-created DLQ
                $dlq = $exchange->getDlqExchangeName();
                $this->line("  └── <fg=gray>{$dlq} (auto-created DLQ)</>");
            }
        }

        $this->newLine();

        // Output queues with their bindings
        $this->line('<fg=yellow>Queues (from Jobs):</>');

        if (empty($topology['queues'])) {
            $this->line('  (none discovered)');
        } else {
            foreach ($topology['queues'] as $queueName => $queueData) {
                $attribute = $queueData['attribute'];
                $className = class_basename($queueData['class']);

                $this->line("  <fg=green>{$queueName}</> <fg=gray>← {$className}</>");

                // Show queue settings
                $settings = [];
                if ($attribute->quorum) {
                    $settings[] = 'quorum';
                }
                if ($attribute->maxPriority !== null) {
                    $settings[] = "priority:{$attribute->maxPriority}";
                }
                if (! empty($settings)) {
                    $this->line('  │ settings: '.implode(', ', $settings));
                }

                // Show bindings
                foreach ($attribute->bindings as $exchange => $routingKeys) {
                    $keys = is_array($routingKeys) ? implode(', ', $routingKeys) : $routingKeys;
                    $this->line("  │ └── binds to: <fg=cyan>{$exchange}</> [{$keys}]");
                }

                // Show DLQ
                $dlqQueue = $attribute->getDlqQueueName();
                $dlqExchange = $attribute->getDlqExchangeName();
                if ($dlqExchange !== null) {
                    $this->line("  └── DLQ: <fg=gray>{$dlqQueue}</> (via {$dlqExchange})");
                }
            }
        }

        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Output topology as JSON.
     *
     * @param  array<string, mixed>  $topology
     */
    protected function outputJson(array $topology): int
    {
        $output = [
            'exchanges' => [],
            'queues' => [],
        ];

        foreach ($topology['exchanges'] as $name => $exchange) {
            $output['exchanges'][$name] = [
                'type' => $exchange->getTypeValue(),
                'durable' => $exchange->durable,
                'bindTo' => $exchange->bindTo,
                'bindRoutingKey' => $exchange->bindRoutingKey,
                'dlqExchange' => $exchange->getDlqExchangeName(),
            ];
        }

        foreach ($topology['queues'] as $queueName => $queueData) {
            $attribute = $queueData['attribute'];
            $output['queues'][$queueName] = [
                'class' => $queueData['class'],
                'bindings' => $attribute->bindings,
                'quorum' => $attribute->quorum,
                'maxPriority' => $attribute->maxPriority,
                'messageTtl' => $attribute->messageTtl,
                'retryAttempts' => $attribute->retryAttempts,
                'retryStrategy' => $attribute->retryStrategy,
                'dlqQueue' => $attribute->getDlqQueueName(),
                'dlqExchange' => $attribute->getDlqExchangeName(),
            ];
        }

        $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * Output topology as tables.
     *
     * @param  array<string, mixed>  $topology
     */
    protected function outputTable(array $topology): int
    {
        // Exchanges table
        $exchangeRows = [];
        foreach ($topology['exchanges'] as $name => $exchange) {
            $exchangeRows[] = [
                $name,
                $exchange->getTypeValue(),
                $exchange->durable ? 'Yes' : 'No',
                $exchange->bindTo ?? '-',
                $exchange->bindRoutingKey,
            ];
        }

        $this->table(
            ['Exchange', 'Type', 'Durable', 'Binds To', 'Routing Key'],
            $exchangeRows
        );

        $this->newLine();

        // Queues table
        $queueRows = [];
        foreach ($topology['queues'] as $queueName => $queueData) {
            $attribute = $queueData['attribute'];
            $bindings = [];
            foreach ($attribute->bindings as $exchange => $keys) {
                $keys = is_array($keys) ? implode(', ', $keys) : $keys;
                $bindings[] = "{$exchange}: {$keys}";
            }

            $queueRows[] = [
                $queueName,
                class_basename($queueData['class']),
                implode("\n", $bindings),
                $attribute->quorum ? 'Yes' : 'No',
                $attribute->maxPriority ?? '-',
            ];
        }

        $this->table(
            ['Queue', 'Job Class', 'Bindings', 'Quorum', 'Max Priority'],
            $queueRows
        );

        return self::SUCCESS;
    }
}
