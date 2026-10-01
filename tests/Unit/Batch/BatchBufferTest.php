<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Batch\BatchBuffer;
use Lettermint\RabbitMQ\Batch\BatchItem;
use Lettermint\RabbitMQ\Batch\BatchOptions;
use Lettermint\RabbitMQ\Batch\PendingBatchItem;
use Lettermint\RabbitMQ\Queue\RabbitMQJob;

function pendingBatchItem(int $bytes): PendingBatchItem
{
    return new PendingBatchItem(
        Mockery::mock(RabbitMQJob::class),
        new BatchItem(new stdClass, 'job-1', 'events', 1, 0, false, 0, $bytes),
    );
}

test('flush limits use count and bytes', function () {
    $buffer = new BatchBuffer(new BatchOptions(2, 10, 5));

    $buffer->append(pendingBatchItem(4));
    expect($buffer->reachedLimit(2))->toBeFalse()
        ->and($buffer->wouldExceedBytes(7))->toBeTrue();

    $buffer->append(pendingBatchItem(6));
    expect($buffer->reachedLimit(2))->toBeTrue()
        ->and($buffer->payloadBytes())->toBe(10)
        ->and($buffer->drain()->items)->toHaveCount(2)
        ->and($buffer->isEmpty())->toBeTrue();
});

test('time limit uses a monotonic clock when no new item arrives', function () {
    $now = 1_000_000_000;
    $buffer = new BatchBuffer(new BatchOptions(10, 100, 0.5), function () use (&$now): int {
        return $now;
    });
    $buffer->append(pendingBatchItem(1));

    $now += 499_000_000;
    expect($buffer->expired())->toBeFalse()
        ->and($buffer->nextWaitTimeout(1))->toBeLessThanOrEqual(0.0011);

    $now += 1_000_000;
    expect($buffer->expired())->toBeTrue()
        ->and($buffer->drain()->collectionMilliseconds)->toBe(500.0);
});

test('rejects invalid limits and an oversized item', function () {
    expect(fn () => new BatchOptions(0, 1, 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new BatchOptions(1, 0, 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new BatchOptions(1, 1, 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new BatchOptions(1, 1, 1, 0))->toThrow(InvalidArgumentException::class);

    $buffer = new BatchBuffer(new BatchOptions(1, 2, 1));
    expect(fn () => $buffer->append(pendingBatchItem(3)))->toThrow(LogicException::class);
});
