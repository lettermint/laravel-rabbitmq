<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Queue\WorkerOptions;
use Lettermint\RabbitMQ\Consumers\RabbitMQWorker;
use Lettermint\RabbitMQ\Tests\TestCase;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$test = new class('bootWorker') extends TestCase
{
    public function bootWorker(): void
    {
        $this->setUp();
    }
};
$test->bootWorker();

$mode = $argv[1];
$job = Mockery::mock(Job::class, JobContract::class);
$job->shouldReceive('getConnectionName')->andReturn('rabbitmq');
$job->shouldReceive('getQueue')->andReturn('default');
$job->shouldReceive('resolveName')->andReturn('TimeoutTestJob');
$job->shouldReceive('uuid')->andReturn('timeout-test');
$job->shouldReceive('payload')->andReturn([]);
$job->shouldReceive('maxTries')->andReturn(0);
$job->shouldReceive('maxExceptions', 'retryUntil')->andReturnNull();
$job->shouldReceive('timeout')->andReturn(1);
$job->shouldReceive('isDeleted', 'isReleased', 'hasFailed')->andReturnFalse();
$job->shouldReceive('release')->andReturnNull();
$job->shouldReceive('shouldFailOnTimeout')->andReturn($mode === 'failure-callback');
$job->shouldReceive('fail')->andReturnUsing(function (): never {
    echo "failure-callback\n";
    throw new RuntimeException('Failure callback unavailable');
});
$job->shouldReceive('fire')->andReturnUsing(function (): void {
    echo "started\n";
    sleep(5);
    echo "finished\n";
});

app('events')->listen(JobExceptionOccurred::class, function ($event): void {
    fwrite(STDERR, $event->exception->getMessage()."\n");
});
app('events')->listen(JobTimedOut::class, function (): void {
    echo "timed-out\n";
});
app('events')->listen(WorkerStopping::class, function ($event) use ($mode): void {
    echo json_encode([
        'connection' => $event->connectionName ?? null,
        'queue' => $event->queue ?? null,
    ], JSON_THROW_ON_ERROR)."\n";

    if ($mode === 'stop-listener') {
        echo "stop-listener\n";
        throw new RuntimeException('Stop listener unavailable');
    }
});

pcntl_async_signals(true);
app(RabbitMQWorker::class)->processMessage($job, 'rabbitmq', new WorkerOptions(timeout: 10, maxTries: 0));
echo "returned\n";
