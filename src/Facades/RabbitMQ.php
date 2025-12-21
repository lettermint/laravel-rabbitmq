<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Facades;

use Illuminate\Support\Facades\Facade;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;

/**
 * RabbitMQ Facade.
 *
 * @method static mixed push(object|string $job, mixed $data = '', ?string $queue = null)
 * @method static mixed pushRaw(string $payload, ?string $queue = null, array $options = [])
 * @method static mixed later(\DateTimeInterface|\DateInterval|int $delay, object|string $job, mixed $data = '', ?string $queue = null)
 * @method static int size(?string $queue = null)
 * @method static \Illuminate\Contracts\Queue\Job|null pop(?string $queue = null)
 *
 * @see \Lettermint\RabbitMQ\Queue\RabbitMQQueue
 */
class RabbitMQ extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return RabbitMQQueue::class;
    }
}
