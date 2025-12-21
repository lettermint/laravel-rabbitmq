<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Attributes;

use Attribute;
use InvalidArgumentException;
use Lettermint\RabbitMQ\Enums\ExchangeType;

/**
 * Defines a RabbitMQ exchange.
 *
 * Apply this attribute to a class to declare an exchange. The package will
 * automatically discover and register the exchange with RabbitMQ.
 *
 * Exchange types (use ExchangeType enum):
 * - Topic: Routes based on routing key patterns (*.word.#)
 * - Direct: Routes based on exact routing key match
 * - Fanout: Broadcasts to all bound queues (ignores routing key)
 * - Headers: Routes based on message headers
 * - DelayedMessage: Delays message delivery (requires plugin)
 *
 * @example
 * ```php
 * #[Exchange(name: 'emails', type: ExchangeType::Topic)]
 * class EmailsExchange {}
 *
 * #[Exchange(
 *     name: 'emails.outbound',
 *     type: ExchangeType::Topic,
 *     bindTo: 'emails',
 *     bindRoutingKey: 'outbound.#',
 * )]
 * class EmailsOutboundExchange {}
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Exchange
{
    /**
     * The resolved exchange type enum.
     */
    public readonly ExchangeType $typeEnum;

    /**
     * @param  string  $name  The exchange name (e.g., 'emails', 'emails.outbound')
     * @param  ExchangeType|string  $type  Exchange type (use ExchangeType enum)
     * @param  bool  $durable  Survive broker restart (default: true)
     * @param  bool  $autoDelete  Delete when no bindings exist (default: false)
     * @param  bool  $internal  Only accessible via exchange-to-exchange bindings (default: false)
     * @param  string|null  $bindTo  Parent exchange name for exchange-to-exchange binding
     * @param  string  $bindRoutingKey  Routing key pattern for parent exchange binding
     * @param  array<string, mixed>  $arguments  Additional exchange arguments (e.g., x-delayed-type)
     *
     * @throws InvalidArgumentException When validation fails
     */
    public function __construct(
        public string $name,
        ExchangeType|string $type = ExchangeType::Topic,
        public bool $durable = true,
        public bool $autoDelete = false,
        public bool $internal = false,
        public ?string $bindTo = null,
        public string $bindRoutingKey = '#',
        public array $arguments = [],
    ) {
        // Validate exchange name
        if (trim($this->name) === '') {
            throw new InvalidArgumentException('Exchange name cannot be empty');
        }

        // Validate binding routing key without bindTo
        if ($this->bindRoutingKey !== '#' && $this->bindTo === null) {
            throw new InvalidArgumentException(
                'bindRoutingKey requires bindTo to be set for exchange-to-exchange binding'
            );
        }

        // Convert string to enum
        $this->typeEnum = $type instanceof ExchangeType
            ? $type
            : ExchangeType::from($type);
    }

    /**
     * Get the exchange type as a string value for RabbitMQ declaration.
     */
    public function getTypeValue(): string
    {
        return $this->typeEnum->value;
    }

    /**
     * Get the DLQ exchange name derived from this exchange.
     * Convention: 'emails' -> 'emails.dlq'
     */
    public function getDlqExchangeName(): string
    {
        // Get the root domain (e.g., 'emails.outbound' -> 'emails')
        $parts = explode('.', $this->name);

        return $parts[0].'.dlq';
    }
}
