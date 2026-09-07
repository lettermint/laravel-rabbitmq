<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Tests\Fixtures\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Throwable;

class LifecycleJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public ?int $maxExceptions = null;

    public function __construct(public string $path, public string $id, public int $failAttempts = 0, public bool $middlewareRelease = false, public ?int $deadline = null) {}

    public function retryUntil(): ?int
    {
        return $this->deadline;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [0, 1];
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return $this->middlewareRelease ? [new ReleaseFirstAttempt] : [];
    }

    public function handle(): void
    {
        file_put_contents($this->path, "run:{$this->id}:{$this->attempts()}\n", FILE_APPEND);
        if ($this->attempts() <= $this->failAttempts) {
            throw new RuntimeException('Synthetic job failure');
        }
    }

    public function failed(?Throwable $exception): void
    {
        file_put_contents($this->path, "failed:{$this->id}\n", FILE_APPEND);
    }
}

final class ReleaseFirstAttempt
{
    public function handle(LifecycleJob $job, callable $next): void
    {
        if ($job->attempts() === 1) {
            $job->release(1);

            return;
        }
        $next($job);
    }
}
