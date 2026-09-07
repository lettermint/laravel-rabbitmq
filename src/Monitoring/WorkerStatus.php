<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Monitoring;

use RuntimeException;

final class WorkerStatus
{
    public function write(string $phase, bool $registered, ?int $deadline = null): void
    {
        $path = config('rabbitmq.consumer.status_file');

        if (! is_string($path) || $path === '') {
            return;
        }

        $temporary = $path.'.'.getmypid().'.tmp';
        $data = json_encode([
            'pid' => getmypid(),
            'phase' => $phase,
            'registered' => $registered,
            'updated_at' => time(),
            'job_deadline' => $deadline,
        ], JSON_THROW_ON_ERROR);

        if (file_put_contents($temporary, $data, LOCK_EX) === false || ! rename($temporary, $path)) {
            throw new RuntimeException('Cannot write the RabbitMQ worker status file.');
        }
    }

    public function healthy(string $path, bool $ready): bool
    {
        if (! is_file($path)) {
            return false;
        }

        $status = json_decode((string) file_get_contents($path), true);

        if (! is_array($status) || ($status['phase'] ?? null) === 'stopping') {
            return false;
        }

        $fresh = ($status['updated_at'] ?? 0) >= time() - 10;
        $unbounded = array_key_exists('job_deadline', $status) && $status['job_deadline'] === null;
        $active = ($status['phase'] ?? null) === 'processing' && ($unbounded || ($status['job_deadline'] ?? 0) + 5 >= time());

        return ($fresh || $active) && (! $ready || ($status['registered'] ?? false));
    }
}
