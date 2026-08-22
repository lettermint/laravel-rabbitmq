<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Topology;

use Lettermint\RabbitMQ\Attributes\ConsumesQueue;
use Lettermint\RabbitMQ\Attributes\Exchange;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Enums\TopologyEntityType;
use Lettermint\RabbitMQ\Exceptions\TopologyException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

final class TopologyManager
{
    /** @var array<string, bool> */
    protected array $declaredExchanges = [];

    /** @var array<string, bool> */
    protected array $declaredQueues = [];

    /** @param  array<string, mixed>  $config */
    public function __construct(
        protected ChannelManager $channelManager,
        protected TopologyRegistry $registry,
        protected array $config,
    ) {}

    /**
     * @return array{exchanges: list<string>, queues: list<string>, bindings: list<string>}
     */
    public function declare(bool $dryRun = false): array
    {
        $result = ['exchanges' => [], 'queues' => [], 'bindings' => []];
        $exchanges = $this->registry->exchanges();
        $queues = $this->registry->queues();
        $channel = $dryRun ? null : $this->channelManager->topologyChannel($this->brokerConnection());

        foreach ($exchanges as $exchange) {
            $result['exchanges'][] = $exchange['name'];

            if ($channel instanceof AMQPChannel) {
                $this->declareExchangeDefinition($channel, $exchange);
            }
        }

        foreach ($exchanges as $exchange) {
            if ($exchange['bind_to'] === null) {
                continue;
            }

            $result['bindings'][] = "{$exchange['bind_to']} -> {$exchange['name']} [{$exchange['bind_routing_key']}]";

            if ($channel instanceof AMQPChannel) {
                $channel->exchange_bind(
                    $exchange['name'],
                    $exchange['bind_to'],
                    $exchange['bind_routing_key'],
                );
            }
        }

        foreach ($queues as $definition) {
            if ($definition->deadLetterEnabled) {
                if (! in_array($definition->deadLetterExchange, $result['exchanges'], true)) {
                    $result['exchanges'][] = $definition->deadLetterExchange;
                }

                if ($channel instanceof AMQPChannel) {
                    $this->declareDirectExchange($channel, $definition->deadLetterExchange);
                }
            }

            $result['queues'][] = $definition->physicalName;

            if ($channel instanceof AMQPChannel) {
                $this->declareQueueDefinition($channel, $definition);
            }

            foreach ($definition->bindings as $exchange => $routingKeys) {
                foreach ($routingKeys as $routingKey) {
                    $result['bindings'][] = "{$exchange} -> {$definition->physicalName} [{$routingKey}]";

                    if ($channel instanceof AMQPChannel && $exchange !== '') {
                        $channel->queue_bind($definition->physicalName, $exchange, $routingKey);
                    }
                }
            }

            if (! $definition->deadLetterEnabled) {
                continue;
            }

            $result['queues'][] = $definition->deadLetterQueue;
            $result['bindings'][] = "{$definition->deadLetterExchange} -> {$definition->deadLetterQueue} [{$definition->deadLetterRoutingKey}]";

            if ($channel instanceof AMQPChannel) {
                $this->declareDeadLetterQueue($channel, $definition);
            }
        }

        return $result;
    }

