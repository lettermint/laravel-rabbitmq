<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Batch;

use InvalidArgumentException;
use Lettermint\RabbitMQ\Enums\BatchItemOutcome;
use Throwable;

final readonly class BatchItemResult
{
    private function __construct(
        public BatchItem $item,
        public BatchItemOutcome $outcome,
        public ?Throwable $exception,
    ) {
        if ($outcome !== BatchItemOutcome::Success && $exception === null) {
            throw new InvalidArgumentException('A retry or failure result must include an exception.');
        }
    }

    public static function success(BatchItem $item): self
    {
        return new self($item, BatchItemOutcome::Success, null);
    }

    public static function retry(BatchItem $item, Throwable $exception): self
    {
        return new self($item, BatchItemOutcome::Retry, $exception);
    }

    public static function failure(BatchItem $item, Throwable $exception): self
    {
        return new self($item, BatchItemOutcome::Failure, $exception);
    }
}
