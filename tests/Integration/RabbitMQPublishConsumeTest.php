<?php

declare(strict_types=1);

use Illuminate\Queue\QueueManager;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Lettermint\RabbitMQ\Actions\Dlq\ReplayDlqMessages;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Connection\ConnectionManager;
use Lettermint\RabbitMQ\Consumers\RabbitMQWorker;
use Lettermint\RabbitMQ\Diagnostics\QueueProbeJob;
use Lettermint\RabbitMQ\Events\QueueProbeProcessed;
use Lettermint\RabbitMQ\Exceptions\PublishException;
use Lettermint\RabbitMQ\Queue\RabbitMQJob;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;
use Lettermint\RabbitMQ\Topology\TopologyManager;
use Lettermint\RabbitMQ\Topology\TopologyRegistry;

pest()->group('integration');

beforeEach(function () {
    if (! canConnectToRabbitMQ()) {
        $this->markTestSkipped('RabbitMQ is not available');
    }

    $prefix = 'integration.'.Str::lower(Str::random(12)).'.';
    $config = [
        'driver_name' => 'rabbitmq',
        'default' => 'default',
        'physical_prefix' => $prefix,
        'strict_topology' => true,
        'connections' => [
            'default' => [
                'hosts' => [[
                    'host' => env('RABBITMQ_HOST', 'localhost'),
                    'port' => (int) env('RABBITMQ_PORT', 5672),
                    'user' => env('RABBITMQ_USER', 'guest'),
                    'password' => env('RABBITMQ_PASSWORD', 'guest'),
                    'vhost' => env('RABBITMQ_VHOST', '/'),
                ]],
                'options' => [
                    'heartbeat' => 60,
                    'connection_timeout' => 5,
                    'read_timeout' => 10,
                    'write_timeout' => 10,
                    'channel_rpc_timeout' => 5,
                ],
                'ssl' => ['enabled' => false],
            ],
        ],
        'queue' => ['default' => 'default'],
        'publisher' => [
            'confirm' => true,
            'mandatory' => true,
            'confirm_timeout' => 5.0,
        ],
        'retry' => [
            'maximum_delay' => 30,
            'delay_queue_cleanup_grace' => 60000,
        ],
        'recovery' => [
            'max_attempts' => 2,
            'initial_delay_ms' => 10,
            'max_delay_ms' => 50,
        ],
        'dead_letter' => [
            'enabled' => true,
            'exchange' => 'dlx',
            'queue_prefix' => 'dlq:',
        ],
        'topology' => [
            'exchanges' => [
                'jobs' => ['type' => 'direct'],
                'dlx' => ['type' => 'direct'],
            ],
            'queues' => [
                'default' => [
                    'bindings' => ['jobs' => ['default']],
                    'delivery_limit' => 5,
                ],
            ],
        ],
    ];

    config()->set('rabbitmq', $config);
    config()->set('queue.connections.rabbitmq-integration', [
        'driver' => 'rabbitmq',
        'connection' => 'default',
        'queue' => 'default',
    ]);

    /** @var QueueManager $manager */
    $manager = app('queue');

    $this->registry = app(TopologyRegistry::class);
    $this->topology = app(TopologyManager::class);
    $this->topology->declare();
    $this->channels = app(ChannelManager::class);
    $this->queue = $manager->connection('rabbitmq-integration');
    expect($this->queue)->toBeInstanceOf(RabbitMQQueue::class);

    $this->integrationPrefix = $prefix;
    $this->cleanupQueues = [
        $this->registry->queue('default')->physicalName,
        $this->registry->queue('default')->deadLetterQueue,
    ];
    $this->cleanupExchanges = array_column($this->registry->exchanges(), 'name');
});

afterEach(function () {
    if (! isset($this->channels)) {
        return;
    }

    foreach (array_unique($this->cleanupQueues) as $index => $queue) {
        try {
            $this->channels->channel('integration-cleanup-queue-'.$index)->queue_delete($queue);
        } catch (Throwable) {
            // The test can delete a queue before cleanup.
        }
    }

    foreach (array_reverse(array_unique($this->cleanupExchanges)) as $index => $exchange) {
        try {
            $this->channels->channel('integration-cleanup-exchange-'.$index)->exchange_delete($exchange);
        } catch (Throwable) {
            // The exchange can already be unavailable after a failed test.
        }
    }

    app(ConnectionManager::class)->disconnectAll();
});

