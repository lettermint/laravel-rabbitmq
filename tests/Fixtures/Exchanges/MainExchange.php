<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Tests\Fixtures\Exchanges;

use Lettermint\RabbitMQ\Attributes\Exchange;
use Lettermint\RabbitMQ\Enums\ExchangeType;

/**
 * A top-level topic exchange.
 */
#[Exchange(
    name: 'emails',
    type: ExchangeType::Topic,
)]
class MainExchange {}
