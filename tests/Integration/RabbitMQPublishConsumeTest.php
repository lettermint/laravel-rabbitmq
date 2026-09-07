<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Lettermint\RabbitMQ\Actions\Dlq\InspectDlqMessages;
use Lettermint\RabbitMQ\Actions\Dlq\ReplayDlqMessages;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Connection\ConnectionManager;
use Lettermint\RabbitMQ\Consumers\Consumer;
use Lettermint\RabbitMQ\Consumers\RabbitMQWorker;
use Lettermint\RabbitMQ\Diagnostics\QueueProbeJob;
use Lettermint\RabbitMQ\Events\MessagePublished;
use Lettermint\RabbitMQ\Events\QueueProbeProcessed;
use Lettermint\RabbitMQ\Exceptions\PublishException;
use Lettermint\RabbitMQ\Exceptions\SettlementException;
use Lettermint\RabbitMQ\Monitoring\ManagementClient;
use Lettermint\RabbitMQ\Queue\RabbitMQJob;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;
use Lettermint\RabbitMQ\Tests\Fixtures\Jobs\LifecycleJob;
use Lettermint\RabbitMQ\Tests\Fixtures\Jobs\ProcessMarkerJob;
use Lettermint\RabbitMQ\Tests\Fixtures\Jobs\ThrowingJob;
use Lettermint\RabbitMQ\Tests\Fixtures\Jobs\UniqueLifecycleJob;
use Lettermint\RabbitMQ\Topology\TopologyManager;
use Lettermint\RabbitMQ\Topology\TopologyRegistry;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;

pest()->group('integration');

