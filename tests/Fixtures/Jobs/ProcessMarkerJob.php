<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Tests\Fixtures\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Queue;

final class ProcessMarkerJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public string $path, public string $id, public int $seconds = 0, public bool $releaseOnce = false, public ?string $publishId = null) {}

    public function handle(): void
    {
        file_put_contents($this->path, 'started:'.$this->id."\n", FILE_APPEND | LOCK_EX);

        if ($this->releaseOnce && $this->attempts() === 1) {
            $this->release(0);

            return;
        }

        $deadline = microtime(true) + $this->seconds;

        while (microtime(true) < $deadline) {
            usleep(20000);
        }

        if ($this->publishId !== null) {
            Queue::connection('rabbitmq-integration')->pushRaw(json_encode(['uuid' => $this->publishId], JSON_THROW_ON_ERROR), 'default');
        }

        file_put_contents($this->path, 'completed:'.$this->id."\n", FILE_APPEND | LOCK_EX);
    }
}
