<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Actions\Dlq\Results;

/**
 * Result of replaying DLQ messages.
 */
final readonly class DlqReplayResult
{
    /**
     * @param  array<DlqMessageData>  $replayedMessages  Messages that were/would be replayed
     * @param  array<array{message: DlqMessageData, error: string}>  $failures
     */
    public function __construct(
        public int $replayedCount,
        public int $failedCount,
        public bool $wasDryRun,
        public ?string $notFoundId = null,
        public array $replayedMessages = [],
        public array $failures = [],
    ) {}

    public function wasMessageNotFound(): bool
    {
        return $this->notFoundId !== null;
    }

    public function hasFailures(): bool
    {
        return $this->failedCount > 0;
    }
}
