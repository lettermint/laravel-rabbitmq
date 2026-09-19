<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Enums;

enum BatchItemOutcome: string
{
    case Success = 'success';
    case Retry = 'retry';
    case Failure = 'failure';
}