    /**
     * Perform passive broker operations for all configured entities.
     *
     * @return array{healthy: bool, queues: list<array{logical: string, physical: string, messages: int, consumers: int}>, failures: list<array{entity: string, name: string, error: string}>}
     */
    public function audit(): array
    {
        $queues = [];
        $failures = [];

        $expectedExchanges = [];

        foreach ($this->registry->exchanges() as $exchange) {
            $expectedExchanges[$exchange['name']] = $exchange;
        }

        foreach ($this->registry->queues() as $definition) {
            if ($definition->deadLetterEnabled && ! isset($expectedExchanges[$definition->deadLetterExchange])) {
                $expectedExchanges[$definition->deadLetterExchange] = [
                    'name' => $definition->deadLetterExchange,
                    'type' => 'direct',
                    'durable' => true,
                    'auto_delete' => false,
                    'internal' => false,
                    'arguments' => [],
                    'bind_to' => null,
                    'bind_routing_key' => '#',
                ];
            }
        }

        foreach ($expectedExchanges as $exchange) {
            $channel = $this->channelManager->channel('audit-exchange-'.$exchange['name'], $this->brokerConnection());

            try {
                $channel->exchange_declare(
                    $exchange['name'],
                    $exchange['type'],
                    true,
                    $exchange['durable'],
                    $exchange['auto_delete'],
                    $exchange['internal'],
                    false,
                    new AMQPTable($exchange['arguments']),
                );
            } catch (Throwable $exception) {
                $failures[] = ['entity' => 'exchange', 'name' => $exchange['name'], 'error' => $exception->getMessage()];
                $this->channelManager->closeChannel('audit-exchange-'.$exchange['name'], $this->brokerConnection());
            }
        }

        foreach ($this->registry->queues() as $definition) {
            foreach ([$definition->physicalName, $definition->deadLetterEnabled ? $definition->deadLetterQueue : null] as $physicalQueue) {
                if ($physicalQueue === null) {
                    continue;
                }

                $purpose = 'audit-queue-'.hash('sha256', $physicalQueue);
                $channel = $this->channelManager->channel($purpose, $this->brokerConnection());

                try {
                    [, $messages, $consumers] = $channel->queue_declare($physicalQueue, true, false, false, false);
                    $queues[] = [
                        'logical' => $definition->logicalName,
                        'physical' => $physicalQueue,
                        'messages' => (int) $messages,
                        'consumers' => (int) $consumers,
                    ];
                } catch (Throwable $exception) {
                    $failures[] = ['entity' => 'queue', 'name' => $physicalQueue, 'error' => $exception->getMessage()];
                    $this->channelManager->closeChannel($purpose, $this->brokerConnection());
                }
            }
        }

        return ['healthy' => $failures === [], 'queues' => $queues, 'failures' => $failures];
    }

    /**
     * Keep the public attribute declaration API for applications that do not use explicit topology.
     */
    public function declareExchange(AMQPChannel $channel, Exchange $exchange): void
    {
        $this->declareExchangeDefinition($channel, [
            'name' => $this->registry->physicalName($exchange->name),
            'type' => $exchange->getTypeValue(),
            'durable' => $exchange->durable,
            'auto_delete' => $exchange->autoDelete,
            'internal' => $exchange->internal,
            'arguments' => $exchange->arguments,
            'bind_to' => $exchange->bindTo === null ? null : $this->registry->physicalName($exchange->bindTo),
            'bind_routing_key' => $exchange->bindRoutingKey,
        ]);
    }

    public function declareQueue(AMQPChannel $channel, ConsumesQueue $attribute): void
    {
        $definition = $this->registry->queues()[$attribute->queue] ?? null;

        if (! $definition instanceof QueueDefinition) {
            throw new TopologyException(
                "Queue [{$attribute->queue}] is not registered.",
                TopologyEntityType::Queue,
                $attribute->queue,
            );
        }

        $this->declareQueueDefinition($channel, $definition);
    }

    /**
     * @param  array{name: string, type: string, durable: bool, auto_delete: bool, internal: bool, arguments: array<string, mixed>, bind_to: string|null, bind_routing_key: string}  $exchange
     */
    protected function declareExchangeDefinition(AMQPChannel $channel, array $exchange): void
    {
        if (isset($this->declaredExchanges[$exchange['name']])) {
            return;
        }

        try {
            $channel->exchange_declare(
                $exchange['name'],
                $exchange['type'],
                false,
                $exchange['durable'],
                $exchange['auto_delete'],
                $exchange['internal'],
                false,
                new AMQPTable($exchange['arguments']),
            );
            $this->declaredExchanges[$exchange['name']] = true;
        } catch (AMQPIOException|AMQPConnectionClosedException|AMQPChannelClosedException|AMQPProtocolChannelException $exception) {
            throw new TopologyException(
                "Failed to declare exchange [{$exchange['name']}]: {$exception->getMessage()}",
                TopologyEntityType::Exchange,
                $exchange['name'],
                previous: $exception,
            );
        }
    }