test('the driver publishes with confirms and processes a Laravel job', function () {
    $processedProbe = null;
    Event::listen(QueueProbeProcessed::class, function (QueueProbeProcessed $event) use (&$processedProbe): void {
        $processedProbe = $event;
    });
    $probe = new QueueProbeJob('probe-1', 'default', time());
    $this->queue->push($probe, '', 'default');

    $job = waitForRabbitJob($this->queue, 'default');
    expect($job)->toBeInstanceOf(RabbitMQJob::class);

    app(RabbitMQWorker::class)->processMessage(
        $job,
        'rabbitmq-integration',
        new WorkerOptions(maxTries: 1, timeout: 30),
    );

    expect($processedProbe)->toBeInstanceOf(QueueProbeProcessed::class)
        ->and($processedProbe->probeId)->toBe('probe-1')
        ->and($this->queue->size('default'))->toBe(0)
        ->and($this->topology->audit()['healthy'])->toBeTrue();
});

test('a delayed publish uses a TTL queue and returns to the main route', function () {
    $exchange = $this->registry->exchanges()['jobs']['name'];
    $suffix = substr(hash('sha256', $exchange."\0default"), 0, 12);
    $delayQueue = $this->integrationPrefix.'delay:default:1000:'.$suffix;
    $this->cleanupQueues[] = $delayQueue;

    $this->queue->pushRaw('{"uuid":"delayed-1"}', 'default', ['delay' => 1]);
    expect($this->queue->pop('default'))->toBeNull();

    $job = waitForRabbitJob($this->queue, 'default', 4.0);
    expect($job)->toBeInstanceOf(RabbitMQJob::class)
        ->and(json_decode($job->getRawBody(), true)['uuid'])->toBe('delayed-1');
    $job->delete();
});

test('a final rejection reaches the quorum DLQ and can be replayed', function () {
    $this->queue->pushRaw('{"uuid":"failed-1","displayName":"ExampleJob"}', 'default');
    $job = waitForRabbitJob($this->queue, 'default');
    expect($job)->toBeInstanceOf(RabbitMQJob::class);
    $this->queue->reject($job->getMessage(), $job->getChannel(), false);

    waitForQueueDepth(
        $this->channels,
        $this->registry->queue('default')->deadLetterQueue,
        1,
    );

    $result = app(ReplayDlqMessages::class)('default', messageId: 'failed-1');
    expect($result->replayedCount)->toBe(1)
        ->and($result->failedCount)->toBe(0);

    $replayed = waitForRabbitJob($this->queue, 'default');
    expect($replayed)->toBeInstanceOf(RabbitMQJob::class)
        ->and($replayed->attempts())->toBe(1)
        ->and(json_decode($replayed->getRawBody(), true)['uuid'])->toBe('failed-1');
    $replayed->delete();
});

test('mandatory routing reports a deleted route as a publish failure', function () {
    $physicalQueue = $this->registry->queue('default')->physicalName;
    $this->channels->topologyChannel()->queue_delete($physicalQueue);

    expect(fn () => $this->queue->pushRaw('{"uuid":"unroutable-1"}', 'default'))
        ->toThrow(PublishException::class, 'unroutable');
});

test('the broker connection is available', function () {
    expect(app(ConnectionManager::class)->connection()->isConnected())->toBeTrue();
});

function waitForRabbitJob(RabbitMQQueue $queue, string $logicalQueue, float $timeout = 2.0): ?RabbitMQJob
{
    $deadline = microtime(true) + $timeout;

    do {
        $job = $queue->pop($logicalQueue);

        if ($job instanceof RabbitMQJob) {
            return $job;
        }

        usleep(50000);
    } while (microtime(true) < $deadline);

    return null;
}

function waitForQueueDepth(ChannelManager $channels, string $physicalQueue, int $minimum, float $timeout = 3.0): void
{
    $deadline = microtime(true) + $timeout;

    do {
        [, $messages] = $channels
            ->channel('integration-depth-'.hash('sha256', $physicalQueue))
            ->queue_declare($physicalQueue, true, false, false, false);

        if ($messages >= $minimum) {
            return;
        }

        usleep(50000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException("Queue [{$physicalQueue}] did not reach depth [{$minimum}].");
}

function canConnectToRabbitMQ(): bool
{
    $socket = @fsockopen(
        (string) env('RABBITMQ_HOST', 'localhost'),
        (int) env('RABBITMQ_PORT', 5672),
        $errorCode,
        $errorMessage,
        2,
    );

    if ($socket === false) {
        return false;
    }

    fclose($socket);

    return true;
}
