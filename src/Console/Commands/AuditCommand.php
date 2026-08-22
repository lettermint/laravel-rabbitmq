<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Console\Commands;

use Illuminate\Console\Command;
use Lettermint\RabbitMQ\Exceptions\TopologyException;
use Lettermint\RabbitMQ\Topology\TopologyManager;

final class AuditCommand extends Command
{
    protected $signature = 'rabbitmq:audit
        {--strict : Return a failure when an expected entity is unavailable}
        {--json : Write machine-readable output}';

    protected $description = 'Perform passive RabbitMQ topology checks';

    public function handle(TopologyManager $topology): int
    {
        $result = $topology->audit();

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($result['queues'] as $queue) {
                $this->line("OK {$queue['logical']} ({$queue['messages']} ready, {$queue['consumers']} consumers)");
            }

            foreach ($result['failures'] as $failure) {
                $this->error("FAILED {$failure['entity']} {$failure['name']}: {$failure['error']}");
            }
        }

        if ($result['healthy']) {
            return self::SUCCESS;
        }

        $exception = new TopologyException('The RabbitMQ runtime topology audit failed.');
        report($exception);

        return $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }
}
