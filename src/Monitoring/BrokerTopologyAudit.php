<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Monitoring;

use Lettermint\RabbitMQ\Topology\TopologyRegistry;
use RuntimeException;
use Throwable;

final class BrokerTopologyAudit
{
    public function __construct(private ManagementClient $client) {}

    /** @return list<array{entity: string, name: string, error: string}> */
    public function check(TopologyRegistry $registry, string $connection): array
    {
        $failures = [];
        $vhost = $this->client->vhost($connection);

        if (! $this->client->configured()) {
            return [['entity' => 'management', 'name' => $connection, 'error' => 'A strict audit requires rabbitmq.management.url.']];
        }

        $exchanges = [];
        foreach ($registry->exchanges() as $exchange) {
            $exchanges[$exchange['name']] = $exchange;
        }
        foreach ($registry->queues() as $queue) {
            if ($queue->deadLetterEnabled && ! isset($exchanges[$queue->deadLetterExchange])) {
                $exchanges[$queue->deadLetterExchange] = [
                    'name' => $queue->deadLetterExchange, 'type' => 'direct',
                    'durable' => true, 'auto_delete' => false, 'internal' => false,
                    'arguments' => [], 'bind_to' => null, 'bind_routing_key' => '#',
                ];
            }
        }

        foreach ($exchanges as $exchange) {
            try {
                $actual = $this->client->get('exchanges/'.$vhost.'/'.rawurlencode($exchange['name']), $connection);

                foreach (['type', 'durable', 'auto_delete', 'internal'] as $key) {
                    if (($actual[$key] ?? null) !== $exchange[$key]) {
                        throw new RuntimeException('Exchange property differs: '.$key);
                    }
                }

                if (($actual['arguments'] ?? []) != $exchange['arguments']) {
                    throw new RuntimeException('Exchange arguments differ.');
                }

                if ($exchange['bind_to'] !== null) {
                    $bindings = $this->client->get('exchanges/'.$vhost.'/'.rawurlencode($exchange['name']).'/bindings/destination', $connection) ?? [];
                    $this->assertBinding($bindings, $exchange['bind_to'], $exchange['bind_routing_key']);
                }
            } catch (Throwable $exception) {
                $failures[] = ['entity' => 'exchange', 'name' => $exchange['name'], 'error' => $exception->getMessage()];
            }
        }

        foreach ($registry->queues() as $queue) {
            $definitions = [[$queue->physicalName, $queue->queueArguments(), $queue->bindings, $queue->quorum ? 'quorum' : 'classic']];

            if ($queue->deadLetterEnabled) {
                $definitions[] = [$queue->deadLetterQueue, $queue->deadLetterQueueArguments(), [$queue->deadLetterExchange => [$queue->deadLetterRoutingKey]], 'quorum'];
            }

            foreach ($definitions as [$name, $arguments, $expectedBindings, $type]) {
                try {
                    $actual = $this->client->queue($name, $connection);

                    if ($actual === null || ($actual['durable'] ?? false) !== true || ($actual['auto_delete'] ?? true) !== false) {
                        throw new RuntimeException('The queue is missing or is not durable.');
                    }
                    if (($actual['type'] ?? null) !== $type) {
                        throw new RuntimeException('Queue type differs from the configured type.');
                    }

                    foreach ($arguments as $key => $value) {
                        $observed = $key === 'x-queue-type' ? $actual['type'] : $this->client->effectiveArgument($actual, $key);

                        if ($observed !== $value) {
                            throw new RuntimeException('Queue argument or policy differs: '.$key);
                        }
                    }

                    foreach (['x-message-ttl', 'x-expires', 'x-max-length', 'x-max-length-bytes', 'x-dead-letter-exchange', 'x-dead-letter-routing-key'] as $key) {
                        if (! array_key_exists($key, $arguments) && $this->client->effectiveArgument($actual, $key) !== null) {
                            throw new RuntimeException('Unexpected queue argument or policy: '.$key);
                        }
                    }

                    $bindings = $this->client->get('queues/'.$vhost.'/'.rawurlencode($name).'/bindings', $connection) ?? [];

                    foreach ($expectedBindings as $exchange => $routingKeys) {
                        foreach ($routingKeys as $routingKey) {
                            $this->assertBinding($bindings, $exchange, $routingKey);
                        }
                    }
                    foreach ($bindings as $binding) {
                        $source = $binding['source'] ?? '';
                        if ($source !== '' && (! in_array($binding['routing_key'] ?? null, $expectedBindings[$source] ?? [], true) || ($binding['arguments'] ?? []) !== [])) {
                            throw new RuntimeException('The queue has an unexpected binding.');
                        }
                    }
                } catch (Throwable $exception) {
                    $failures[] = ['entity' => 'queue', 'name' => $name, 'error' => $exception->getMessage()];
                }
            }
        }

        return $failures;
    }

    /** @param array<array<string, mixed>> $bindings */
    private function assertBinding(array $bindings, string $source, string $routingKey): void
    {
        foreach ($bindings as $binding) {
            if (($binding['source'] ?? null) === $source && ($binding['routing_key'] ?? null) === $routingKey && ($binding['arguments'] ?? []) === []) {
                return;
            }
        }

        throw new RuntimeException('A required binding is missing.');
    }
}
