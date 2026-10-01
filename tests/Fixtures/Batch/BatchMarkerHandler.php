<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Tests\Fixtures\Batch;

use Lettermint\RabbitMQ\Batch\BatchItem;
use Lettermint\RabbitMQ\Batch\BatchItemResult;
use Lettermint\RabbitMQ\Contracts\BatchHandler;
use Lettermint\RabbitMQ\Tests\Fixtures\Jobs\SimpleJob;

final class BatchMarkerHandler implements BatchHandler
{
    public static int $calls = 0;

    /** @var list<int> */
    public static array $sizes = [];

    public static function jobClasses(): array
    {
        return [SimpleJob::class];
    }

    public function handle(array $items): array
    {
        self::$calls++;
        self::$sizes[] = count($items);

        return array_map(
            static fn (BatchItem $item): BatchItemResult => BatchItemResult::success($item),
            $items,
        );
    }

    public static function reset(): void
    {
        self::$calls = 0;
        self::$sizes = [];
    }
}
