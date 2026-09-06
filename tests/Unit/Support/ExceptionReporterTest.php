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

    $path = tempnam(sys_get_temp_dir(), 'rabbitmq-report-');
    $previous = ini_set('error_log', $path);
    try {
        ExceptionReporter::report(new RuntimeException('broker failure'));
        expect(file_get_contents($path))->toContain('RabbitMQ exception reporting failed: RuntimeException; original exception: RuntimeException')
            ->not->toContain('broker failure');
    } finally {
        ini_set('error_log', $previous);
        unlink($path);
    }
});
