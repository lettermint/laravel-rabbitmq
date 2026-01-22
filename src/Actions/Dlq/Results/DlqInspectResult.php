<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Actions\Dlq\Results;

/**
 * Result of inspecting DLQ messages.
 */
final readonly class DlqInspectResult
{
    /**
     * @param  array<DlqMessageData>  $messages
     */
    public function __construct(
        public array $messages,
        public int $totalFound,
        public ?string $notFoundId = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->totalFound === 0;
    }

    public function wasMessageNotFound(): bool
    {
        return $this->notFoundId !== null;
    }
}
