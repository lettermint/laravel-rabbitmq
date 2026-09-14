<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Tests\Fixtures\Batch;

use Lettermint\RabbitMQ\Batch\BatchItem;
use Lettermint\RabbitMQ\Batch\BatchItemResult;
use Lettermint\RabbitMQ\Connection\ConnectionManager;
use Lettermint\RabbitMQ\Contracts\BatchHandler;
use RuntimeException;

final class BatchStorageHandler implements BatchHandler
{
    public static function jobClasses(): array
    {
        return [BatchStorageJob::class];
    }

    public function handle(array $items): array
    {
        $jobs = array_map(
            static fn (BatchItem $item): BatchStorageJob => $item->job,
            $items,
        );
        $marker = $jobs[0]->marker;
        $ids = implode(',', array_map(static fn (BatchStorageJob $job): string => $job->id, $jobs));
        file_put_contents($marker, "batch:{$ids}\n", FILE_APPEND | LOCK_EX);

        $sleep = max(array_map(static fn (BatchStorageJob $job): int => $job->sleepSeconds, $jobs));

        if ($sleep > 0) {
            $deadline = microtime(true) + $sleep;

            while (microtime(true) < $deadline) {
                usleep(20000);
            }
        }

        if (in_array('throw', array_column($jobs, 'outcome'), true)) {
            throw new RuntimeException('Fake storage is unavailable.');
        }

        $results = array_map(static function (BatchItem $item): BatchItemResult {
            /** @var BatchStorageJob $job */
            $job = $item->job;

            return match ($job->outcome) {
                'retry' => BatchItemResult::retry($item, new RuntimeException('Retry the fake write.')),
                'failure' => BatchItemResult::failure($item, new RuntimeException('The fake event is not valid.')),
                default => BatchItemResult::success($item),
            };
        }, $items);

        foreach ($jobs as $job) {
            if ($job->outcome === 'success') {
                file_put_contents($marker, "write:{$job->id}\n", FILE_APPEND | LOCK_EX);
            }
        }

        if (in_array(true, array_column($jobs, 'disconnectBeforeReturn'), true)) {
            app(ConnectionManager::class)->disconnectAll();
        }

        return $results;
    }
}
