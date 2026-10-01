<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Batch;

use InvalidArgumentException;

final readonly class BatchOptions
{
    public function __construct(
        public int $maxCount,
        public int $maxBytes,
        public float $maxWaitSeconds,
        public int $minimumRetryDelaySeconds = 1,
    ) {
        if ($maxCount < 1) {
            throw new InvalidArgumentException('The batch message limit must be at least 1.');
        }

        if ($maxBytes < 1) {
            throw new InvalidArgumentException('The batch payload byte limit must be at least 1.');
        }

        if (! is_finite($maxWaitSeconds) || $maxWaitSeconds <= 0) {
            throw new InvalidArgumentException('The batch wait limit must be greater than zero.');
        }

        if ($minimumRetryDelaySeconds < 1) {
            throw new InvalidArgumentException('The batch retry delay must be at least 1 second.');
        }
    }
}
