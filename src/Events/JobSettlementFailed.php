<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Events;

use Throwable;

final readonly class JobSettlementFailed
{
    public function __construct(
        public string $queue,
        public ?string $jobId,
        public Throwable $exception,
    ) {}
}
