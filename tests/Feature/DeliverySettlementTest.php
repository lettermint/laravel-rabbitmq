<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Event;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Consumers\RabbitMQWorker;
use Lettermint\RabbitMQ\Exceptions\ConnectionException;
use Lettermint\RabbitMQ\Exceptions\SettlementException;
use Lettermint\RabbitMQ\Queue\RabbitMQJob;
use Lettermint\RabbitMQ\Support\FailedJobDetails;
use PhpAmqpLib\Exception\AMQPIOException;

test('an acknowledgement failure does not mark the delivery deleted', function () {
    $channel = mockAMQPChannel();
    $channel->shouldReceive('basic_ack')->once()->andThrow(new AMQPIOException('connection lost'));
    $queue = testRabbitMQQueue(Mockery::mock(ChannelManager::class));
    $job = new RabbitMQJob(app(), $queue, $channel, mockAMQPMessage(), 'rabbitmq', 'default');

    expect(fn () => $job->delete())->toThrow(ConnectionException::class);

    expect($job->isDeleted())->toBeFalse()
        ->and($job->isSettled())->toBeFalse()
        ->and($job->settlementError())->toBeInstanceOf(ConnectionException::class);
    expect(fn () => $job->delete())->toThrow(SettlementException::class);
});

test('a rejected delay cannot be retried again on the same delivery', function () {
    $channel = mockAMQPChannel();
    $channel->shouldNotReceive('basic_ack');
    $queue = testRabbitMQQueue(Mockery::mock(ChannelManager::class), ['retry' => ['maximum_delay' => 1]]);
    $job = new RabbitMQJob(app(), $queue, $channel, mockAMQPMessage(), 'rabbitmq', 'default');

    expect(fn () => $job->release(2))->toThrow(InvalidArgumentException::class);

    expect($job->isReleased())->toBeFalse()
        ->and($job->isSettled())->toBeFalse();
    expect(fn () => $job->release(0))->toThrow(SettlementException::class);
});

test('repeated deletion sends only one acknowledgement', function () {
    $channel = mockAMQPChannel();
    $channel->shouldReceive('basic_ack')->once();
    $queue = testRabbitMQQueue(Mockery::mock(ChannelManager::class));
    $job = new RabbitMQJob(app(), $queue, $channel, mockAMQPMessage(), 'rabbitmq', 'default');

    $job->delete();
    $job->delete();
    $job->release();

    expect($job->isSettled())->toBeTrue()->and($job->isReleased())->toBeFalse();
});

test('malformed messages are rejected without losing their raw body', function (string $body) {
    $channel = mockAMQPChannel();
    $channel->shouldNotReceive('basic_ack');
    $channel->shouldReceive('basic_reject')->once()->with(1, false);
    $queue = testRabbitMQQueue(Mockery::mock(ChannelManager::class));
    $job = new RabbitMQJob(app(), $queue, $channel, mockAMQPMessage(['body' => $body, 'deliveryTag' => 1]), 'rabbitmq', 'default');

    app(RabbitMQWorker::class)->processMessage($job, 'rabbitmq', new WorkerOptions);

    expect($job->isSettled())->toBeTrue()
        ->and($job->hasFailed())->toBeTrue()
        ->and($job->getRawBody())->toBe($body);
})->with(['invalid JSON' => ['{broken'], 'scalar JSON' => ['12'], 'missing handler' => ['{"uuid":"malformed"}']]);

test('an unknown job is retained when failure history and exception reporting both fail', function () {
    $body = json_encode(['uuid' => 'missing-job', 'job' => 'UnknownJob@fire', 'maxTries' => 1], JSON_THROW_ON_ERROR);
    $channel = mockAMQPChannel();
    $channel->shouldNotReceive('basic_ack');
    $channel->shouldReceive('basic_reject')->once()->with(1, false);
    $queue = testRabbitMQQueue(Mockery::mock(ChannelManager::class));
    $job = new RabbitMQJob(app(), $queue, $channel, mockAMQPMessage(['body' => $body, 'deliveryTag' => 1]), 'rabbitmq', 'default');
    $provider = Mockery::mock(FailedJobProviderInterface::class);
    $provider->shouldReceive('find')->once()->andThrow(new RuntimeException('History unavailable'));
    app()->instance('queue.failer', $provider);
    Event::listen(JobFailed::class, fn ($event) => app(FailedJobDetails::class)->record($event));
    $handler = Mockery::mock(ExceptionHandler::class);
    $handler->shouldReceive('report')->atLeast()->once()->andThrow(new RuntimeException('Reporter unavailable'));
    app()->instance(ExceptionHandler::class, $handler);
    $previousLog = ini_get('error_log');
    $log = tempnam(sys_get_temp_dir(), 'queue-report-');
    ini_set('error_log', $log);

    try {
        app(RabbitMQWorker::class)->processMessage($job, 'rabbitmq', new WorkerOptions);
        expect($job->isSettled())->toBeTrue()
            ->and($job->hasFailed())->toBeTrue()
            ->and($job->getRawBody())->toBe($body)
            ->and(file_get_contents($log))->toContain('RabbitMQ exception reporting failed');
    } finally {
        ini_set('error_log', $previousLog);
        unlink($log);
    }
});
