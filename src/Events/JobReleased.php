<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Events;

final readonly class JobReleased
{
    public function __construct(
        public string $queue,
        public ?string $jobId,
        public string $jobName,
        public int $attempt,
        public int $delay,
        public int $messageTimestamp,
        public bool $redelivered,
        public int $brokerDeliveryCount,
    ) {}
}
