<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Contracts;

use Lettermint\RabbitMQ\Batch\BatchItem;
use Lettermint\RabbitMQ\Batch\BatchItemResult;

interface BatchHandler
{
    /**
     * List each Laravel job class that this handler accepts.
     *
     * @return non-empty-list<class-string>
     */
    public static function jobClasses(): array;

    /**
     * Process one batch and return one result for each item.
     *
     * Processing is at least once. RabbitMQ can redeliver an item when the
     * application writes succeed but delivery settlement is not confirmed.
     * The handler must make its writes idempotent.
     *
     * @param  non-empty-list<BatchItem>  $items
     * @return list<BatchItemResult>
     */
    public function handle(array $items): array;
}
