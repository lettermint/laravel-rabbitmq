<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Events;

final readonly class DlqOperationFinished
{
    /** @param array<string, int|string|bool|null> $context */
    public function __construct(public array $context) {}
}
