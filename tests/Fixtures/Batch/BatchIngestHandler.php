<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Tests\Fixtures\Batch;

use Lettermint\RabbitMQ\Batch\BatchItem;
use Lettermint\RabbitMQ\Batch\BatchItemResult;
use Lettermint\RabbitMQ\Contracts\BatchHandler;
use RuntimeException;

/**
 * Simulates an analytics store that takes one bulk insert per table.
 *
 * Each job class maps to one table. A storage outage is simulated while the
 * file in RABBITMQ_TEST_OUTAGE_FILE exists: the whole handler call throws.
 */
final class BatchIngestHandler implements BatchHandler
{
    public static function jobClasses(): array
    {
        return [BatchStorageJob::class, BatchMetricJob::class, BatchDeadlineJob::class];
    }

    public function handle(array $items): array
    {
        /** @var BatchStorageJob|BatchMetricJob|BatchDeadlineJob $first */
        $first = $items[0]->job;
        $marker = $first->marker;
        $outageFile = (string) getenv('RABBITMQ_TEST_OUTAGE_FILE');

        if ($outageFile !== '' && file_exists($outageFile)) {
            $ids = implode(',', array_map(static fn (BatchItem $item): string => $item->job->id, $items));
            file_put_contents($marker, "outage:{$ids}\n", FILE_APPEND | LOCK_EX);

            throw new RuntimeException('The analytics store is unavailable.');
        }

        $delay = (int) getenv('RABBITMQ_TEST_HANDLER_DELAY_MS');

        if ($delay > 0) {
            usleep($delay * 1000);
        }

        $tables = [];

        foreach ($items as $item) {
            $table = match (true) {
                $item->job instanceof BatchMetricJob => 'metrics',
                $item->job instanceof BatchDeadlineJob => 'deadlines',
                default => 'events',
            };
            $tables[$table][] = $item->job->id.'@'.$item->attempt;
        }

        foreach ($tables as $table => $rows) {
            file_put_contents($marker, "insert:{$table}:".implode(',', $rows)."\n", FILE_APPEND | LOCK_EX);
        }

        return array_map(
            static fn (BatchItem $item): BatchItemResult => ($item->job->outcome ?? null) === 'retry'
                ? BatchItemResult::retry($item, new RuntimeException('Retry the fake write.'))
                : BatchItemResult::success($item),
            $items,
        );
    }
}
