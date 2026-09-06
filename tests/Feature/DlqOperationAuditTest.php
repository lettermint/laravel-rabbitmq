<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Lettermint\RabbitMQ\Actions\Dlq\Results\DlqPurgeResult;
use Lettermint\RabbitMQ\Actions\Dlq\Results\DlqReplayResult;
use Lettermint\RabbitMQ\Events\DlqOperationFinished;
use Lettermint\RabbitMQ\Support\DlqOperationAudit;

test('DLQ audit records incomplete and uncertain operations without payload data', function () {
    Event::fake([DlqOperationFinished::class]);
    $result = new DlqReplayResult(2, 1, false, incomplete: true, uncertain: true);

    expect(DlqOperationAudit::run('replay', 'default', null, false, fn () => $result))->toBe($result);

    Event::assertDispatched(DlqOperationFinished::class, fn ($event) => $event->context['count'] === 2
        && $event->context['failed_count'] === 1
        && $event->context['uncertain'] === true
        && $event->context['incomplete'] === true
        && ! array_key_exists('payload', $event->context));
});

test('DLQ audit records preflight failure and preserves its exception', function () {
    Event::fake([DlqOperationFinished::class]);
    $failure = new RuntimeException('Queue access failed');

    expect(fn () => DlqOperationAudit::run('purge', 'default', 'id-1', false, fn () => throw $failure))
        ->toThrow($failure);

    Event::assertDispatched(DlqOperationFinished::class, fn ($event) => $event->context['operation_error'] === true
        && $event->context['error_class'] === RuntimeException::class
        && $event->context['count'] === 0);
});

test('an audit listener failure cannot change a completed purge result', function () {
    Event::listen(DlqOperationFinished::class, fn () => throw new RuntimeException('Audit listener failed'));
    $result = new DlqPurgeResult(1, 0, false);

    expect(DlqOperationAudit::run('purge', 'default', 'id-1', false, fn () => $result))->toBe($result);
});
