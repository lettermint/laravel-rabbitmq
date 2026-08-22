<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Tests\Fixtures\CachedTopology;

use Lettermint\RabbitMQ\Attributes\Exchange;
use Lettermint\RabbitMQ\Enums\ExchangeType;

#[Exchange(name: 'cached.jobs', type: ExchangeType::Direct)]
final class CachedJobsExchange {}
