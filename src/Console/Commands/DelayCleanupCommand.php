<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Console\Commands;

use Illuminate\Console\Command;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Monitoring\ManagementClient;
use Lettermint\RabbitMQ\Topology\TopologyRegistry;
use RuntimeException;
use Throwable;

final class DelayCleanupCommand extends Command
{
    protected $signature = 'rabbitmq:delay-cleanup {queue* : Physical delay queue names} {--dry-run : Check without deleting queues}';

    protected $description = 'Remove selected empty and unused delay queues';

    public function handle(ManagementClient $management, TopologyRegistry $registry, ChannelManager $channels): int
    {
        $connection = (string) config('rabbitmq.default', 'default');
        $failed = false;

        foreach ($this->argument('queue') as $name) {
            try {
                $details = $management->queue($name, $connection);

                if ($details === null) {
                    $this->line('ABSENT '.$name);

                    continue;
                }

                if (($details['type'] ?? null) !== 'classic') {
                    throw new RuntimeException('Quorum delay queues are retained: this broker does not support conditional empty and unused deletion.');
                }

                $arguments = $details['arguments'] ?? [];
                $owned = false;

                foreach ($registry->queues() as $definition) {
                    $exchange = $arguments['x-dead-letter-exchange'] ?? null;
                    $route = $arguments['x-dead-letter-routing-key'] ?? null;
                    $ttl = $arguments['x-message-ttl'] ?? null;

                    if (! is_string($route) || ! is_int($ttl) || $ttl < 1 || ! array_key_exists($exchange ?? '', $definition->bindings)) {
                        continue;
                    }

                    $suffix = substr(hash('sha256', $exchange."\0".$route), 0, 12);

                    foreach (['delay:', 'delay-v2:'] as $prefix) {
                        foreach ([$definition->logicalName, substr(hash('sha256', $definition->logicalName), 0, 20)] as $logical) {
                            $owned = $owned || $name === $registry->physicalName($prefix.$logical.':'.$ttl.':'.$suffix);
                        }
                    }
                }

                if (! $owned || ($details['messages'] ?? -1) !== 0 || ($details['consumers'] ?? -1) !== 0) {
                    throw new RuntimeException('The queue is not a verified empty, unused package delay queue.');
                }

                if (! $this->option('dry-run')) {
                    $channels->channel('delay-cleanup', $connection)->queue_delete($name, true, true);
                }

                $this->line(($this->option('dry-run') ? 'ELIGIBLE ' : 'DELETED ').$name);
            } catch (Throwable $exception) {
                $failed = true;
                $this->error($name.': '.$exception->getMessage());
            } finally {
                $channels->closeChannel('delay-cleanup', $connection);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
