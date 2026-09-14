<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Events;

use Lettermint\RabbitMQ\Enums\BatchItemOutcome;

final readonly class BatchItemSettled
{
    public function __construct(
        public ?string $batchId,
        public string $connection,
        public string $queue,
        public ?string $jobId,
        public string $jobClass,
        public int $attempt,
        public int $brokerDeliveryCount,
        public bool $redelivered,
        public int $messageTimestamp,
        public int $payloadBytes,
        public BatchItemOutcome $outcome,
        public string $reason,
        public float $processingMilliseconds,
        public float $settlementMilliseconds,
    ) {}
}
