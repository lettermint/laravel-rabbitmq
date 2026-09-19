<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Tests\Fixtures\Batch;

use Lettermint\RabbitMQ\Batch\BatchItem;
use Lettermint\RabbitMQ\Batch\BatchItemResult;
use Lettermint\RabbitMQ\Contracts\BatchHandler;
use Lettermint\RabbitMQ\Tests\Fixtures\Jobs\SimpleJob;
use RuntimeException;

final class BatchOutcomeHandler implements BatchHandler
{
    public static string $mode = 'mixed';

    public static function jobClasses(): array
    {
        return [SimpleJob::class];
    }

    public function handle(array $items): array
    {
        if (self::$mode === 'throw') {
            throw new RuntimeException('Storage is unavailable.');
        }

        if (self::$mode === 'missing') {
            return [BatchItemResult::success($items[0])];
        }

        if (self::$mode === 'duplicate') {
            return [
                BatchItemResult::success($items[0]),
                BatchItemResult::success($items[0]),
                ...array_map(
                    static fn (BatchItem $item): BatchItemResult => BatchItemResult::success($item),
                    array_slice($items, 1),
                ),
            ];
        }

        return array_map(static function (BatchItem $item): BatchItemResult {
            return match ($item->job->message) {
                'retry' => BatchItemResult::retry($item, new RuntimeException('Retry this item.')),
                'failure' => BatchItemResult::failure($item, new RuntimeException('Reject this item.')),
                default => BatchItemResult::success($item),
            };
        }, $items);
    }
}
