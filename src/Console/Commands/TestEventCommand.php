<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Str;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Diagnostics\QueueProbeJob;
use Lettermint\RabbitMQ\Exceptions\PublishException;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;
use Lettermint\RabbitMQ\Support\ExceptionReporter;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

final class TestEventCommand extends Command
{
    protected $signature = 'rabbitmq:test-event
        {queue=default : Registered logical queue for a safe worker probe}
        {--connection=rabbitmq : Laravel queue connection}
        {--roundtrip : Use an isolated broker queue for a publish and consume check}
        {--json : Write machine-readable output}';

    protected $description = 'Run a safe RabbitMQ publish or broker round-trip diagnostic';

    public function handle(QueueManager $manager, ChannelManager $channels): int
    {
        $connectionName = (string) $this->option('connection');
        $connection = $manager->connection($connectionName);

        if (! $connection instanceof RabbitMQQueue) {
            $this->error("Queue connection [{$connectionName}] does not use this RabbitMQ driver.");

            return self::FAILURE;
        }

        try {
            $result = $this->option('roundtrip')
                ? $this->roundTrip($connection, $channels)
                : $this->publishProbe($connection);

            if ($this->option('json')) {
                $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->line("OK {$result['action']} {$result['message_id']}");
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $result = ['success' => false, 'error' => $exception->getMessage()];

            if ($this->option('json')) {
                $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }

    /** @return array<string, mixed> */
    protected function publishProbe(RabbitMQQueue $connection): array
    {
        $logicalQueue = (string) $this->argument('queue');
        $probeId = (string) Str::uuid();
        $dispatchedAt = time();
        $connection->push(new QueueProbeJob($probeId, $logicalQueue, $dispatchedAt), '', $logicalQueue);

        return [
            'success' => true,
            'action' => 'probe_published',
            'queue' => $logicalQueue,
            'message_id' => $probeId,
            'dispatched_at' => $dispatchedAt,
        ];
    }

    /** @return array<string, mixed> */
    protected function roundTrip(RabbitMQQueue $connection, ChannelManager $channels): array
    {
        $brokerConnection = $connection->getBrokerConnectionName();
        $queueName = 'lettermint.diagnostic.'.Str::lower(Str::random(20));
        $messageId = (string) Str::uuid();
        $startedAt = microtime(true);
        $returned = false;
        $nacked = false;
        $topology = $channels->topologyChannel($brokerConnection);
        $declared = false;

        try {
            $topology->queue_declare($queueName, false, false, true, true);
            $declared = true;

            $publisher = $channels->publishChannel($brokerConnection);
            $publisher->set_return_listener(function () use (&$returned): void {
                $returned = true;
            });
            $publisher->set_nack_handler(function () use (&$nacked): void {
                $nacked = true;
            });
            $publisher->basic_publish(
                new AMQPMessage(
                    (string) json_encode(['uuid' => $messageId, 'type' => 'rabbitmq_round_trip'], JSON_THROW_ON_ERROR),
                    ['message_id' => $messageId, 'content_type' => 'application/json'],
                ),
                '',
                $queueName,
                true,
            );
            $publisher->wait_for_pending_acks_returns((float) config('rabbitmq.publisher.confirm_timeout', 5.0));

            if ($returned || $nacked) {
                throw new PublishException('RabbitMQ did not confirm the diagnostic message.');
            }

            $consumer = $channels->channel('diagnostic-roundtrip', $brokerConnection);
            $message = $consumer->basic_get($queueName, false);

            if (! $message instanceof AMQPMessage || $message->get('message_id') !== $messageId) {
                throw new PublishException('RabbitMQ did not return the expected diagnostic message.');
            }

            $consumer->basic_ack($message->getDeliveryTag());

            return [
                'success' => true,
                'action' => 'roundtrip',
                'message_id' => $messageId,
                'round_trip_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            ];
        } finally {
            $channels->closeChannel('diagnostic-roundtrip', $brokerConnection);

            if ($declared) {
                try {
                    $topology->queue_delete($queueName);
                } catch (Throwable $exception) {
                    ExceptionReporter::report($exception);
                }
            }
        }
    }
}
