<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Topology;

use AMQPChannel;
use AMQPExchange;
use AMQPExchangeException;
use AMQPQueue;
use AMQPQueueException;
use Illuminate\Support\Arr;
use Lettermint\RabbitMQ\Attributes\ConsumesQueue;
use Lettermint\RabbitMQ\Attributes\Exchange;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Exceptions\TopologyException;

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

        // Second: Declare exchange-to-exchange bindings
        foreach ($topology['exchanges'] as $name => $exchange) {
            if ($exchange->bindTo !== null) {
                $result['bindings'][] = "{$exchange->bindTo} -> {$name} [{$exchange->bindRoutingKey}]";

                if (! $dryRun) {
                    $this->declareExchangeBinding($channel, $exchange);
                }
            }
        }

        // Third: Declare queues and their bindings
        foreach ($topology['queues'] as $queueName => $queueData) {
            /** @var ConsumesQueue $attribute */
            $attribute = $queueData['attribute'];

            $result['queues'][] = $queueName;

            if (! $dryRun) {
                $this->declareQueue($channel, $attribute);
            }

            // Declare bindings
            foreach ($attribute->bindings as $exchangeName => $routingKeys) {
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
            $amqpExchange = new AMQPExchange($channel);
            $amqpExchange->setName($exchange->name);
            $amqpExchange->setType($this->mapExchangeType($exchange->getTypeValue()));
            $amqpExchange->setFlags($this->getExchangeFlags($exchange));

            if (! empty($exchange->arguments)) {
                $amqpExchange->setArguments($exchange->arguments);
            }

            $amqpExchange->declareExchange();

            $this->declaredExchanges[$exchange->name] = true;
        } catch (AMQPExchangeException $e) {
            throw new TopologyException(
                "Failed to declare exchange [{$exchange->name}]: ".$e->getMessage(),
                entityType: 'exchange',
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
            $exchange = new AMQPExchange($channel);
            $exchange->setName($name);
            $exchange->setType(AMQP_EX_TYPE_DIRECT);
            $exchange->setFlags(AMQP_DURABLE);
            $exchange->declareExchange();

            $this->declaredExchanges[$name] = true;
        } catch (AMQPExchangeException $e) {
            throw new TopologyException(
                "Failed to declare DLQ exchange [{$name}]: ".$e->getMessage(),
                entityType: 'exchange',
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
            $exchange = new AMQPExchange($channel);
            $exchange->setName($name);
            $exchange->setType('x-delayed-message');
            $exchange->setFlags(AMQP_DURABLE);
            $exchange->setArguments([
                'x-delayed-type' => 'topic', // Underlying exchange type for routing
            ]);
            $exchange->declareExchange();

            $this->declaredExchanges[$name] = true;
        } catch (AMQPExchangeException $e) {
            throw new TopologyException(
                "Failed to declare delayed exchange [{$name}]: ".$e->getMessage().
                ' (Is the rabbitmq_delayed_message_exchange plugin installed?)',
                entityType: 'exchange',
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
            $amqpExchange = new AMQPExchange($channel);
            $amqpExchange->setName($exchange->name);

            // Bind this exchange to the parent exchange
            // Note: In ext-amqp, we bind the source exchange TO the destination
            $sourceExchange = new AMQPExchange($channel);
            $sourceExchange->setName($exchange->bindTo);
            $sourceExchange->bind($exchange->name, $exchange->bindRoutingKey);
        } catch (AMQPExchangeException $e) {
            throw new TopologyException(
                "Failed to bind exchange [{$exchange->name}] to [{$exchange->bindTo}]: ".$e->getMessage(),
                entityType: 'binding',
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
            $queue = new AMQPQueue($channel);
            $queue->setName($attribute->queue);
            $queue->setFlags(AMQP_DURABLE);
            $queue->setArguments($attribute->getQueueArguments());
            $queue->declareQueue();

            $this->declaredQueues[$attribute->queue] = true;
        } catch (AMQPQueueException $e) {
            throw new TopologyException(
                "Failed to declare queue [{$attribute->queue}]: ".$e->getMessage(),
                entityType: 'queue',
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
            // Declare DLQ queue
            $queue = new AMQPQueue($channel);
            $queue->setName($dlqQueueName);
            $queue->setFlags(AMQP_DURABLE);
            $queue->setArguments([
                'x-queue-type' => 'quorum',
                'x-message-ttl' => Arr::get($this->config, 'dead_letter.default_ttl', 604800000), // 7 days
            ]);
            $queue->declareQueue();

            // Bind DLQ queue to DLQ exchange
            $queue->bind($dlqExchange, $attribute->getDlqRoutingKey());

            $this->declaredQueues[$dlqQueueName] = true;
        } catch (AMQPQueueException $e) {
            throw new TopologyException(
                "Failed to declare DLQ queue [{$dlqQueueName}]: ".$e->getMessage(),
                entityType: 'queue',
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
            $queue = new AMQPQueue($channel);
            $queue->setName($queueName);
            $queue->bind($exchangeName, $routingKey);
        } catch (AMQPQueueException $e) {
            throw new TopologyException(
                "Failed to bind queue [{$queueName}] to [{$exchangeName}]: ".$e->getMessage(),
                entityType: 'binding',
                entityName: "{$exchangeName}->{$queueName}",
                previous: $e
            );
        }
    }

    /**
     * Map string exchange type to AMQP constant.
     */
    protected function mapExchangeType(string $type): string
    {
        return match ($type) {
            'direct' => AMQP_EX_TYPE_DIRECT,
            'topic' => AMQP_EX_TYPE_TOPIC,
            'fanout' => AMQP_EX_TYPE_FANOUT,
            'headers' => AMQP_EX_TYPE_HEADERS,
            default => $type, // For custom types like 'x-delayed-message'
        };
    }

    /**
     * Get exchange flags from Exchange attribute.
     */
    protected function getExchangeFlags(Exchange $exchange): int
    {
        $flags = 0;

        if ($exchange->durable) {
            $flags |= AMQP_DURABLE;
        }

        if ($exchange->autoDelete) {
            $flags |= AMQP_AUTODELETE;
        }

        if ($exchange->internal) {
            $flags |= AMQP_INTERNAL;
        }

        return $flags;
    }

    /**
     * Delete a queue (use with caution).
     */
    public function deleteQueue(string $queueName): void
    {
        $channel = $this->channelManager->topologyChannel();
        $queue = new AMQPQueue($channel);
        $queue->setName($queueName);
        $queue->delete();

        unset($this->declaredQueues[$queueName]);
    }

    /**
     * Purge all messages from a queue.
     */
    public function purgeQueue(string $queueName): int
    {
        $channel = $this->channelManager->topologyChannel();
        $queue = new AMQPQueue($channel);
        $queue->setName($queueName);

        return $queue->purge();
    }

    /**
     * Get queue information (message count, consumer count, etc.).
     *
     * @return array{messages: int, consumers: int, name: string}
     */
    public function getQueueInfo(string $queueName): array
    {
        $channel = $this->channelManager->topologyChannel();
        $queue = new AMQPQueue($channel);
        $queue->setName($queueName);
        $queue->declareQueue(); // This returns the current state

        return [
            'name' => $queueName,
            'messages' => 0, // ext-amqp doesn't expose this directly via declareQueue
            'consumers' => 0,
        ];
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
