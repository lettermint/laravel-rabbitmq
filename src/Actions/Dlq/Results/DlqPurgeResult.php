<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Actions\Dlq\Results;

/**
 * Result of purging DLQ messages.
 */
final readonly class DlqPurgeResult
{
    /**
     * @param  array<DlqMessageData>  $purgedMessages  Messages that were/would be purged
     */
    public function __construct(
        public int $purgedCount,
        public int $skippedCount,
        public bool $wasDryRun,
        public ?string $notFoundId = null,
        public array $purgedMessages = [],
    ) {}

    public function wasMessageNotFound(): bool
    {
        return $this->notFoundId !== null;
    }
}
