<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Topology;

use Illuminate\Support\Arr;
use Lettermint\RabbitMQ\Attributes\ConsumesQueue;
use Lettermint\RabbitMQ\Attributes\Exchange;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Enums\TopologyEntityType;
use Lettermint\RabbitMQ\Exceptions\TopologyException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Manages RabbitMQ topology (exchanges, queues, bindings).
 *
 * This class is responsible for:
 * - Declaring exchanges from Exchange attributes
 * - Declaring queues from ConsumesQueue attributes
 * - Creating bindings between exchanges and queues
 * - Auto-creating DLQ exchanges and queues
 */
class TopologyManager
{
    /**
     * Declared exchanges (to avoid re-declaring).
     *
     * @var array<string, bool>
     */
    protected array $declaredExchanges = [];

    /**
     * Declared queues (to avoid re-declaring).
     *
     * @var array<string, bool>
     */
    protected array $declaredQueues = [];

    public function __construct(
        protected ChannelManager $channelManager,
        protected AttributeScanner $scanner,
        protected array $config,
    ) {}

    /**
     * Declare all topology from discovered attributes.
     *
     * @param  bool  $dryRun  If true, only returns what would be declared
     * @return array{exchanges: array<string>, queues: array<string>, bindings: array<string>}
     *
     * @throws TopologyException
     */
    public function declare(bool $dryRun = false): array
    {
        $topology = $this->scanner->getTopology();
        $result = [
            'exchanges' => [],
            'queues' => [],
            'bindings' => [],
        ];

        $channel = $dryRun ? null : $this->channelManager->topologyChannel();

        // First: Declare delayed exchange if enabled
        if (Arr::get($this->config, 'delayed.enabled', true)) {
            $delayedExchange = Arr::get($this->config, 'delayed.exchange', 'delayed');
            $result['exchanges'][] = "{$delayedExchange} (x-delayed-message)";

            if (! $dryRun) {
                $this->declareDelayedExchange($channel, $delayedExchange);
            }
        }

        // Second: Declare all exchanges from attributes
        foreach ($topology['exchanges'] as $name => $exchange) {
            $result['exchanges'][] = $name;

            if (! $dryRun) {
                $this->declareExchange($channel, $exchange);
            }

            // Auto-create DLQ exchange
            $dlqExchange = $exchange->getDlqExchangeName();
            if (! in_array($dlqExchange, $result['exchanges'])) {
                $result['exchanges'][] = $dlqExchange;

                if (! $dryRun) {
                    $this->declareDlqExchange($channel, $dlqExchange);
                }
            }
        }

        // Third: Declare exchange-to-exchange bindings
        foreach ($topology['exchanges'] as $name => $exchange) {
            if ($exchange->bindTo !== null) {
                $result['bindings'][] = "{$exchange->bindTo} -> {$name} [{$exchange->bindRoutingKey}]";

                if (! $dryRun) {
                    $this->declareExchangeBinding($channel, $exchange);
                }
            }
        }

        // Fourth: Declare queues and their bindings
        foreach ($topology['queues'] as $queueName => $queueData) {
            /** @var ConsumesQueue $attribute */
            $attribute = $queueData['attribute'];

            // Use allBindings which merges bindings from all jobs declaring this queue
            $allBindings = $queueData['allBindings'];

            $result['queues'][] = $queueName;

            if (! $dryRun) {
                $this->declareQueue($channel, $attribute);
            }

            // Declare bindings from ALL jobs that use this queue
            foreach ($allBindings as $exchangeName => $routingKeys) {
                $routingKeys = (array) $routingKeys;

                foreach ($routingKeys as $routingKey) {
                    $result['bindings'][] = "{$exchangeName} -> {$queueName} [{$routingKey}]";

                    if (! $dryRun) {
                        $this->declareQueueBinding($channel, $queueName, $exchangeName, $routingKey);
                    }
                }
            }

            // Auto-create DLQ queue
            $dlqQueueName = $attribute->getDlqQueueName();
            $dlqExchange = $attribute->getDlqExchangeName();

            if ($dlqExchange !== null) {
                $result['queues'][] = $dlqQueueName;
                $result['bindings'][] = "{$dlqExchange} -> {$dlqQueueName} [{$attribute->getDlqRoutingKey()}]";

                if (! $dryRun) {
                    $this->declareDlqQueue($channel, $attribute);
                }
            }
        }

        return $result;
    }

