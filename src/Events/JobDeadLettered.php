<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Events;

use Throwable;

final readonly class JobDeadLettered
{
    public function __construct(
        public string $queue,
        public ?string $jobId,
        public string $jobName,
        public int $attempt,
        public Throwable $exception,
        public int $messageTimestamp,
        public bool $redelivered,
        public int $brokerDeliveryCount,
    ) {}
}