    protected function declareDirectExchange(AMQPChannel $channel, string $name): void
    {
        $this->declareExchangeDefinition($channel, [
            'name' => $name,
            'type' => 'direct',
            'durable' => true,
            'auto_delete' => false,
            'internal' => false,
            'arguments' => [],
            'bind_to' => null,
            'bind_routing_key' => '#',
        ]);
    }

    protected function declareQueueDefinition(AMQPChannel $channel, QueueDefinition $definition): void
    {
        if (isset($this->declaredQueues[$definition->physicalName])) {
            return;
        }

        try {
            $channel->queue_declare(
                $definition->physicalName,
                false,
                true,
                false,
                false,
                false,
                new AMQPTable($definition->queueArguments()),
            );
            $this->declaredQueues[$definition->physicalName] = true;
        } catch (AMQPIOException|AMQPConnectionClosedException|AMQPChannelClosedException|AMQPProtocolChannelException $exception) {
            throw new TopologyException(
                "Failed to declare queue [{$definition->physicalName}]: {$exception->getMessage()}",
                TopologyEntityType::Queue,
                $definition->physicalName,
                previous: $exception,
            );
        }
    }

    protected function declareDeadLetterQueue(AMQPChannel $channel, QueueDefinition $definition): void
    {
        if (isset($this->declaredQueues[$definition->deadLetterQueue])) {
            return;
        }

        try {
            $channel->queue_declare(
                $definition->deadLetterQueue,
                false,
                true,
                false,
                false,
                false,
                new AMQPTable($definition->deadLetterQueueArguments()),
            );
            $channel->queue_bind(
                $definition->deadLetterQueue,
                $definition->deadLetterExchange,
                $definition->deadLetterRoutingKey,
            );
            $this->declaredQueues[$definition->deadLetterQueue] = true;
        } catch (AMQPIOException|AMQPConnectionClosedException|AMQPChannelClosedException|AMQPProtocolChannelException $exception) {
            throw new TopologyException(
                "Failed to declare dead-letter queue [{$definition->deadLetterQueue}]: {$exception->getMessage()}",
                TopologyEntityType::Queue,
                $definition->deadLetterQueue,
                previous: $exception,
            );
        }
    }

    public function deleteQueue(string $queueName): void
    {
        $physicalQueue = $this->registry->physicalQueue($queueName);

        try {
            $this->channelManager->topologyChannel($this->brokerConnection())->queue_delete($physicalQueue);
            unset($this->declaredQueues[$physicalQueue]);
        } catch (Throwable $exception) {
            throw new TopologyException(
                "Failed to delete queue [{$physicalQueue}]: {$exception->getMessage()}",
                TopologyEntityType::Queue,
                $physicalQueue,
                previous: $exception,
            );
        }
    }

    public function purgeQueue(string $queueName): int
    {
        $physicalQueue = $this->registry->physicalQueue($queueName);

        try {
            return (int) $this->channelManager->topologyChannel($this->brokerConnection())->queue_purge($physicalQueue);
        } catch (Throwable $exception) {
            throw new TopologyException(
                "Failed to purge queue [{$physicalQueue}]: {$exception->getMessage()}",
                TopologyEntityType::Queue,
                $physicalQueue,
                previous: $exception,
            );
        }
    }

    /** @return array{messages: int, consumers: int, name: string} */
    public function getQueueInfo(string $queueName): array
    {
        $physicalQueue = $this->registry->physicalQueue($queueName);

        try {
            [$name, $messages, $consumers] = $this->channelManager
                ->topologyChannel($this->brokerConnection())
                ->queue_declare($physicalQueue, true, false, false, false);

            return ['name' => $name, 'messages' => (int) $messages, 'consumers' => (int) $consumers];
        } catch (Throwable $exception) {
            throw new TopologyException(
                "Failed to get queue information for [{$physicalQueue}]: {$exception->getMessage()}",
                TopologyEntityType::Queue,
                $physicalQueue,
                previous: $exception,
            );
        }
    }

    public function reset(): void
    {
        $this->declaredExchanges = [];
        $this->declaredQueues = [];
        $this->registry->reset();
    }

    protected function brokerConnection(): string
    {
        return (string) ($this->config['connection'] ?? $this->config['default'] ?? 'default');
    }
}
