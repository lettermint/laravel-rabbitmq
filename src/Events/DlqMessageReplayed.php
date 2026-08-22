<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Events;

final readonly class DlqMessageReplayed
{
    public function __construct(
        public string $queue,
        public string $jobId,
        public string $jobName,
        public int $attempt,
    ) {}
}