    /**
     * Declare a single exchange.
     *
     * @throws TopologyException
     */
    public function declareExchange(AMQPChannel $channel, Exchange $exchange): void
    {
        if (isset($this->declaredExchanges[$exchange->name])) {
            return;
        }

        try {
            $arguments = ! empty($exchange->arguments)
                ? new AMQPTable($exchange->arguments)
                : [];

            $channel->exchange_declare(
                $exchange->name,
                $exchange->getTypeValue(),
                false,             // passive
                $exchange->durable,
                $exchange->autoDelete,
                $exchange->internal,
                false,             // nowait
                $arguments
            );

            $this->declaredExchanges[$exchange->name] = true;
        } catch (\Exception $e) {
            throw new TopologyException(
                "Failed to declare exchange [{$exchange->name}]: ".$e->getMessage(),
                entityType: TopologyEntityType::Exchange,
                entityName: $exchange->name,
                previous: $e
            );
        }
    }

    /**
     * Declare a DLQ exchange (always direct type).
     *
     * @throws TopologyException
     */
    protected function declareDlqExchange(AMQPChannel $channel, string $name): void
    {
        if (isset($this->declaredExchanges[$name])) {
            return;
        }

        try {
            $channel->exchange_declare(
                $name,
                'direct',
                false,  // passive
                true,   // durable
                false,  // auto_delete
                false,  // internal
                false   // nowait
            );

            $this->declaredExchanges[$name] = true;
        } catch (\Exception $e) {
            throw new TopologyException(
                "Failed to declare DLQ exchange [{$name}]: ".$e->getMessage(),
                entityType: TopologyEntityType::Exchange,
                entityName: $name,
                previous: $e
            );
        }
    }

    /**
     * Declare the delayed message exchange.
     *
     * Requires the rabbitmq_delayed_message_exchange plugin to be installed.
     *
     * @throws TopologyException
     */
    protected function declareDelayedExchange(AMQPChannel $channel, string $name): void
    {
        if (isset($this->declaredExchanges[$name])) {
            return;
        }

        try {
            $arguments = new AMQPTable([
                'x-delayed-type' => 'topic', // Underlying exchange type for routing
            ]);

            $channel->exchange_declare(
                $name,
                'x-delayed-message',
                false,  // passive
                true,   // durable
                false,  // auto_delete
                false,  // internal
                false,  // nowait
                $arguments
            );

            $this->declaredExchanges[$name] = true;
        } catch (\Exception $e) {
            throw new TopologyException(
                "Failed to declare delayed exchange [{$name}]: ".$e->getMessage().
                ' (Is the rabbitmq_delayed_message_exchange plugin installed?)',
                entityType: TopologyEntityType::Exchange,
                entityName: $name,
                previous: $e
            );
        }
    }

    /**
     * Declare an exchange-to-exchange binding.
     *
     * @throws TopologyException
     */
    protected function declareExchangeBinding(AMQPChannel $channel, Exchange $exchange): void
    {
        if ($exchange->bindTo === null) {
            return;
        }

        try {
            // Bind the source exchange to this exchange as destination
            // exchange_bind(destination, source, routing_key)
            $channel->exchange_bind(
                $exchange->name,           // destination
                $exchange->bindTo,         // source
                $exchange->bindRoutingKey  // routing_key
            );
        } catch (\Exception $e) {
            throw new TopologyException(
                "Failed to bind exchange [{$exchange->name}] to [{$exchange->bindTo}]: ".$e->getMessage(),
                entityType: TopologyEntityType::Binding,
                entityName: "{$exchange->bindTo}->{$exchange->name}",
                previous: $e
            );
        }
    }

    /**
     * Declare a queue from ConsumesQueue attribute.
     *
     * @throws TopologyException
     */
    public function declareQueue(AMQPChannel $channel, ConsumesQueue $attribute): void
    {
        if (isset($this->declaredQueues[$attribute->queue])) {
            return;
        }

        try {
            $arguments = new AMQPTable($attribute->getQueueArguments());

            $channel->queue_declare(
                $attribute->queue,
                false,     // passive
                true,      // durable
                false,     // exclusive
                false,     // auto_delete
                false,     // nowait
                $arguments
            );

            $this->declaredQueues[$attribute->queue] = true;
        } catch (\Exception $e) {
            throw new TopologyException(
                "Failed to declare queue [{$attribute->queue}]: ".$e->getMessage(),
                entityType: TopologyEntityType::Queue,
                entityName: $attribute->queue,
                previous: $e
            );
        }
    }

