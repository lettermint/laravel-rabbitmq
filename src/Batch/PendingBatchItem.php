<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Batch;

use Lettermint\RabbitMQ\Queue\RabbitMQJob;

/** @internal */
final readonly class PendingBatchItem
{
    public function __construct(
        public RabbitMQJob $delivery,
        public BatchItem $item,
    ) {}
}
