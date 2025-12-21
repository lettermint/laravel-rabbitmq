<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Tests\Fixtures\Exchanges;

use Lettermint\RabbitMQ\Attributes\Exchange;
use Lettermint\RabbitMQ\Enums\ExchangeType;

/**
 * A delayed message exchange (requires rabbitmq_delayed_message_exchange plugin).
 */
#[Exchange(
    name: 'delayed',
    type: ExchangeType::DelayedMessage,
    arguments: ['x-delayed-type' => 'topic'],
)]
class DelayedExchange {}
