<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Lettermint\RabbitMQ\Enums\BatchItemOutcome;
use Lettermint\RabbitMQ\Events\BatchItemSettled;
use Lettermint\RabbitMQ\Events\BatchProcessed;

test('batch lifecycle logs report aggregate and item data without payload contents', function () {
    Log::spy();

    event(new BatchProcessed(
        batchId: 'batch-1',
        connection: 'rabbitmq',
        queue: 'events',
        handler: 'App\\EventBatchHandler',
        size: 3,
        payloadBytes: 1200,
        collectionMilliseconds: 50,
        processingMilliseconds: 25,
        settlementMilliseconds: 5,
        successes: 1,
        retries: 1,
        failures: 1,
        invalidResults: 0,
    ));
    event(new BatchItemSettled(
        batchId: 'batch-1',
        connection: 'rabbitmq',
        queue: 'events',
        jobId: 'job-1',
        jobClass: 'App\\StoreEvent',
        attempt: 2,
        brokerDeliveryCount: 1,
        redelivered: true,
        messageTimestamp: time(),
        payloadBytes: 400,
        outcome: BatchItemOutcome::Retry,
        reason: 'handler_retry',
        processingMilliseconds: 25,
        settlementMilliseconds: 2,
    ));

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
        return $message === 'RabbitMQ batch processed'
            && $context['batch_size'] === 3
            && $context['successes'] === 1
            && $context['retries'] === 1
            && $context['failures'] === 1
            && ! array_key_exists('payload', $context)
            && ! array_key_exists('body', $context);
    })->once();
    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
        return $message === 'RabbitMQ batch item settled'
            && $context['job_id'] === 'job-1'
            && $context['outcome'] === 'retry'
            && ! array_key_exists('payload', $context)
            && ! array_key_exists('body', $context);
    })->once();
});
