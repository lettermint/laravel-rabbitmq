<?php

declare(strict_types=1);

use Illuminate\Queue\WorkerOptions;
use Lettermint\RabbitMQ\Batch\BatchItem;
use Lettermint\RabbitMQ\Batch\BatchOptions;
use Lettermint\RabbitMQ\Batch\BatchProcessor;
use Lettermint\RabbitMQ\Batch\CollectedBatch;
use Lettermint\RabbitMQ\Batch\PendingBatchItem;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Enums\BatchItemOutcome;
use Lettermint\RabbitMQ\Events\BatchInterrupted;
use Lettermint\RabbitMQ\Events\BatchItemSettled;
use Lettermint\RabbitMQ\Events\BatchProcessed;
use Lettermint\RabbitMQ\Exceptions\ConnectionException;
use Lettermint\RabbitMQ\Exceptions\PublishException;
use Lettermint\RabbitMQ\Queue\RabbitMQJob;
use Lettermint\RabbitMQ\Tests\Fixtures\Batch\BatchOutcomeHandler;
use Lettermint\RabbitMQ\Tests\Fixtures\Jobs\SimpleJob;
use Mockery\MockInterface;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPTimeoutException;

/**
 * @return array{CollectedBatch, MockInterface, MockInterface}
 */
function processorBatch(array $values): array
{
    $consumeChannel = mockAMQPChannel();
    $publishChannel = mockAMQPChannel();
    $channelManager = Mockery::mock(ChannelManager::class);
    $channelManager->shouldReceive('topologyChannel')->andReturn($publishChannel)->byDefault();
    $channelManager->shouldReceive('publishChannel')->andReturn($publishChannel)->byDefault();
    $channelManager->shouldReceive('closeChannel')->andReturnNull()->byDefault();
    $queue = testRabbitMQQueue($channelManager, ['strict_topology' => false]);
    $queue->setContainer(app());
    $pending = [];

    foreach ($values as $index => $value) {
        $job = new SimpleJob($value);
        $payload = json_encode([
            'uuid' => 'job-'.$index,
            'displayName' => SimpleJob::class,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'maxTries' => null,
            'maxExceptions' => null,
            'backoff' => 0,
            'timeout' => null,
            'retryUntil' => null,
            'data' => [
                'commandName' => SimpleJob::class,
                'command' => serialize($job),
            ],
        ], JSON_THROW_ON_ERROR);
        $message = mockAMQPMessage([
            'body' => $payload,
            'deliveryTag' => $index + 1,
            'messageId' => 'job-'.$index,
        ]);
        $delivery = new RabbitMQJob(app(), $queue, $consumeChannel, $message, 'rabbitmq', 'events');
        $item = new BatchItem($job, 'job-'.$index, 'events', 1, 0, false, time(), strlen($payload));
        $pending[] = new PendingBatchItem($delivery, $item);
    }

    return [
        new CollectedBatch('batch-1', $pending, array_sum(array_map(
            static fn (PendingBatchItem $item): int => $item->item->payloadBytes,
            $pending,
        )), 10),
        $consumeChannel,
        $publishChannel,
    ];
}

function runProcessorBatch(CollectedBatch $batch): array
{
    $settled = [];
    app(BatchProcessor::class)->process(
        $batch,
        BatchOutcomeHandler::class,
        'rabbitmq',
        new WorkerOptions(maxTries: 3, timeout: 10, backoff: 0),
        new BatchOptions(10, 100000, 1),
        function (PendingBatchItem $item, BatchItemOutcome $outcome) use (&$settled): void {
            $settled[$item->item->jobId] = $outcome;
        },
    );

    return $settled;
}

beforeEach(function () {
    BatchOutcomeHandler::$mode = 'mixed';
});

