<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Encryption\Encrypter;
use Lettermint\RabbitMQ\Batch\BatchItemFactory;
use Lettermint\RabbitMQ\Exceptions\MalformedBatchMessageException;
use Lettermint\RabbitMQ\Exceptions\UnsupportedBatchJobException;
use Lettermint\RabbitMQ\Queue\RabbitMQJob;
use Lettermint\RabbitMQ\Tests\Fixtures\Jobs\SimpleJob;

function batchDelivery(array $payload): RabbitMQJob
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $delivery = Mockery::mock(RabbitMQJob::class);
    $delivery->shouldReceive('payload')->andReturn($payload);
    $delivery->shouldReceive('getJobId')->andReturn('item-1');
    $delivery->shouldReceive('getQueue')->andReturn('events');
    $delivery->shouldReceive('attempts')->andReturn(2);
    $delivery->shouldReceive('brokerDeliveryCount')->andReturn(3);
    $delivery->shouldReceive('getTimestamp')->andReturn(123);
    $delivery->shouldReceive('getRawBody')->andReturn($body);
    $message = mockAMQPMessage(['redelivered' => true]);
    $delivery->shouldReceive('getMessage')->andReturn($message);

    return $delivery;
}

test('restores a typed Laravel object job with delivery metadata', function () {
    $job = new SimpleJob('preserved');
    $delivery = batchDelivery([
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => [
            'commandName' => SimpleJob::class,
            'command' => serialize($job),
        ],
    ]);

    $item = (new BatchItemFactory(new Container))->make($delivery);

    expect($item->job)->toBeInstanceOf(SimpleJob::class)
        ->and($item->job->message)->toBe('preserved')
        ->and($item->jobId)->toBe('item-1')
        ->and($item->attempt)->toBe(2)
        ->and($item->brokerDeliveryCount)->toBe(3)
        ->and($item->redelivered)->toBeTrue()
        ->and($item->payloadBytes)->toBeGreaterThan(0);
});

test('rejects malformed and mismatched job payloads', function (array $payload) {
    expect(fn () => (new BatchItemFactory(new Container))->make(batchDelivery($payload)))
        ->toThrow(MalformedBatchMessageException::class);
})->with([
    'wrong handler' => [[
        'job' => 'Example@handle',
        'data' => [],
    ]],
    'class mismatch' => [[
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => [
            'commandName' => SimpleJob::class,
            'command' => serialize(new stdClass),
        ],
    ]],
    'invalid serialization' => [[
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => [
            'commandName' => SimpleJob::class,
            'command' => 'not-serialized',
        ],
    ]],
]);

test('restores an encrypted Laravel object job', function () {
    $container = new Container;
    $encrypter = new Encrypter(random_bytes(32), 'AES-256-CBC');
    $container->instance(EncrypterContract::class, $encrypter);
    $job = new SimpleJob('encrypted');
    $delivery = batchDelivery([
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => [
            'commandName' => SimpleJob::class,
            'command' => $encrypter->encrypt(serialize($job)),
        ],
    ]);

    $item = (new BatchItemFactory($container))->make($delivery);

    expect($item->job)->toBeInstanceOf(SimpleJob::class)
        ->and($item->job->message)->toBe('encrypted');
});

test('rejects an unsupported declared class before it restores the job', function () {
    $job = new SimpleJob('unsupported');
    $delivery = batchDelivery([
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => [
            'commandName' => SimpleJob::class,
            'command' => serialize($job),
        ],
    ]);

    expect(fn () => (new BatchItemFactory(new Container))->make($delivery, [stdClass::class]))
        ->toThrow(UnsupportedBatchJobException::class);
});
