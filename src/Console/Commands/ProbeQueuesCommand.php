<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Lettermint\RabbitMQ\Diagnostics\QueueProbeJob;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;
use Lettermint\RabbitMQ\Topology\TopologyRegistry;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

final class ProbeQueuesCommand extends Command
{
    protected $signature = 'rabbitmq:probe
        {queue?* : Logical queues to probe}
        {--all : Probe every registered queue}
        {--connection=rabbitmq : Laravel queue connection}
        {--wait=0 : Seconds to wait for each queue to process its probe}
        {--json : Write machine-readable output}';

    protected $description = 'Publish a safe probe job to registered RabbitMQ queues';

    public function handle(QueueManager $manager, TopologyRegistry $registry): int
    {
        $requested = $this->option('all')
            ? $registry->logicalQueueNames()
            : array_values($this->argument('queue'));
        $requested = $requested === [] ? ['default'] : $requested;
        $connectionName = (string) $this->option('connection');
        $connection = $manager->connection($connectionName);

        if (! $connection instanceof RabbitMQQueue) {
            $this->error("Queue connection [{$connectionName}] does not use this RabbitMQ driver.");

            return self::FAILURE;
        }

        $results = [];
        $runId = (string) Str::uuid();
        $wait = max(0, (int) $this->option('wait'));
        $replyQueue = null;
        $replyChannel = null;
        $pending = [];
        $error = null;

        try {
            if ($wait > 0) {
                $replyChannel = $connection->getChannelManager()->channel('probe-replies', $connection->getBrokerConnectionName());
                [$replyQueue] = $replyChannel->queue_declare('', false, false, true, true, false, new AMQPTable([
                    'x-queue-type' => 'classic',
                    'x-expires' => ($wait + 30) * 1000,
                ]));
            }

            foreach ($requested as $logicalQueue) {
                $registry->queue($logicalQueue);
                $probeId = (string) Str::uuid();
                $dispatchedAt = time();
                $job = new QueueProbeJob($probeId, $logicalQueue, $dispatchedAt, $replyQueue);
                $connection->push($job, '', $logicalQueue);
                if ($replyQueue !== null) {
                    $pending[$probeId] = $logicalQueue;
                }
                $results[] = ['queue' => $logicalQueue, 'probe_id' => $probeId, 'dispatched_at' => $dispatchedAt];
            }

            if ($replyChannel !== null && $replyQueue !== null) {
                $replyChannel->basic_consume($replyQueue, '', false, true, false, false, function (AMQPMessage $message) use (&$pending): void {
                    $reply = json_decode($message->getBody(), true);
                    $id = $reply['probe_id'] ?? null;
                    if (is_string($id) && isset($pending[$id]) && $pending[$id] === ($reply['queue'] ?? null)) {
                        unset($pending[$id]);
                    }
                });
                $deadline = microtime(true) + $wait;
                while ($pending !== [] && microtime(true) < $deadline) {
                    try {
                        $replyChannel->wait(null, false, min(0.5, max(0.01, $deadline - microtime(true))));
                    } catch (AMQPTimeoutException) {
                        continue;
                    }
                }
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        } finally {
            if ($replyChannel !== null) {
                $connection->getChannelManager()->closeChannel('probe-replies', $connection->getBrokerConnectionName());
            }
        }

        $success = $error === null && $pending === [];
        try {
            Log::log($success ? 'info' : 'error', 'RabbitMQ probe run finished', [
                'event' => 'rabbitmq.queue_probe.run_finished',
                'run_id' => $runId,
                'expected' => count($requested),
                'completed' => $wait > 0 ? count($results) - count($pending) : 0,
                'result' => $success ? ($wait > 0 ? 'completed' : 'published') : 'failed',
            ]);
        } catch (Throwable $exception) {
            $success = false;
            $error ??= 'Probe telemetry failed: '.$exception->getMessage();
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['run_id' => $runId, 'probes' => $results, 'pending' => $pending, 'error' => $error, 'completed' => $wait > 0 && $success], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($results as $result) {
                $this->line("PUBLISHED {$result['queue']} {$result['probe_id']}");
            }
        }

        return $success ? self::SUCCESS : self::FAILURE;
    }
}
