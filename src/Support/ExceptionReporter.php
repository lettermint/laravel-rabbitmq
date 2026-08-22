<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Support;

use Throwable;

final class ExceptionReporter
{
    public static function report(Throwable $exception): void
    {
        try {
            report($exception);
        } catch (Throwable) {
            // Monitoring must not replace a broker or queue operation result.
        }
    }
}