    /**
     * Declare a DLQ queue.
     *
     * @throws TopologyException
     */
    protected function declareDlqQueue(AMQPChannel $channel, ConsumesQueue $attribute): void
    {
        $dlqQueueName = $attribute->getDlqQueueName();
        $dlqExchange = $attribute->getDlqExchangeName();

        if ($dlqExchange === null || isset($this->declaredQueues[$dlqQueueName])) {
            return;
        }

        try {
            $arguments = new AMQPTable([
                'x-queue-type' => 'quorum',
                'x-message-ttl' => Arr::get($this->config, 'dead_letter.default_ttl', 604800000), // 7 days
            ]);

            // Declare DLQ queue
            $channel->queue_declare(
                $dlqQueueName,
                false,     // passive
                true,      // durable
                false,     // exclusive
                false,     // auto_delete
                false,     // nowait
                $arguments
            );

            // Bind DLQ queue to DLQ exchange
            $channel->queue_bind(
                $dlqQueueName,
                $dlqExchange,
                $attribute->getDlqRoutingKey()
            );

            $this->declaredQueues[$dlqQueueName] = true;
        } catch (\Exception $e) {
            throw new TopologyException(
                "Failed to declare DLQ queue [{$dlqQueueName}]: ".$e->getMessage(),
                entityType: TopologyEntityType::Queue,
                entityName: $dlqQueueName,
                previous: $e
            );
        }
    }

    /**
     * Declare a queue-to-exchange binding.
     *
     * @throws TopologyException
     */
    protected function declareQueueBinding(
        AMQPChannel $channel,
        string $queueName,
        string $exchangeName,
        string $routingKey
    ): void {
        try {
            $channel->queue_bind($queueName, $exchangeName, $routingKey);
        } catch (\Exception $e) {
            throw new TopologyException(
                "Failed to bind queue [{$queueName}] to [{$exchangeName}]: ".$e->getMessage(),
                entityType: TopologyEntityType::Binding,
                entityName: "{$exchangeName}->{$queueName}",
                previous: $e
            );
        }
    }

    /**
     * Delete a queue (use with caution).
     *
     * @throws TopologyException When queue deletion fails
     */
    public function deleteQueue(string $queueName): void
    {
        try {
            $channel = $this->channelManager->topologyChannel();
            $channel->queue_delete($queueName);

            unset($this->declaredQueues[$queueName]);
        } catch (\Exception $e) {
            throw new TopologyException(
                "Failed to delete queue [{$queueName}]: ".$e->getMessage(),
                entityType: TopologyEntityType::Queue,
                entityName: $queueName,
                previous: $e
            );
        }
    }

    /**
     * Purge all messages from a queue.
     *
     * @throws TopologyException When queue purge fails
     */
    public function purgeQueue(string $queueName): int
    {
        try {
            $channel = $this->channelManager->topologyChannel();

            // queue_purge returns the number of messages purged
            return (int) $channel->queue_purge($queueName);
        } catch (\Exception $e) {
            throw new TopologyException(
                "Failed to purge queue [{$queueName}]: ".$e->getMessage(),
                entityType: TopologyEntityType::Queue,
                entityName: $queueName,
                previous: $e
            );
        }
    }

    /**
     * Get queue information.
     *
     * Uses passive queue_declare to get message and consumer counts
     * without modifying the queue.
     *
     * @return array{messages: int, consumers: int, name: string}
     *
     * @throws TopologyException When queue access fails
     */
    public function getQueueInfo(string $queueName): array
    {
        try {
            $channel = $this->channelManager->topologyChannel();

            // Passive declare returns [queue_name, message_count, consumer_count]
            [$name, $messageCount, $consumerCount] = $channel->queue_declare(
                $queueName,
                true,   // passive - only check, don't create
                false,  // durable
                false,  // exclusive
                false   // auto_delete
            );

            return [
                'name' => $name,
                'messages' => $messageCount,
                'consumers' => $consumerCount,
            ];
        } catch (\Exception $e) {
            throw new TopologyException(
                "Failed to get queue info for [{$queueName}]: ".$e->getMessage(),
                entityType: TopologyEntityType::Queue,
                entityName: $queueName,
                previous: $e
            );
        }
    }

    /**
     * Reset declared state (useful for testing).
     */
    public function reset(): void
    {
        $this->declaredExchanges = [];
        $this->declaredQueues = [];
    }
}
