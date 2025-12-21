<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Tests\Fixtures\Exchanges;

use Lettermint\RabbitMQ\Attributes\Exchange;
use Lettermint\RabbitMQ\Enums\ExchangeType;

/**
 * A child exchange bound to the main exchange (exchange-to-exchange binding).
 */
#[Exchange(
    name: 'emails.outbound',
    type: ExchangeType::Topic,
    bindTo: 'emails',
    bindRoutingKey: 'outbound.#',
)]
class ChildExchange {}
