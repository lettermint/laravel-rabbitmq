<?php

declare(strict_types=1);

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Events\JobRetried;
use Lettermint\RabbitMQ\Events\JobSettlementFailed;
use Lettermint\RabbitMQ\Queue\RabbitMQJob;

/** @param array<string, mixed> $headers */
function lifecycleTelemetryJob(array $headers = []): RabbitMQJob
{
    return new RabbitMQJob(
        app(),
        testRabbitMQQueue(Mockery::mock(ChannelManager::class)),
        mockAMQPChannel(),
        mockAMQPMessage(['messageId' => 'timed-job', 'headers' => $headers]),
        'rabbitmq',
        'default',
    );
}

test('processing logs the wait once and completion does not include execution time', function () {
    $this->travelTo(now()->startOfSecond());
    $job = lifecycleTelemetryJob([RabbitMQJob::AVAILABLE_AT_HEADER => now()->getTimestampMs() - 1250]);
    Log::spy();

    Event::dispatch(new JobProcessing('rabbitmq', $job));
    $this->travel(2)->minutes();
    Event::dispatch(new JobProcessed('rabbitmq', $job));

    foreach (['processing', 'processed'] as $result) {
        Log::shouldHaveReceived('info')->with(
            'RabbitMQ job '.$result,
            Mockery::on(fn (array $context): bool => $context['ready_wait_ms'] === 1250
                && $context['queue'] === 'default'
                && $context['job_id'] === 'timed-job'
                && array_key_exists('queue_wait_ms', $context)),
        )->once();
    }

    Event::dispatch(new JobProcessed('rabbitmq', $job));
    Log::shouldHaveReceived('info')->with(
        'RabbitMQ job processed',
        Mockery::on(fn (array $context): bool => $context['ready_wait_ms'] === null),
    )->once();
});

test('old messages log unknown ready wait instead of an estimated value', function () {
    Log::spy();

    Event::dispatch(new JobProcessing('rabbitmq', lifecycleTelemetryJob()));

    Log::shouldHaveReceived('info')->with(
        'RabbitMQ job processing',
        Mockery::on(fn (array $context): bool => $context['ready_wait_ms'] === null),
    )->once();
});

test('failed attempts retain the wait at processing and clear it after settlement', function (string $result) {
    $this->travelTo(now()->startOfSecond());
    $job = lifecycleTelemetryJob([RabbitMQJob::AVAILABLE_AT_HEADER => now()->getTimestampMs() - 250]);
    $exception = new RuntimeException('Test failure.');
    Log::spy();

    Event::dispatch(new JobProcessing('rabbitmq', $job));
    $this->travel(1)->minutes();
    Event::dispatch(new JobExceptionOccurred('rabbitmq', $job, $exception));

    $event = match ($result) {
        'failed' => new JobFailed('rabbitmq', $job, $exception),
        'timed_out' => new JobTimedOut('rabbitmq', $job),
        'retry' => new JobRetried('default', 'timed-job', 'TestJob', 1, 30, time(), false, 0),
        'settlement_error' => new JobSettlementFailed('default', 'timed-job', $exception),
    };
    Event::dispatch($event);
    Event::dispatch(new JobProcessed('rabbitmq', $job));

    Log::shouldHaveReceived('warning')->with(
        'RabbitMQ job raised an exception',
        Mockery::on(fn (array $context): bool => $context['ready_wait_ms'] === 250),
    )->once();

    if ($result !== 'settlement_error') {
        $level = $result === 'retry' ? 'notice' : 'error';
        Log::shouldHaveReceived($level)->with(
            Mockery::any(),
            Mockery::on(fn (array $context): bool => $context['result'] === $result && $context['ready_wait_ms'] === 250),
        )->once();
    }

    Log::shouldHaveReceived('info')->with(
        'RabbitMQ job processed',
        Mockery::on(fn (array $context): bool => $context['ready_wait_ms'] === null),
    )->once();
})->with(['failed', 'timed_out', 'retry', 'settlement_error']);
