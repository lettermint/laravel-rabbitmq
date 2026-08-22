<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Tests\Fixtures\CachedTopology;

use Lettermint\RabbitMQ\Attributes\ConsumesQueue;

#[ConsumesQueue(
    queue: 'cached:queue',
    bindings: ['cached.jobs' => 'cached:queue'],
    dlqExchange: 'cached.dlx',
    deliveryLimit: 20,
)]
final class CachedQueue {}
