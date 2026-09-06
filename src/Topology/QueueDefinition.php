<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Topology;

final readonly class QueueDefinition
{
    /**
     * @param  array<string, list<string>>  $bindings
     */
    public function __construct(
        public string $logicalName,
        public string $physicalName,
        public array $bindings,
        public bool $quorum,
        public bool $singleActiveConsumer,
        public ?int $deliveryLimit,
        public ?int $maxLength,
        public ?int $maxLengthBytes,
        public ?int $messageTtl,
        public ?int $maxPriority,
        public string $overflow,
        public bool $deadLetterEnabled,
        public string $deadLetterExchange,
        public string $deadLetterQueue,
        public string $deadLetterRoutingKey,
    ) {}

    public function publishExchange(): string
    {
        return array_key_first($this->bindings) ?? '';
    }

    public function publishRoutingKey(): string
    {
        $routingKeys = array_values($this->bindings)[0] ?? [];

        if (! isset($routingKeys[0])) {
            return $this->physicalName;
        }

        if (str_contains($routingKeys[0], '*') || str_contains($routingKeys[0], '#')) {
            return str_replace(':', '.', $this->logicalName);
        }

        return $routingKeys[0];
    }

    /**
     * @return array<string, mixed>
     */
    public function queueArguments(): array
    {
        $arguments = [];

        if ($this->quorum) {
            $arguments['x-queue-type'] = 'quorum';
            $arguments['x-overflow'] = $this->overflow;
        }

        if ($this->singleActiveConsumer) {
            $arguments['x-single-active-consumer'] = true;
        }

        if ($this->deliveryLimit !== null) {
            $arguments['x-delivery-limit'] = $this->deliveryLimit;
        }

        if ($this->maxLength !== null) {
            $arguments['x-max-length'] = $this->maxLength;
            $arguments['x-overflow'] = $this->overflow;
        }

        if ($this->maxLengthBytes !== null) {
            $arguments['x-max-length-bytes'] = $this->maxLengthBytes;
        }

        if ($this->messageTtl !== null) {
            $arguments['x-message-ttl'] = $this->messageTtl;
        }

        if ($this->maxPriority !== null) {
            $arguments['x-max-priority'] = $this->maxPriority;
        }

        if ($this->deadLetterEnabled) {
            $arguments['x-dead-letter-exchange'] = $this->deadLetterExchange;
            $arguments['x-dead-letter-routing-key'] = $this->deadLetterRoutingKey;

            if ($this->quorum) {
                $arguments['x-dead-letter-strategy'] = 'at-least-once';
            }
        }

        return $arguments;
    }

    /**
     * @return array<string, mixed>
     */
    public function deadLetterQueueArguments(): array
    {
        return [
            'x-queue-type' => 'quorum',
            'x-overflow' => 'reject-publish',
            'x-delivery-limit' => -1,
        ];
    }
}
