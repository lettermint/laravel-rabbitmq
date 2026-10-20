<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Batch;

/** @internal */
final readonly class CollectedBatch
{
    /**
     * @param  non-empty-list<PendingBatchItem>  $items
     */
    public function __construct(
        public string $id,
        public array $items,
        public int $payloadBytes,
        public float $collectionMilliseconds,
    ) {}
}
