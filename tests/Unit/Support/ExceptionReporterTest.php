<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Lettermint\RabbitMQ\Support\ExceptionReporter;

test('a monitoring failure does not replace the reported exception', function () {
    $handler = Mockery::mock(ExceptionHandler::class);
    $handler->shouldReceive('report')
        ->once()
        ->with(Mockery::on(fn (Throwable $exception): bool => $exception->getMessage() === 'broker failure'))
        ->andThrow(new RuntimeException('monitoring failure'));
    app()->instance(ExceptionHandler::class, $handler);

    ExceptionReporter::report(new RuntimeException('broker failure'));

    expect(true)->toBeTrue();
});
