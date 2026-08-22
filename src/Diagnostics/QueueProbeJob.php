<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Diagnostics;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Lettermint\RabbitMQ\Events\QueueProbeProcessed;

final class QueueProbeJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public readonly string $probeId,
        public readonly string $logicalQueue,
        public readonly int $dispatchedAt,
    ) {}

    public function handle(): void
    {
        $processedAt = time();

        Log::info('RabbitMQ queue probe processed', [
            'event' => 'rabbitmq.queue_probe.processed',
            'probe_id' => $this->probeId,
            'queue' => $this->logicalQueue,
            'dispatched_at' => $this->dispatchedAt,
            'processed_at' => $processedAt,
            'wait_ms' => max(0, ($processedAt - $this->dispatchedAt) * 1000),
        ]);

        event(new QueueProbeProcessed(
            probeId: $this->probeId,
            queue: $this->logicalQueue,
            dispatchedAt: $this->dispatchedAt,
            processedAt: $processedAt,
        ));
    }
}
