<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Batch;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\WorkerOptions;
use Lettermint\RabbitMQ\Consumers\RabbitMQWorker;
use Lettermint\RabbitMQ\Contracts\BatchHandler;
use Lettermint\RabbitMQ\Enums\BatchItemOutcome;
use Lettermint\RabbitMQ\Events\BatchInterrupted;
use Lettermint\RabbitMQ\Events\BatchItemSettled;
use Lettermint\RabbitMQ\Events\BatchProcessed;
use Lettermint\RabbitMQ\Events\BatchProcessing;
use Lettermint\RabbitMQ\Exceptions\InvalidBatchResultException;
use Lettermint\RabbitMQ\Support\ExceptionReporter;
use Throwable;

/** @internal */
final readonly class BatchProcessor
{
    public function __construct(
        private Container $container,
        private Dispatcher $events,
        private RabbitMQWorker $worker,
    ) {}

    /**
     * @param  class-string<BatchHandler>  $handlerClass
     * @param  Closure(PendingBatchItem, BatchItemOutcome): void  $afterSettlement
     */
    public function process(
        CollectedBatch $batch,
        string $handlerClass,
        string $connection,
        WorkerOptions $workerOptions,
        BatchOptions $batchOptions,
        Closure $afterSettlement,
    ): void {
        $queue = $batch->items[0]->item->queue;
        $processingStarted = null;
        $processingMilliseconds = 0.0;
        $settlementMilliseconds = 0.0;
        $settled = 0;
        $successes = 0;
        $retries = 0;
        $failures = 0;
        $invalidResults = 0;
        $handlerException = null;

        $this->dispatch(new BatchProcessing(
            batchId: $batch->id,
            connection: $connection,
            queue: $queue,
            handler: $handlerClass,
            size: count($batch->items),
            payloadBytes: $batch->payloadBytes,
            collectionMilliseconds: $batch->collectionMilliseconds,
        ));

        try {
            $eligible = [];

            foreach ($batch->items as $pending) {
                $settlementStarted = hrtime(true);

                if ($this->worker->batchItemMayRun($pending->delivery, $connection, $workerOptions)) {
                    $eligible[] = $pending;

                    continue;
                }

                $duration = $this->millisecondsSince($settlementStarted);
                $settlementMilliseconds += $duration;
                $settled++;
                $failures++;
                $afterSettlement($pending, BatchItemOutcome::Failure);
                $this->dispatchItem($batch->id, $connection, $pending, BatchItemOutcome::Failure, 'attempt_limit', 0, $duration);
            }

            $results = [];

            if ($eligible !== []) {
                $items = array_map(
                    static fn (PendingBatchItem $pending): BatchItem => $pending->item,
                    $eligible,
                );

                $this->worker->resetApplicationScope();

                try {
                    $handler = $this->container->make($handlerClass);

                    if (! $handler instanceof BatchHandler) {
                        throw new InvalidBatchResultException("Batch handler [{$handlerClass}] does not implement the batch handler contract.");
                    }

                    $processingStarted = hrtime(true);
                    $results = $this->worker->processBatchCall(
                        callback: fn (): array => $handler->handle($items),
                        connectionName: $connection,
                        queue: $queue,
                        options: $workerOptions,
                        beforeTimeout: function () use (
                            $batch,
                            $connection,
                            $queue,
                            &$processingStarted,
                            &$settled,
                            &$processingMilliseconds,
                            &$settlementMilliseconds,
                        ): void {
                            $processingMilliseconds = $this->millisecondsSince($processingStarted);
                            $this->dispatch(new BatchInterrupted(
                                batchId: $batch->id,
                                connection: $connection,
                                queue: $queue,
                                size: count($batch->items),
                                payloadBytes: $batch->payloadBytes,
                                settled: $settled,
                                unacknowledged: count($batch->items) - $settled,
                                reason: 'timeout',
                                collectionMilliseconds: $batch->collectionMilliseconds,
                                processingMilliseconds: $processingMilliseconds,
                                settlementMilliseconds: $settlementMilliseconds,
                            ));
                        },
                    );
                } catch (Throwable $exception) {
                    $handlerException = $exception;
                    $results = array_map(
                        static fn (BatchItem $item): BatchItemResult => BatchItemResult::retry($item, $exception),
                        $items,
                    );
                } finally {
                    $processingMilliseconds = $processingStarted === null
                        ? 0
                        : $this->millisecondsSince($processingStarted);
                    $this->worker->resetApplicationScope();
                }

                [$outcomes, $invalidResults] = $this->reconcile($eligible, $results);

                foreach ($eligible as $pending) {
                    $itemSettlementStarted = hrtime(true);
                    try {
                        $result = $outcomes[spl_object_id($pending->item)];
                        $actualOutcome = $this->worker->settleBatchItem(
                            job: $pending->delivery,
                            outcome: $result->outcome,
                            exception: $result->exception,
                            connectionName: $connection,
                            options: $workerOptions,
                            minimumRetryDelaySeconds: $batchOptions->minimumRetryDelaySeconds,
                        );
                    } catch (Throwable $exception) {
                        $settlementMilliseconds += $this->millisecondsSince($itemSettlementStarted);

                        throw $exception;
                    }
                    $duration = $this->millisecondsSince($itemSettlementStarted);
                    $settlementMilliseconds += $duration;
                    $settled++;

                    match ($actualOutcome) {
                        BatchItemOutcome::Success => $successes++,
                        BatchItemOutcome::Retry => $retries++,
                        BatchItemOutcome::Failure => $failures++,
                    };

                    $afterSettlement($pending, $actualOutcome);
                    $this->dispatchItem(
                        $batch->id,
                        $connection,
                        $pending,
                        $actualOutcome,
                        $this->resultReason($result, $actualOutcome),
                        $processingMilliseconds,
                        $duration,
                    );
                }

            }

            $this->dispatch(new BatchProcessed(
                batchId: $batch->id,
                connection: $connection,
                queue: $queue,
                handler: $handlerClass,
                size: count($batch->items),
                payloadBytes: $batch->payloadBytes,
                collectionMilliseconds: $batch->collectionMilliseconds,
                processingMilliseconds: $processingMilliseconds,
                settlementMilliseconds: $settlementMilliseconds,
                successes: $successes,
                retries: $retries,
                failures: $failures,
                invalidResults: $invalidResults,
                handlerExceptionClass: $handlerException === null ? null : $handlerException::class,
            ));
        } catch (Throwable $exception) {
            $this->dispatch(new BatchInterrupted(
                batchId: $batch->id,
                connection: $connection,
                queue: $queue,
                size: count($batch->items),
                payloadBytes: $batch->payloadBytes,
                settled: $settled,
                unacknowledged: count($batch->items) - $settled,
                reason: 'settlement_error',
                collectionMilliseconds: $batch->collectionMilliseconds,
                processingMilliseconds: $processingMilliseconds,
                settlementMilliseconds: $settlementMilliseconds,
                exceptionClass: $exception::class,
            ));

            throw $exception;
        }
    }

    /**
     * @param  non-empty-list<PendingBatchItem>  $pendingItems
     * @return array{array<int, BatchItemResult>, int}
     */
    private function reconcile(array $pendingItems, mixed $results): array
    {
        $expected = [];

        foreach ($pendingItems as $pending) {
            $expected[spl_object_id($pending->item)] = $pending->item;
        }

        $outcomes = [];
        $duplicates = [];
        $invalid = 0;

        if (! is_array($results)) {
            $results = [];
            $invalid++;
        }

        foreach ($results as $result) {
            if (! $result instanceof BatchItemResult) {
                $invalid++;

                continue;
            }

            $id = spl_object_id($result->item);

            if (! isset($expected[$id])) {
                $invalid++;

                continue;
            }

            if (isset($outcomes[$id])) {
                $duplicates[$id] = true;
                $invalid++;

                continue;
            }

            $outcomes[$id] = $result;
        }

        foreach ($expected as $id => $item) {
            if (! isset($outcomes[$id]) || isset($duplicates[$id])) {
                $outcomes[$id] = BatchItemResult::retry(
                    $item,
                    new InvalidBatchResultException('The batch handler did not return exactly one result for this item.'),
                );
                $invalid++;
            }
        }

        return [$outcomes, $invalid];
    }

    private function dispatchItem(
        string $batchId,
        string $connection,
        PendingBatchItem $pending,
        BatchItemOutcome $outcome,
        string $reason,
        float $processingMilliseconds,
        float $settlementMilliseconds,
    ): void {
        $item = $pending->item;
        $this->dispatch(new BatchItemSettled(
            batchId: $batchId,
            connection: $connection,
            queue: $item->queue,
            jobId: $item->jobId,
            jobClass: $item->job::class,
            attempt: $item->attempt,
            brokerDeliveryCount: $item->brokerDeliveryCount,
            redelivered: $item->redelivered,
            messageTimestamp: $item->messageTimestamp,
            payloadBytes: $item->payloadBytes,
            outcome: $outcome,
            reason: $reason,
            processingMilliseconds: $processingMilliseconds,
            settlementMilliseconds: $settlementMilliseconds,
        ));
    }

    private function resultReason(BatchItemResult $result, BatchItemOutcome $actual): string
    {
        if ($actual !== $result->outcome) {
            return 'attempt_limit';
        }

        return match ($actual) {
            BatchItemOutcome::Success => 'handler_success',
            BatchItemOutcome::Retry => $result->exception instanceof InvalidBatchResultException ? 'invalid_result' : 'handler_retry',
            BatchItemOutcome::Failure => 'handler_failure',
        };
    }

    private function millisecondsSince(int $nanoseconds): float
    {
        return (hrtime(true) - $nanoseconds) / 1_000_000;
    }

    private function dispatch(object $event): void
    {
        try {
            $this->events->dispatch($event);
        } catch (Throwable $exception) {
            ExceptionReporter::report($exception);
        }
    }
}
