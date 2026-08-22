<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Topology;

use Illuminate\Support\Arr;
use InvalidArgumentException;
use Lettermint\RabbitMQ\Attributes\ConsumesQueue;
use Lettermint\RabbitMQ\Attributes\Exchange;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Enums\TopologyEntityType;
use Lettermint\RabbitMQ\Exceptions\TopologyException;
use Lettermint\RabbitMQ\Exceptions\UnknownBindingException;
use Lettermint\RabbitMQ\Exceptions\UnknownQueueException;
use Lettermint\RabbitMQ\Support\ExceptionReporter;

final class TopologyRegistry
{
    /** @var array<string, QueueDefinition>|null */
    private ?array $queues = null;

    /** @var array<string, array{name: string, type: string, durable: bool, auto_delete: bool, internal: bool, arguments: array<string, mixed>, bind_to: string|null, bind_routing_key: string}>|null */
    private ?array $exchanges = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly AttributeScanner $scanner,
        private readonly array $config,
    ) {}

    public function isStrict(): bool
    {
        return (bool) Arr::get($this->config, 'strict_topology', false);
    }

    public function hasExplicitTopology(): bool
    {
        return Arr::get($this->config, 'topology.queues', []) !== [];
    }

    /** @return list<string> */
    public function logicalQueueNames(): array
    {
        return array_keys($this->queues());
    }

    /** @return list<string> */
    public function deadLetterQueueNames(): array
    {
        return array_keys(array_filter(
            $this->queues(),
            fn (QueueDefinition $definition): bool => $definition->deadLetterEnabled,
        ));
    }

    /** @return array<string, QueueDefinition> */
    public function queues(): array
    {
        if ($this->queues !== null) {
            return $this->queues;
        }

        if ($this->isStrict() && ! $this->hasExplicitTopology()) {
            throw new TopologyException('Strict RabbitMQ topology mode requires an explicit topology.queues registry.');
        }

        $this->queues = $this->hasExplicitTopology()
            ? $this->buildExplicitQueues()
            : $this->buildAttributeQueues();

        return $this->queues;
    }

    /**
     * @return array<string, array{name: string, type: string, durable: bool, auto_delete: bool, internal: bool, arguments: array<string, mixed>, bind_to: string|null, bind_routing_key: string}>
     */
    public function exchanges(): array
    {
        if ($this->exchanges !== null) {
            return $this->exchanges;
        }

        $this->exchanges = $this->hasExplicitTopology()
            ? $this->buildExplicitExchanges()
            : $this->buildAttributeExchanges();

        return $this->exchanges;
    }

    public function queue(string $logicalName): QueueDefinition
    {
        $this->assertValidName($logicalName, 'queue');
        $definition = $this->queues()[$logicalName] ?? null;

        if ($definition !== null) {
            return $definition;
        }

        $exception = new UnknownQueueException($logicalName, $this->logicalQueueNames());

        if ($this->isStrict()) {
            ExceptionReporter::report($exception);
            throw $exception;
        }

        $physicalName = $this->physicalName($logicalName);
        $deadLetterQueue = $this->physicalName($this->deadLetterQueuePrefix().$logicalName);
        $this->assertValidName($physicalName, 'physical queue');
        $this->assertValidName($deadLetterQueue, 'physical queue');

        return new QueueDefinition(
            logicalName: $logicalName,
            physicalName: $physicalName,
            bindings: ['' => [$physicalName]],
            quorum: true,
            singleActiveConsumer: false,
            deliveryLimit: null,
            maxLength: null,
            maxLengthBytes: null,
            messageTtl: null,
            maxPriority: null,
            overflow: 'reject-publish',
            deadLetterEnabled: false,
            deadLetterExchange: '',
            deadLetterQueue: $deadLetterQueue,
            deadLetterRoutingKey: $logicalName,
        );
    }

    public function physicalQueue(string $logicalName): string
    {
        return $this->queue($logicalName)->physicalName;
    }

    public function validateRoutingKey(string $logicalQueue, string $routingKey): string
    {
        $this->assertValidRoutingKey($routingKey);
        $definition = $this->queue($logicalQueue);

        foreach ($definition->bindings as $patterns) {
            foreach ($patterns as $pattern) {
                if ($this->routingKeyMatches($routingKey, $pattern)) {
                    return $routingKey;
                }
            }
        }

        $exception = new UnknownBindingException($logicalQueue, $routingKey);
        ExceptionReporter::report($exception);

        throw $exception;
    }

    public function physicalName(string $name): string
    {
        return (string) Arr::get($this->config, 'physical_prefix', '').$name;
    }

    public function reset(): void
    {
        $this->queues = null;
        $this->exchanges = null;
    }

    /** @return array<string, QueueDefinition> */
    private function buildExplicitQueues(): array
    {
        $queues = [];
        $configured = Arr::get($this->config, 'topology.queues', []);

        if (! is_array($configured)) {
            throw new TopologyException('RabbitMQ topology.queues must be an array.');
        }

        foreach ($configured as $logicalName => $settings) {
            if (! is_string($logicalName) || ! is_array($settings)) {
                throw new TopologyException('Each RabbitMQ topology queue must have a string name and an array definition.');
            }

            $this->assertValidName($logicalName, 'queue');
            $bindings = $this->normalizeExplicitBindings($logicalName, $settings);
            $quorum = (bool) ($settings['quorum'] ?? true);
            $maxPriority = isset($settings['max_priority']) ? (int) $settings['max_priority'] : null;
            $deliveryLimit = isset($settings['delivery_limit']) ? (int) $settings['delivery_limit'] : null;
            $maxLength = isset($settings['max_length']) ? (int) $settings['max_length'] : null;
            $maxLengthBytes = isset($settings['max_length_bytes']) ? (int) $settings['max_length_bytes'] : null;
            $messageTtl = isset($settings['message_ttl']) ? (int) $settings['message_ttl'] : null;
            $overflow = (string) ($settings['overflow'] ?? 'reject-publish');

            if ($quorum && $maxPriority !== null) {
                throw new TopologyException(
                    "Queue [{$logicalName}] cannot use max_priority with a quorum queue.",
                    TopologyEntityType::Queue,
                    $logicalName,
                );
            }

            if ($maxPriority !== null && ($maxPriority < 0 || $maxPriority > 255)) {
                throw new TopologyException("Queue [{$logicalName}] has an invalid max_priority value.");
            }

            if ($deliveryLimit !== null && (! $quorum || $deliveryLimit < 1)) {
                throw new TopologyException("Queue [{$logicalName}] has an invalid delivery_limit value.");
            }

            if (($maxLength !== null && $maxLength < 1) || ($maxLengthBytes !== null && $maxLengthBytes < 1)) {
                throw new TopologyException("Queue [{$logicalName}] has an invalid length limit.");
            }

            if ($messageTtl !== null && $messageTtl < 0) {
                throw new TopologyException("Queue [{$logicalName}] has an invalid message_ttl value.");
            }

            if ($quorum && $overflow !== 'reject-publish') {
                throw new TopologyException(
                    "Quorum queue [{$logicalName}] must use reject-publish for at-least-once dead lettering."
                );
            }

            $deadLetterEnabled = (bool) ($settings['dead_letter'] ?? Arr::get($this->config, 'dead_letter.enabled', true));
            $deadLetterExchange = $this->physicalName((string) ($settings['dead_letter_exchange'] ?? Arr::get($this->config, 'dead_letter.exchange', 'dlx')));
            $deadLetterRoutingKey = (string) ($settings['dead_letter_routing_key'] ?? $logicalName);
            $this->assertValidRoutingKey($deadLetterRoutingKey);

            if ($deadLetterEnabled) {
                $matchingExchange = collect($this->exchanges())->first(
                    fn (array $exchange): bool => $exchange['name'] === $deadLetterExchange
                );

                if (! is_array($matchingExchange) || $matchingExchange['type'] !== 'direct') {
                    throw new TopologyException(
                        "Queue [{$logicalName}] requires the direct dead-letter exchange [{$deadLetterExchange}].",
                        TopologyEntityType::Exchange,
                        $deadLetterExchange,
                    );
                }
            }

            $queues[$logicalName] = new QueueDefinition(
                logicalName: $logicalName,
                physicalName: $this->physicalName($logicalName),
                bindings: $bindings,
                quorum: $quorum,
                singleActiveConsumer: (bool) ($settings['single_active_consumer'] ?? false),
                deliveryLimit: $deliveryLimit,
                maxLength: $maxLength,
                maxLengthBytes: $maxLengthBytes,
                messageTtl: $messageTtl,
                maxPriority: $maxPriority,
                overflow: $overflow,
                deadLetterEnabled: $deadLetterEnabled,
                deadLetterExchange: $deadLetterExchange,
                deadLetterQueue: $this->physicalName($this->deadLetterQueuePrefix().$logicalName),
                deadLetterRoutingKey: $deadLetterRoutingKey,
            );
        }

        $this->validateQueueDefinitions($queues);

        return $queues;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, list<string>>
     */
    private function normalizeExplicitBindings(string $logicalName, array $settings): array
    {
        $configuredBindings = $settings['bindings'] ?? null;

        if ($configuredBindings === null) {
            $exchange = (string) ($settings['exchange'] ?? 'jobs');
            $routingKey = (string) ($settings['routing_key'] ?? $logicalName);
            $configuredBindings = [$exchange => [$routingKey]];
        }

        if (! is_array($configuredBindings) || $configuredBindings === []) {
            throw new TopologyException("Queue [{$logicalName}] must have at least one binding.");
        }

        $knownExchanges = $this->exchanges();
        $bindings = [];

        foreach ($configuredBindings as $exchange => $routingKeys) {
            if (! is_string($exchange) || ! isset($knownExchanges[$exchange])) {
                throw new TopologyException(
                    "Queue [{$logicalName}] refers to unknown exchange [{$exchange}].",
                    TopologyEntityType::Binding,
                    $logicalName,
                );
            }

            $keys = is_array($routingKeys) ? $routingKeys : [$routingKeys];
            $normalizedKeys = [];

            foreach ($keys as $routingKey) {
                if (! is_string($routingKey)) {
                    throw new TopologyException("Queue [{$logicalName}] has a non-string routing key.");
                }

                $this->assertValidRoutingKey($routingKey, allowWildcards: true);
                $normalizedKeys[] = $routingKey;
            }

            if ($normalizedKeys === []) {
                throw new TopologyException("Queue [{$logicalName}] has an empty binding for exchange [{$exchange}].");
            }

            $bindings[$knownExchanges[$exchange]['name']] = array_values(array_unique($normalizedKeys));
        }

        return $bindings;
    }

    /** @return array<string, QueueDefinition> */
    private function buildAttributeQueues(): array
    {
        $queues = [];
        $knownExchanges = array_column($this->exchanges(), 'name', 'name');

        foreach ($this->scanner->getTopology()['queues'] as $logicalName => $data) {
            /** @var ConsumesQueue $attribute */
            $attribute = $data['attribute'];
            $bindings = [];

            foreach ($data['allBindings'] as $exchange => $routingKeys) {
                $physicalExchange = $this->physicalName($exchange);

                if (! isset($knownExchanges[$physicalExchange])) {
                    throw new TopologyException(
                        "Queue [{$logicalName}] refers to unknown exchange [{$exchange}].",
                        TopologyEntityType::Binding,
                        $logicalName,
                    );
                }

                $bindings[$physicalExchange] = array_values($routingKeys);
            }

            if ($bindings === []) {
                $bindings = ['' => [$this->physicalName($logicalName)]];
            }

            $dlqExchange = $attribute->getDlqExchangeName();

            if ($dlqExchange !== null) {
                $physicalDlqExchange = $this->physicalName($dlqExchange);
                $registeredDlqExchange = $this->findExchangeByPhysicalName($physicalDlqExchange);

                if ($registeredDlqExchange !== null && $registeredDlqExchange['type'] !== 'direct') {
                    throw new TopologyException(
                        "Queue [{$logicalName}] requires the direct dead-letter exchange [{$physicalDlqExchange}]."
                    );
                }
            }

            $queues[$logicalName] = new QueueDefinition(
                logicalName: $logicalName,
                physicalName: $this->physicalName($logicalName),
                bindings: $bindings,
                quorum: $attribute->quorum,
                singleActiveConsumer: $attribute->singleActiveConsumer,
                deliveryLimit: $attribute->deliveryLimit,
                maxLength: $attribute->maxLength,
                maxLengthBytes: null,
                messageTtl: $attribute->messageTtl,
                maxPriority: $attribute->maxPriority,
                overflow: $attribute->quorum ? 'reject-publish' : $attribute->overflowEnum->value,
                deadLetterEnabled: $dlqExchange !== null,
                deadLetterExchange: $dlqExchange === null ? '' : $this->physicalName($dlqExchange),
                deadLetterQueue: $this->physicalName($attribute->getDlqQueueName()),
                deadLetterRoutingKey: $attribute->getDlqRoutingKey(),
            );
        }

        $this->validateQueueDefinitions($queues);

        return $queues;
    }

    /**
     * @return array<string, array{name: string, type: string, durable: bool, auto_delete: bool, internal: bool, arguments: array<string, mixed>, bind_to: string|null, bind_routing_key: string}>
     */
    private function buildExplicitExchanges(): array
    {
        $configured = Arr::get($this->config, 'topology.exchanges', []);

        if (! is_array($configured) || $configured === []) {
            $configured = [
                'jobs' => ['type' => 'direct'],
                'dlx' => ['type' => 'direct'],
            ];
        }

        $exchanges = [];
        $physicalNames = [];

        foreach ($configured as $logicalName => $settings) {
            if (! is_string($logicalName) || ! is_array($settings)) {
                throw new TopologyException('Each RabbitMQ topology exchange must have a string name and an array definition.');
            }

            $this->assertValidName($logicalName, 'exchange');
            $type = (string) ($settings['type'] ?? 'direct');

            if (! in_array($type, ['direct', 'topic', 'fanout'], true)) {
                throw new TopologyException("Exchange [{$logicalName}] has unsupported type [{$type}].");
            }

            $physicalName = $this->physicalName((string) ($settings['name'] ?? $logicalName));
            $this->assertValidName($physicalName, 'physical exchange');

            if (isset($physicalNames[$physicalName])) {
                throw new TopologyException(
                    "Exchanges [{$physicalNames[$physicalName]}] and [{$logicalName}] use the same physical name [{$physicalName}]."
                );
            }

            $physicalNames[$physicalName] = $logicalName;

            $exchanges[$logicalName] = [
                'name' => $physicalName,
                'type' => $type,
                'durable' => (bool) ($settings['durable'] ?? true),
                'auto_delete' => (bool) ($settings['auto_delete'] ?? false),
                'internal' => (bool) ($settings['internal'] ?? false),
                'arguments' => is_array($settings['arguments'] ?? null) ? $settings['arguments'] : [],
                'bind_to' => null,
                'bind_routing_key' => (string) ($settings['bind_routing_key'] ?? '#'),
            ];
        }

        foreach ($configured as $logicalName => $settings) {
            $parent = $settings['bind_to'] ?? null;

            if ($parent === null) {
                continue;
            }

            if (! is_string($parent) || ! isset($exchanges[$parent]) || $parent === $logicalName) {
                throw new TopologyException("Exchange [{$logicalName}] has an invalid parent exchange [{$parent}].");
            }

            $this->assertValidRoutingKey($exchanges[$logicalName]['bind_routing_key'], allowWildcards: true);
            $exchanges[$logicalName]['bind_to'] = $exchanges[$parent]['name'];
        }

        return $exchanges;
    }

    /**
     * @return array<string, array{name: string, type: string, durable: bool, auto_delete: bool, internal: bool, arguments: array<string, mixed>, bind_to: string|null, bind_routing_key: string}>
     */
    private function buildAttributeExchanges(): array
    {
        $exchanges = [];
        $attributes = $this->scanner->getTopology()['exchanges'];
        $physicalNames = [];

        foreach ($attributes as $logicalName => $attribute) {
            /** @var Exchange $attribute */
            $type = $attribute->getTypeValue();

            if (! in_array($type, ['direct', 'topic', 'fanout'], true)) {
                throw new TopologyException("Exchange [{$logicalName}] has unsupported type [{$type}].");
            }

            $physicalName = $this->physicalName($attribute->name);
            $this->assertValidName($physicalName, 'physical exchange');

            if (isset($physicalNames[$physicalName])) {
                throw new TopologyException(
                    "Exchanges [{$physicalNames[$physicalName]}] and [{$logicalName}] use the same physical name [{$physicalName}]."
                );
            }

            $physicalNames[$physicalName] = $logicalName;
            $exchanges[$logicalName] = [
                'name' => $physicalName,
                'type' => $type,
                'durable' => $attribute->durable,
                'auto_delete' => $attribute->autoDelete,
                'internal' => $attribute->internal,
                'arguments' => $attribute->arguments,
                'bind_to' => null,
                'bind_routing_key' => $attribute->bindRoutingKey,
            ];
        }

        foreach ($attributes as $logicalName => $attribute) {
            if ($attribute->bindTo === null) {
                continue;
            }

            if (! isset($exchanges[$attribute->bindTo]) || $attribute->bindTo === $logicalName) {
                throw new TopologyException(
                    "Exchange [{$logicalName}] has an invalid parent exchange [{$attribute->bindTo}]."
                );
            }

            $this->assertValidRoutingKey($attribute->bindRoutingKey, allowWildcards: true);
            $exchanges[$logicalName]['bind_to'] = $exchanges[$attribute->bindTo]['name'];
        }

        return $exchanges;
    }

    /** @param array<string, QueueDefinition> $queues */
    private function validateQueueDefinitions(array $queues): void
    {
        $physicalNames = [];

        foreach ($queues as $logicalName => $definition) {
            $names = [$definition->physicalName => 'main'];

            if ($definition->deadLetterEnabled) {
                $names[$definition->deadLetterQueue] = 'dead-letter';
            }

            foreach ($names as $physicalName => $role) {
                $this->assertValidName($physicalName, 'physical queue');

                if (isset($physicalNames[$physicalName])) {
                    throw new TopologyException(
                        "Queue [{$logicalName}] {$role} name conflicts with [{$physicalNames[$physicalName]}] at [{$physicalName}].",
                        TopologyEntityType::Queue,
                        $physicalName,
                    );
                }

                $physicalNames[$physicalName] = "{$logicalName} {$role} queue";
            }
        }
    }

    private function deadLetterQueuePrefix(): string
    {
        return (string) Arr::get($this->config, 'dead_letter.queue_prefix', 'dlq:');
    }

    /**
     * @return array{name: string, type: string, durable: bool, auto_delete: bool, internal: bool, arguments: array<string, mixed>, bind_to: string|null, bind_routing_key: string}|null
     */
    private function findExchangeByPhysicalName(string $physicalName): ?array
    {
        foreach ($this->exchanges() as $exchange) {
            if ($exchange['name'] === $physicalName) {
                return $exchange;
            }
        }

        return null;
    }

    private function assertValidName(string $name, string $type): void
    {
        if (trim($name) === '' || strlen($name) > 255 || preg_match('/[\x00-\x20\x7F*#]/', $name) === 1) {
            throw new TopologyException(
                "RabbitMQ {$type} name [{$name}] is invalid.",
                str_contains($type, 'queue') ? TopologyEntityType::Queue : TopologyEntityType::Exchange,
                $name,
            );
        }
    }

    private function assertValidRoutingKey(string $routingKey, bool $allowWildcards = false): void
    {
        if (trim($routingKey) === '' || strlen($routingKey) > 255 || preg_match('/[\x00-\x1F\x7F]/', $routingKey) === 1) {
            throw new InvalidArgumentException('RabbitMQ routing keys must be non-empty strings of at most 255 bytes without control characters.');
        }

        if (! $allowWildcards && (str_contains($routingKey, '*') || str_contains($routingKey, '#'))) {
            throw new InvalidArgumentException('RabbitMQ publish routing keys cannot contain wildcards.');
        }
    }

    private function routingKeyMatches(string $routingKey, string $pattern): bool
    {
        $routingWords = explode('.', $routingKey);
        $patternWords = explode('.', $pattern);
        $memo = [];

        $matches = function (int $routingIndex, int $patternIndex) use (&$matches, &$memo, $routingWords, $patternWords): bool {
            $key = $routingIndex.':'.$patternIndex;

            if (array_key_exists($key, $memo)) {
                return $memo[$key];
            }

            if ($patternIndex === count($patternWords)) {
                return $memo[$key] = $routingIndex === count($routingWords);
            }

            $word = $patternWords[$patternIndex];

            if ($word === '#') {
                return $memo[$key] = $matches($routingIndex, $patternIndex + 1)
                    || ($routingIndex < count($routingWords) && $matches($routingIndex + 1, $patternIndex));
            }

            if ($routingIndex >= count($routingWords)) {
                return $memo[$key] = false;
            }

            if ($word !== '*' && ! hash_equals($word, $routingWords[$routingIndex])) {
                return $memo[$key] = false;
            }

            return $memo[$key] = $matches($routingIndex + 1, $patternIndex + 1);
        };

        return $matches(0, 0);
    }
}