test('settles mixed item outcomes with individual broker operations', function () {
    [$batch, $consumeChannel, $publishChannel] = processorBatch(['success', 'retry', 'failure']);
    $consumeChannel->shouldReceive('basic_ack')->once()->with(1);
    $consumeChannel->shouldReceive('basic_ack')->once()->with(2);
    $consumeChannel->shouldReceive('basic_reject')->once()->with(3, false);
    $publishChannel->shouldReceive('basic_publish')->once()->withArgs(function ($message, string $exchange, string $routingKey): bool {
        $headers = $message->get('application_headers')->getNativeData();
        $payload = json_decode($message->getBody(), true, flags: JSON_THROW_ON_ERROR);

        expect($exchange)->toBe('')
            ->and($routingKey)->toContain(':1000:')
            ->and($headers[RabbitMQJob::ATTEMPT_HEADER])->toBe(2)
            ->and($payload['data']['command'])->toContain('retry')
            ->and($payload['exception']['class'])->toBe(RuntimeException::class);

        return true;
    });
    $processed = null;
    $itemEvents = [];
    Event::listen(BatchProcessed::class, function (BatchProcessed $event) use (&$processed): void {
        $processed = $event;
    });
    Event::listen(BatchItemSettled::class, function (BatchItemSettled $event) use (&$itemEvents): void {
        $itemEvents[] = $event;
    });

    $settled = runProcessorBatch($batch);

    expect($settled)->toBe([
        'job-0' => BatchItemOutcome::Success,
        'job-1' => BatchItemOutcome::Retry,
        'job-2' => BatchItemOutcome::Failure,
    ])->and($processed)->toBeInstanceOf(BatchProcessed::class)
        ->and($processed->size)->toBe(3)
        ->and($processed->successes)->toBe(1)
        ->and($processed->retries)->toBe(1)
        ->and($processed->failures)->toBe(1)
        ->and($processed->collectionMilliseconds)->toBe(10.0)
        ->and($processed->processingMilliseconds)->toBeGreaterThanOrEqual(0.0)
        ->and($processed->settlementMilliseconds)->toBeGreaterThanOrEqual(0.0)
        ->and($itemEvents)->toHaveCount(3);
});

test('retries missing and duplicate results instead of losing an item', function (string $mode) {
    BatchOutcomeHandler::$mode = $mode;
    [$batch, $consumeChannel, $publishChannel] = processorBatch(['first', 'second']);
    $consumeChannel->shouldReceive('basic_ack')->twice();
    $publishChannel->shouldReceive('basic_publish')->once();
    $events = [];
    Event::listen(BatchItemSettled::class, function (BatchItemSettled $event) use (&$events): void {
        $events[] = $event;
    });

    $settled = runProcessorBatch($batch);

    expect(array_filter($settled, static fn (BatchItemOutcome $outcome): bool => $outcome === BatchItemOutcome::Retry))
        ->not->toBeEmpty()
        ->and(array_filter($events, static fn (BatchItemSettled $event): bool => $event->reason === 'invalid_result'))
        ->not->toBeEmpty();
})->with(['missing', 'duplicate']);

test('retries all items when the handler throws', function () {
    BatchOutcomeHandler::$mode = 'throw';
    [$batch, $consumeChannel, $publishChannel] = processorBatch(['first', 'second']);
    $consumeChannel->shouldReceive('basic_ack')->twice();
    $publishChannel->shouldReceive('basic_publish')->twice();
    $processed = null;
    Event::listen(BatchProcessed::class, function (BatchProcessed $event) use (&$processed): void {
        $processed = $event;
    });

    expect(runProcessorBatch($batch))->each->toBe(BatchItemOutcome::Retry)
        ->and($processed)->toBeInstanceOf(BatchProcessed::class)
        ->and($processed->handlerExceptionClass)->toBe(RuntimeException::class);
});

test('does not acknowledge the original item when retry publication fails', function () {
    [$batch, $consumeChannel, $publishChannel] = processorBatch(['retry']);
    $consumeChannel->shouldNotReceive('basic_ack');
    $publishChannel->shouldReceive('wait_for_pending_acks_returns')
        ->once()
        ->andThrow(new AMQPTimeoutException('confirmation lost'));
    $interrupted = null;
    Event::listen(BatchInterrupted::class, function (BatchInterrupted $event) use (&$interrupted): void {
        $interrupted = $event;
    });

    expect(fn () => runProcessorBatch($batch))
        ->toThrow(PublishException::class)
        ->and($interrupted)->toBeInstanceOf(BatchInterrupted::class)
        ->and($interrupted->settled)->toBe(0)
        ->and($interrupted->unacknowledged)->toBe(1);
});

test('does not report completion when acknowledgement fails after successful work', function () {
    [$batch, $consumeChannel] = processorBatch(['success']);
    $consumeChannel->shouldReceive('basic_ack')
        ->once()
        ->andThrow(new AMQPIOException('acknowledgement lost'));
    $processed = false;
    $interrupted = null;
    Event::listen(BatchProcessed::class, function () use (&$processed): void {
        $processed = true;
    });
    Event::listen(BatchInterrupted::class, function (BatchInterrupted $event) use (&$interrupted): void {
        $interrupted = $event;
    });

    expect(fn () => runProcessorBatch($batch))
        ->toThrow(ConnectionException::class)
        ->and($processed)->toBeFalse()
        ->and($interrupted)->toBeInstanceOf(BatchInterrupted::class)
        ->and($interrupted->unacknowledged)->toBe(1);
});
