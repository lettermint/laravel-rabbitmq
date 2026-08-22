<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Tests\Fixtures\CachedTopology;

use Lettermint\RabbitMQ\Attributes\Exchange;
use Lettermint\RabbitMQ\Enums\ExchangeType;

#[Exchange(name: 'cached.dlx', type: ExchangeType::Direct)]
final class CachedDeadLettersExchange {}