beforeEach(function () {
    if (! canConnectToRabbitMQ()) {
        throw new RuntimeException('Required RabbitMQ integration broker is not available.');
    }

    $prefix = 'integration.'.Str::lower(Str::random(12)).'.';
    $config = [
        'management' => ['url' => env('RABBITMQ_MANAGEMENT_URL', 'http://localhost:15672')],
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
    $delayQueue = $this->integrationPrefix.'delay-v2:default:1000:'.$suffix;
    $this->cleanupQueues[] = $delayQueue;

    $this->queue->pushRaw('{"uuid":"delayed-1"}', 'default', ['delay' => 1]);
    expect($this->queue->pop('default'))->toBeNull();

    $job = waitForRabbitJob($this->queue, 'default', 4.0);
    expect($job)->toBeInstanceOf(RabbitMQJob::class)
        ->and(json_decode($job->getRawBody(), true)['uuid'])->toBe('delayed-1');
    $job->delete();
});

test('a Laravel retry preserves its exception in the replacement message', function () {
    $this->queue->push(new ThrowingJob, '', 'default');
    $job = waitForRabbitJob($this->queue, 'default');
    expect($job)->toBeInstanceOf(RabbitMQJob::class);

    app(RabbitMQWorker::class)->processMessage(
        $job,
        'rabbitmq-integration',
        new WorkerOptions(maxTries: 2, timeout: 30, backoff: 0),
    );

    $retried = waitForRabbitJob($this->queue, 'default');
    expect($retried)->toBeInstanceOf(RabbitMQJob::class);

    $payload = json_decode($retried->getRawBody(), true, flags: JSON_THROW_ON_ERROR);

    expect($retried->attempts())->toBe(2)
        ->and($payload['exception'])->toBe([
            'class' => RuntimeException::class,
            'message' => 'Retry this test job.',
            'code' => 0,
        ]);

    $retried->delete();
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

test('probe completion belongs to the current run and requires a consumer', function () {
    $process = integrationConsumerProcess();
    try {
        $process->start();
        $exit = Artisan::call('rabbitmq:probe', ['queue' => ['default'], '--connection' => 'rabbitmq-integration', '--wait' => 5, '--json' => true]);
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($exit)->toBe(0)->and($result['completed'])->toBeTrue()->and($result['pending'])->toBe([]);
        $process->wait();
        expect($process->getExitCode())->toBe(0);

        $exit = Artisan::call('rabbitmq:probe', ['queue' => ['default'], '--connection' => 'rabbitmq-integration', '--wait' => 1, '--json' => true]);
        $missing = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($exit)->toBe(1)->and($missing['completed'])->toBeFalse()
            ->and($missing['run_id'])->not->toBe($result['run_id'])
            ->and($missing['pending'])->toHaveCount(1);
    } finally {
        $process->stop();
    }
});

test('a lost publisher confirmation does not trigger another replacement on the same delivery', function () {
    $marker = tempnam(sys_get_temp_dir(), 'rabbitmq-drop-replies-');
    unlink($marker);
    $proxy = new Process(['python3', dirname(__DIR__).'/Fixtures/amqp-reply-proxy.py', $marker, (string) env('RABBITMQ_HOST'), (string) env('RABBITMQ_PORT')]);
    $proxy->start();
    $proxy->waitUntil(fn ($type, $output) => str_contains($output, "\n"));
    $port = (int) trim($proxy->getOutput());
    expect($port)->toBeGreaterThan(0);
    $throughProxy = integrationQueueAtPort($port);
    $throughProxy->getChannelManager()->publishChannel('default');
    $this->queue->pushRaw('{"uuid":"uncertain-transfer"}');
    $original = waitForRabbitJob($this->queue, 'default');
    $job = new RabbitMQJob(app(), $throughProxy, $original->getChannel(), $original->getMessage(), 'rabbitmq-integration', 'default');
    try {
        touch($marker);
        $exception = null;
        try {
            $job->release(0);
        } catch (PublishException $failure) {
            $exception = $failure;
        }
        expect($exception)->toBeInstanceOf(PublishException::class)
            ->and($exception->uncertain)->toBeTrue()
            ->and($job->isSettled())->toBeFalse();
        expect(fn () => $job->release(0))->toThrow(SettlementException::class);
        $this->channels->closeChannel('consume');
        $first = waitForRabbitJob($this->queue, 'default', 5);
        $second = waitForRabbitJob($this->queue, 'default', 5);
        expect($first->getJobId())->toBe('uncertain-transfer')->and($second->getJobId())->toBe('uncertain-transfer');
        $first->delete();
        $second->delete();
        expect($this->queue->pop('default'))->toBeNull();
    } finally {
        $proxy->stop();
        if (is_file($marker)) {
            unlink($marker);
        }
    }
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

test('the native consumer exits after a job without consuming a buffered second job', function () {
    $path = tempnam(sys_get_temp_dir(), 'rabbitmq-marker-');
    $this->queue->push(new ProcessMarkerJob($path, 'first'));
    $this->queue->push(new ProcessMarkerJob($path, 'second'));
    $process = integrationConsumerProcess();

    try {
        $process->mustRun();
        expect(file_get_contents($path))->toBe("started:first\ncompleted:first\n");
        $remaining = waitForRabbitJob($this->queue, 'default');
        expect($remaining)->toBeInstanceOf(RabbitMQJob::class);
        $remaining->delete();
    } finally {
        $process->stop();
        unlink($path);
    }
});

test('SIGTERM finishes the active job and returns buffered deliveries', function () {
    $path = tempnam(sys_get_temp_dir(), 'rabbitmq-marker-');
    $this->queue->push(new ProcessMarkerJob($path, 'first', 2));
    $this->queue->push(new ProcessMarkerJob($path, 'second'));
    $process = integrationConsumerProcess(['RABBITMQ_TEST_MAX_JOBS' => '10']);

    try {
        $process->start();
        waitForMarker($path, 'started:first', $process);
        $process->signal(SIGTERM);
        $process->wait();
        expect($process->getExitCode())->toBe(0)
            ->and(file_get_contents($path))->toBe("started:first\ncompleted:first\n");
        $remaining = waitForRabbitJob($this->queue, 'default');
        expect($remaining)->toBeInstanceOf(RabbitMQJob::class);
        $remaining->delete();
    } finally {
        $process->stop();
        unlink($path);
    }
});

test('a killed native consumer leaves the active message recoverable', function () {
    $path = tempnam(sys_get_temp_dir(), 'rabbitmq-marker-');
    $this->queue->push(new ProcessMarkerJob($path, 'first', 10));
    $process = integrationConsumerProcess();

    try {
        $process->start();
        waitForMarker($path, 'started:first', $process);
        $process->signal(SIGKILL);
        $process->wait();
        $remaining = waitForRabbitJob($this->queue, 'default', 5);
        expect($remaining)->toBeInstanceOf(RabbitMQJob::class)
            ->and($remaining->getMessage()->isRedelivered())->toBeTrue()
            ->and($remaining->attempts())->toBe(1);
        $remaining->delete();
    } finally {
        $process->stop();
        unlink($path);
    }
});

test('a job timeout stops the process and preserves an unlimited-attempt job', function () {
    $path = tempnam(sys_get_temp_dir(), 'rabbitmq-marker-');
    $this->queue->push(new ProcessMarkerJob($path, 'first', 10));
    $process = integrationConsumerProcess(['RABBITMQ_TEST_TIMEOUT' => '1']);

    try {
        try {
            $process->run();
        } catch (ProcessSignaledException $exception) {
            expect($exception->getSignal())->toBe(SIGKILL);
        }
        expect($process->isSuccessful())->toBeFalse();
        $remaining = waitForRabbitJob($this->queue, 'default', 5);
        expect($remaining)->toBeInstanceOf(RabbitMQJob::class);
        $remaining->delete();
    } finally {
        $process->stop();
        unlink($path);
    }
});

test('a process exit after retry confirmation preserves both copies with the same identity', function () {
    $path = tempnam(sys_get_temp_dir(), 'rabbitmq-marker-');
    $id = $this->queue->push(new ProcessMarkerJob($path, 'first', releaseOnce: true));
    $process = integrationConsumerProcess(['RABBITMQ_TEST_EXIT_AFTER_PUBLISH' => '1']);

    try {
        $process->run();
        expect($process->getExitCode())->toBe(17);
        $first = waitForRabbitJob($this->queue, 'default', 5);
        $second = waitForRabbitJob($this->queue, 'default', 5);
        expect($first)->toBeInstanceOf(RabbitMQJob::class)
            ->and($second)->toBeInstanceOf(RabbitMQJob::class)
            ->and($first->getJobId())->toBe($id)
            ->and($second->getJobId())->toBe($id);
        $first->delete();
        $second->delete();
    } finally {
        $process->stop();
        unlink($path);
    }
});

/** @param array<string, string> $environment */
function integrationConsumerProcess(array $environment = []): Process
{
    $settings = config('rabbitmq');
    $settings['consumer']['heartbeat_sender'] ??= false;

    return new Process(
        [PHP_BINARY, dirname(__DIR__).'/Fixtures/consumer-process.php'],
        dirname(__DIR__, 2),
        array_merge(['RABBITMQ_TEST_CONFIG' => json_encode($settings, JSON_THROW_ON_ERROR)], $environment),
        timeout: 30,
    );
}

function waitForMarker(string $path, string $marker, Process $process): void
{
    $deadline = microtime(true) + 10;

    do {
        if (str_contains((string) file_get_contents($path), $marker)) {
            return;
        }

        if (! $process->isRunning()) {
            throw new RuntimeException('Consumer exited before marker: '.$process->getErrorOutput().$process->getOutput());
        }

        usleep(20000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('Consumer did not write the expected marker.');
}

test('repeated DLQ inspection keeps a message beyond the default delivery limit', function () {
    $this->queue->pushRaw('{"uuid":"retained-1"}', 'default');
    $job = waitForRabbitJob($this->queue, 'default');
    $this->queue->reject($job->getMessage(), $job->getChannel(), false);
    $dlq = $this->registry->queue('default')->deadLetterQueue;
    waitForQueueDepth($this->channels, $dlq, 1);

    for ($inspection = 0; $inspection < 25; $inspection++) {
        $result = app(InspectDlqMessages::class)('default', messageId: 'retained-1');
        expect($result->messages)->toHaveCount(1);
        waitForQueueDepth($this->channels, $dlq, 1);
    }
});

test('a quorum delay retains an expired message until its destination returns', function () {
    $exchange = $this->registry->exchanges()['jobs']['name'];
    $suffix = substr(hash('sha256', $exchange."\0default"), 0, 12);
    $delay = $this->integrationPrefix.'delay-v2:default:1000:'.$suffix;
    $this->cleanupQueues[] = $delay;
    $this->channels->topologyChannel()->queue_delete($this->registry->queue('default')->physicalName);
    $this->queue->pushRaw('{"uuid":"delayed-outage"}', 'default', ['delay' => 1]);
    usleep(1500000);

    $this->artisan('rabbitmq:delay-cleanup', ['queue' => [$delay]])->assertFailed();
    expect(app(ManagementClient::class)->queue($delay))->not->toBeNull();
    (new TopologyManager($this->channels, $this->registry, config('rabbitmq')))->declare();
    // RabbitMQ 4.2.5 retries an unavailable dead-letter destination after 180 seconds.
    $job = waitForRabbitJob($this->queue, 'default', 200);
    expect($job)->toBeInstanceOf(RabbitMQJob::class)->and($job->getJobId())->toBe('delayed-outage');
    $job->delete();
});

test('leader loss preserves confirmed jobs on a three-member quorum queue', function () {
    $details = requireThreeBrokerQueue($this->registry->queue('default')->physicalName);
    $leader = str_replace('rabbit@', '', $details['leader']);
    expect($leader)->toBeIn(['broker1', 'broker2', 'broker3']);
    $this->queue->pushRaw('{"uuid":"leader-loss"}');
    try {
        integrationDocker(['kill', '-s', 'SIGKILL', $leader]);
        $port = $leader === 'broker1' ? 25674 : 25672;
        $queue = integrationQueueAtPort($port);
        $job = waitForRabbitJobAfterElection($queue, 30);
        expect($job->getJobId())->toBe('leader-loss');
        $job->delete();
        expect($queue->pop('default'))->toBeNull();
    } finally {
        integrationDocker(['up', '-d', '--wait', '--wait-timeout', '120']);
        requireThreeBrokerQueue($this->registry->queue('default')->physicalName);
    }
})->group('broker-fault');

test('majority loss does not confirm a new publish and preserves prior confirmed jobs', function () {
    requireThreeBrokerQueue($this->registry->queue('default')->physicalName);
    $this->queue->pushRaw('{"uuid":"before-majority-loss"}');
    $failure = null;
    try {
        integrationDocker(['kill', '-s', 'SIGKILL', 'broker2', 'broker3']);
        try {
            $this->queue->pushRaw('{"uuid":"during-majority-loss"}');
        } catch (Throwable $exception) {
            $failure = $exception;
        }
        expect($failure)->toBeInstanceOf(PublishException::class);
    } finally {
        integrationDocker(['up', '-d', '--wait', '--wait-timeout', '120']);
        requireThreeBrokerQueue($this->registry->queue('default')->physicalName);
    }
    $queue = integrationQueueAtPort(25672);
    $first = waitForRabbitJobAfterElection($queue, 30);
    expect($first->getJobId())->toBe('before-majority-loss');
    $first->delete();
    $uncertain = waitForRabbitJob($queue, 'default', 2);
    if ($uncertain !== null) {
        expect($uncertain->getJobId())->toBe('during-majority-loss')->and($failure->uncertain)->toBeTrue();
        $uncertain->delete();
    }
    expect($queue->pop('default'))->toBeNull();
})->group('broker-fault');

test('a network partition leaves the majority available and rejoins without extra jobs', function () {
    requireThreeBrokerQueue($this->registry->queue('default')->physicalName);
    $container = trim(integrationDocker(['ps', '-q', 'broker3']));
    expect($container)->not->toBeEmpty();
    $network = 'rabbitmq-driver-tests_default';
    $this->queue->pushRaw('{"uuid":"partition-1"}');
    try {
        (new Process(['docker', 'network', 'disconnect', $network, $container]))->mustRun();
        $queue = integrationQueueAtPort(25672);
        $job = waitForRabbitJobAfterElection($queue, 40);
        expect($job->getJobId())->toBe('partition-1');
        $job->delete();
        $queue->pushRaw('{"uuid":"partition-2"}');
    } finally {
        (new Process(['docker', 'network', 'connect', '--alias', 'broker3', $network, $container]))->mustRun();
        requireThreeBrokerQueue($this->registry->queue('default')->physicalName);
    }
    $job = waitForRabbitJobAfterElection($queue, 30);
    expect($job->getJobId())->toBe('partition-2');
    $job->delete();
    expect($queue->pop('default'))->toBeNull();
})->group('broker-fault');

/** @param list<string> $arguments */
function integrationDocker(array $arguments): string
{
    $process = new Process(
        ['docker', 'compose', '-f', __DIR__.'/docker/compose.yaml', ...$arguments],
        timeout: 150,
    );
    $process->mustRun();

    return $process->getOutput();
}

/** @return array<string, mixed> */
function requireThreeBrokerQueue(string $queue): array
{
    if ((int) env('RABBITMQ_PORT') !== 25672 || ! in_array(env('RABBITMQ_HOST'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Broker fault tests require the isolated Docker Compose cluster on port 25672.');
    }
    $deadline = microtime(true) + 45;
    do {
        try {
            $details = app(ManagementClient::class)->queue($queue);
            if (count($details['members'] ?? []) === 3 && count($details['online'] ?? []) === 3 && ($details['state'] ?? null) === 'running') {
                expect($details['members'])->toEqualCanonicalizing(['rabbit@broker1', 'rabbit@broker2', 'rabbit@broker3']);

                return $details;
            }
        } catch (ConnectionException) {
            // The management listener can start after the broker process.
        }
        usleep(200000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('The required queue does not have three online members.');
}

function integrationQueueAtPort(int $port): RabbitMQQueue
{
    $settings = config('rabbitmq');
    $settings['connections']['default']['hosts'][0]['port'] = $port;
    $connections = new ConnectionManager($settings);
    $channels = new ChannelManager($connections);
    $queue = new RabbitMQQueue($channels, app(TopologyRegistry::class), app('events'), $settings);
    $queue->setContainer(app());
    $queue->setConnectionName('rabbitmq-integration');

    return $queue;
}

function waitForRabbitJobAfterElection(RabbitMQQueue $queue, float $timeout): RabbitMQJob
{
    $deadline = microtime(true) + $timeout;
    do {
        try {
            $job = $queue->pop('default');
            if ($job !== null) {
                return $job;
            }
        } catch (Throwable) {
            $queue->getChannelManager()->closeChannel('consume', 'default');
        }
        usleep(200000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('The confirmed job was not available after leader election.');
}

test('heartbeats keep a long job connected until its single acknowledgement', function () {
    config(['rabbitmq.consumer.heartbeat_sender' => true, 'rabbitmq.connections.default.options.heartbeat' => 2]);
    $path = tempnam(sys_get_temp_dir(), 'rabbitmq-marker-');
    $this->queue->push(new ProcessMarkerJob($path, 'long', 8));
    $process = integrationConsumerProcess(['RABBITMQ_TEST_TIMEOUT' => '15']);

    try {
        $process->mustRun();
        expect(file_get_contents($path))->toBe("started:long\ncompleted:long\n")
            ->and($this->queue->size('default'))->toBe(0);
    } finally {
        $process->stop();
        unlink($path);
    }
});

test('forced worker termination also stops its heartbeat child', function () {
    config(['rabbitmq.consumer.heartbeat_sender' => true]);
    $path = tempnam(sys_get_temp_dir(), 'rabbitmq-marker-');
    $this->queue->push(new ProcessMarkerJob($path, 'active', 15));
    $process = integrationConsumerProcess(['RABBITMQ_TEST_TIMEOUT' => '20']);
    $child = 0;

    try {
        $process->start();
        waitForMarker($path, 'started:active', $process);
        $children = new Process(['pgrep', '-P', (string) $process->getPid()]);
        $children->mustRun();
        $child = (int) trim($children->getOutput());
        expect($child)->toBeGreaterThan(0);
        $process->signal(SIGKILL);
        try {
            $process->wait();
        } catch (ProcessSignaledException) {
        }
        $deadline = microtime(true) + 3;
        do {
            $state = new Process(['ps', '-o', 'stat=', '-p', (string) $child]);
            $state->run();
            $alive = $state->isSuccessful() && ! str_starts_with(trim($state->getOutput()), 'Z');
            if (! $alive) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        expect($alive)->toBeFalse();
        $job = waitForRabbitJob($this->queue, 'default');
        expect($job)->toBeInstanceOf(RabbitMQJob::class);
        $job->delete();
        expect(file_get_contents($path))->toBe("started:active\n");
    } finally {
        $process->stop();
        if ($child > 0 && posix_kill($child, 0)) {
            posix_kill($child, SIGKILL);
        }
        unlink($path);
    }
});

function runIntegrationConsumer(int $jobs = 1): void
{
    app(Consumer::class)->setConnection('rabbitmq-integration')
        ->setQueue('default')->setPrefetch(1)->setMaxJobs($jobs)->setMaxTime(10)->setTimeout(5)->setTries(3)->consume();
}

test('the native consumer preserves backoff attempt limits and failure callbacks after replay', function () {
    $path = tempnam(sys_get_temp_dir(), 'rabbitmq-lifecycle-');
    $job = new LifecycleJob($path, 'retry', 99);
    $this->queue->push($job);
    try {
        $started = microtime(true);
        runIntegrationConsumer(3);
        expect(microtime(true) - $started)->toBeGreaterThanOrEqual(1)
            ->and(file_get_contents($path))->toBe("run:retry:1\nrun:retry:2\nrun:retry:3\nfailed:retry\n");
        $replay = app(ReplayDlqMessages::class)('default', limit: 1);
        expect($replay->replayedCount)->toBe(1);
        runIntegrationConsumer(3);
        expect(file_get_contents($path))->toBe(str_repeat("run:retry:1\nrun:retry:2\nrun:retry:3\nfailed:retry\n", 2));
    } finally {
        unlink($path);
    }
});

test('middleware release runs the handler only on the next attempt', function () {
    $path = tempnam(sys_get_temp_dir(), 'rabbitmq-lifecycle-');
    $this->queue->push(new LifecycleJob($path, 'middleware', middlewareRelease: true));
    try {
        runIntegrationConsumer(2);
        expect(file_get_contents($path))->toBe("run:middleware:2\n");
    } finally {
        unlink($path);
    }
});

test('an expired Laravel deadline fails without calling the job handler', function () {
    $path = tempnam(sys_get_temp_dir(), 'rabbitmq-lifecycle-');
    $this->queue->push(new LifecycleJob($path, 'expired', deadline: time() - 1));
    try {
        runIntegrationConsumer();
        expect(file_get_contents($path))->toBe("failed:expired\n");
    } finally {
        unlink($path);
    }
});

test('the native consumer releases a unique lock only after the job completes', function () {
    $path = tempnam(sys_get_temp_dir(), 'rabbitmq-lifecycle-');
    $class = UniqueLifecycleJob::class;
    $published = 0;
    Event::listen(MessagePublished::class, function () use (&$published): void {
        $published++;
    });
    try {
        $class::dispatch($path, 'unique', 1)->onConnection('rabbitmq-integration');
        $class::dispatch($path, 'unique', 1)->onConnection('rabbitmq-integration');
        expect($published)->toBe(1);
        runIntegrationConsumer();
        $class::dispatch($path, 'unique', 1)->onConnection('rabbitmq-integration');
        expect($published)->toBe(2);
        runIntegrationConsumer();
        $class::dispatch($path, 'unique')->onConnection('rabbitmq-integration');
        runIntegrationConsumer();
        expect(file_get_contents($path))->toBe("run:unique:1\nrun:unique:2\nrun:unique:1\n");
    } finally {
        unlink($path);
    }
});

test('a chained job is dispatched once after its predecessor succeeds', function () {
    $path = tempnam(sys_get_temp_dir(), 'rabbitmq-lifecycle-');
    $class = LifecycleJob::class;
    try {
        Bus::chain([new $class($path, 'first', 1), new $class($path, 'next')])
            ->onConnection('rabbitmq-integration')->dispatch();
        runIntegrationConsumer(3);
        expect(file_get_contents($path))->toBe("run:first:1\nrun:first:2\nrun:next:1\n")
            ->and($this->queue->size('default'))->toBe(0);
    } finally {
        unlink($path);
    }
});

test('a batch completes only after its released job succeeds', function () {
    config(['database.connections.rabbitmq_batch_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'queue.batching.database' => 'rabbitmq_batch_test']);
    Schema::connection('rabbitmq_batch_test')->create('job_batches', function ($table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->integer('total_jobs');
        $table->integer('pending_jobs');
        $table->integer('failed_jobs');
        $table->text('failed_job_ids');
        $table->mediumText('options')->nullable();
        $table->integer('cancelled_at')->nullable();
        $table->integer('created_at');
        $table->integer('finished_at')->nullable();
    });
    $path = tempnam(sys_get_temp_dir(), 'rabbitmq-lifecycle-');
    $class = LifecycleJob::class;
    try {
        $batch = Bus::batch([new $class($path, 'batch', 1)])
            ->onConnection('rabbitmq-integration')->dispatch();
        runIntegrationConsumer();
        expect($batch->fresh()->pendingJobs)->toBe(1)->and($batch->fresh()->finished())->toBeFalse();
        runIntegrationConsumer();
        expect($batch->fresh()->pendingJobs)->toBe(0)->and($batch->fresh()->finished())->toBeTrue()
            ->and(file_get_contents($path))->toBe("run:batch:1\nrun:batch:2\n");
    } finally {
        unlink($path);
        DB::purge('rabbitmq_batch_test');
    }
});

test('maximum exception count stops retries before the attempt limit', function () {
    $path = tempnam(sys_get_temp_dir(), 'rabbitmq-lifecycle-');
    $job = new LifecycleJob($path, 'exceptions', 99);
    $job->maxExceptions = 2;
    $this->queue->push($job);
    try {
        runIntegrationConsumer(2);
        expect(file_get_contents($path))->toBe("run:exceptions:1\nrun:exceptions:2\nfailed:exceptions\n");
    } finally {
        unlink($path);
    }
});

test('maintenance holds consumer registration until the application resumes', function () {
    $path = tempnam(sys_get_temp_dir(), 'rabbitmq-lifecycle-');
    $maintenance = Mockery::mock(MaintenanceMode::class);
    $maintenance->shouldReceive('active')->andReturn(true, false);
    app()->instance(MaintenanceMode::class, $maintenance);
    $loops = 0;
    $consumerCountWhilePaused = null;
    Event::listen(Looping::class, function () use (&$loops, &$consumerCountWhilePaused) {
        if ($loops++ === 0) {
            $queue = app(TopologyRegistry::class)->queue('default')->physicalName;
            [, , $consumerCountWhilePaused] = app(ChannelManager::class)->channel('maintenance-test')->queue_declare($queue, true);

            return false;
        }

        return null;
    });
    $this->queue->push(new ProcessMarkerJob($path, 'after-maintenance'));
    try {
        runIntegrationConsumer();
        expect($consumerCountWhilePaused)->toBe(0)
            ->and(file_get_contents($path))->toBe("started:after-maintenance\ncompleted:after-maintenance\n");
    } finally {
        unlink($path);
    }
});

test('a delay queue capacity rejection leaves the original delivery recoverable', function () {
    if (! in_array(env('RABBITMQ_HOST'), ['127.0.0.1', 'localhost'], true) || (int) env('RABBITMQ_PORT') !== 25672) {
        throw new RuntimeException('Capacity tests require the isolated broker fixture.');
    }
    $vhost = $this->integrationPrefix.'capacity';
    $api = Http::withBasicAuth('guest', 'guest')->timeout(5)
        ->baseUrl(rtrim((string) env('RABBITMQ_MANAGEMENT_URL'), '/').'/api');
    $api->put('/vhosts/'.rawurlencode($vhost), ['description' => 'Synthetic capacity test'])->throw();
    try {
        $api->put('/permissions/'.rawurlencode($vhost).'/guest', ['configure' => '.*', 'write' => '.*', 'read' => '.*'])->throw();
        $api->put('/vhost-limits/'.rawurlencode($vhost).'/max-queues', ['value' => 1])->throw();
        $settings = config('rabbitmq');
        $settings['connections']['default']['hosts'][0]['vhost'] = $vhost;
        $settings['dead_letter']['enabled'] = false;
        $registry = testTopologyRegistry($settings);
        $connections = new ConnectionManager($settings);
        $channels = new ChannelManager($connections);
        (new TopologyManager($channels, $registry, $settings))->declare();
        $queue = new RabbitMQQueue($channels, $registry, app('events'), $settings);
        $queue->setContainer(app());
        $queue->setConnectionName('rabbitmq-integration');
        $queue->pushRaw('{"uuid":"capacity-job"}');
        $job = waitForRabbitJob($queue, 'default');
        expect(fn () => $job->release(1))->toThrow(PublishException::class);
        expect($job->isSettled())->toBeFalse()->and($job->isReleased())->toBeFalse();
        $channels->closeChannel('consume', 'default');
        $recovered = waitForRabbitJob($queue, 'default');
        expect($recovered)->toBeInstanceOf(RabbitMQJob::class)
            ->and($recovered->getJobId())->toBe('capacity-job')->and($recovered->attempts())->toBe(1);
        $recovered->delete();
        $connections->disconnectAll();
    } finally {
        $api->delete('/vhosts/'.rawurlencode($vhost))->throw();
    }
});

test('retry and replay do not send another copy to other queues on a shared exchange', function (bool $replay) {
    $observer = $this->integrationPrefix.'observer';
    $channel = $this->channels->channel('observer');
    $channel->queue_declare($observer, false, true, false, false);
    $channel->queue_bind($observer, $this->integrationPrefix.'jobs', 'default');
    $this->cleanupQueues[] = $observer;
    $this->queue->pushRaw('{"uuid":"shared-exchange"}');
    $original = waitForRabbitJob($this->queue, 'default');
    $copy = $channel->basic_get($observer, false);
    expect($copy)->not->toBeNull();
    $channel->basic_ack($copy->getDeliveryTag());

    if ($replay) {
        $this->queue->reject($original->getMessage(), $original->getChannel(), false);
        waitForQueueDepth($this->channels, $this->registry->queue('default')->deadLetterQueue, 1);
        expect(app(ReplayDlqMessages::class)('default', messageId: 'shared-exchange')->replayedCount)->toBe(1);
    } else {
        $original->release(0);
    }

    $retry = waitForRabbitJob($this->queue, 'default');
    expect($retry)->toBeInstanceOf(RabbitMQJob::class)
        ->and($retry->getJobId())->toBe('shared-exchange');
    $retry->delete();
    expect($channel->basic_get($observer, false))->toBeNull();
})->with(['retry' => false, 'replay' => true]);
