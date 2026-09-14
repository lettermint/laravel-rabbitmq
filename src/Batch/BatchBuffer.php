<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Batch;

use Closure;
use Illuminate\Support\Str;
use LogicException;

/** @internal */
final class BatchBuffer
{
    /** @var list<PendingBatchItem> */
    private array $items = [];

    private int $payloadBytes = 0;

    private ?int $startedAtNanoseconds = null;

    private ?string $batchId = null;

    private Closure $clock;

    public function __construct(
        private readonly BatchOptions $options,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => (int) hrtime(true);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function payloadBytes(): int
    {
        return $this->payloadBytes;
    }

    public function wouldExceedBytes(int $payloadBytes): bool
    {
        return $this->items !== [] && $this->payloadBytes + $payloadBytes > $this->options->maxBytes;
    }

    public function append(PendingBatchItem $item): void
    {
        $payloadBytes = $item->item->payloadBytes;

        if ($payloadBytes > $this->options->maxBytes || $this->payloadBytes + $payloadBytes > $this->options->maxBytes) {
            throw new LogicException('The batch payload byte limit was exceeded.');
        }

        if ($this->items === []) {
            $this->startedAtNanoseconds = $this->now();
            $this->batchId = (string) Str::uuid();
        }

        $this->items[] = $item;
        $this->payloadBytes += $payloadBytes;
    }

    public function reachedLimit(int $maximumCount): bool
    {
        return count($this->items) >= $maximumCount || $this->payloadBytes >= $this->options->maxBytes;
    }

    public function expired(): bool
    {
        return $this->startedAtNanoseconds !== null
            && $this->now() - $this->startedAtNanoseconds >= $this->maximumWaitNanoseconds();
    }

    public function nextWaitTimeout(float $defaultSeconds): float
    {
        if ($this->startedAtNanoseconds === null) {
            return $defaultSeconds;
        }

        $remaining = ($this->maximumWaitNanoseconds() - ($this->now() - $this->startedAtNanoseconds)) / 1_000_000_000;

        return max(0.001, min($defaultSeconds, $remaining));
    }

    public function drain(): CollectedBatch
    {
        if ($this->items === [] || $this->startedAtNanoseconds === null || $this->batchId === null) {
            throw new LogicException('An empty batch cannot be drained.');
        }

        $batch = new CollectedBatch(
            id: $this->batchId,
            items: $this->items,
            payloadBytes: $this->payloadBytes,
            collectionMilliseconds: ($this->now() - $this->startedAtNanoseconds) / 1_000_000,
        );

        $this->items = [];
        $this->payloadBytes = 0;
        $this->startedAtNanoseconds = null;
        $this->batchId = null;

        return $batch;
    }

    private function maximumWaitNanoseconds(): int
    {
        return (int) round($this->options->maxWaitSeconds * 1_000_000_000);
    }

    private function now(): int
    {
        return ($this->clock)();
    }
}
