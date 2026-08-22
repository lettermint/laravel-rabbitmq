<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Lettermint\RabbitMQ\Filament\Pages\RabbitMQDeadLetters;

final class RabbitMQPlugin implements Plugin
{
    public static function make(): self
    {
        return new self;
    }

    public function getId(): string
    {
        return 'lettermint-rabbitmq';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([
            RabbitMQDeadLetters::class,
        ]);
    }

    public function boot(Panel $panel): void {}
}
