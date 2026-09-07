<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Console\Commands;

use Illuminate\Console\Command;
use Lettermint\RabbitMQ\Monitoring\WorkerStatus;

final class WorkerStatusCommand extends Command
{
    protected $signature = 'rabbitmq:worker-status {--file= : Local status file} {--ready : Require a registered consumer}';

    protected $description = 'Check the local worker status without contacting the broker';

    public function handle(WorkerStatus $status): int
    {
        $path = $this->option('file') ?? config('rabbitmq.consumer.status_file');

        return is_string($path) && $status->healthy($path, (bool) $this->option('ready'))
            ? self::SUCCESS : self::FAILURE;
    }
}
