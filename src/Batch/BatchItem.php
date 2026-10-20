<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Batch;

final readonly class BatchItem
{
    public function __construct(
        public object $job,
        public ?string $jobId,
        public string $queue,
        public int $attempt,
        public int $brokerDeliveryCount,
        public bool $redelivered,
        public int $messageTimestamp,
        public int $payloadBytes,
    ) {}
}
