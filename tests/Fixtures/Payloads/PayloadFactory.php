<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Tests\Fixtures\Payloads;

use Illuminate\Support\Str;

/**
 * Factory for creating test job payloads.
 */
class PayloadFactory
{
    /**
     * Create a job payload JSON string.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function create(string $class, array $overrides = []): string
    {
        $defaults = [
            'uuid' => Str::uuid()->toString(),
            'displayName' => class_basename($class),
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [
                'commandName' => $class,
                'command' => 'O:8:"stdClass":0:{}',
            ],
            'maxTries' => null,
            'maxExceptions' => null,
            'backoff' => null,
            'timeout' => null,
            'retryUntil' => null,
        ];

        return json_encode(array_replace_recursive($defaults, $overrides));
    }

    /**
     * Create a payload with x-death header data (simulating DLQ redelivery).
     */
    public static function withXDeath(string $class, string $originalQueue, int $count = 1): string
    {
        return self::create($class, [
            'attempts' => $count,
        ]);
    }

    /**
     * Create a minimal payload with just the required fields.
     */
    public static function minimal(string $class): string
    {
        return json_encode([
            'uuid' => Str::uuid()->toString(),
            'displayName' => class_basename($class),
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [
                'commandName' => $class,
            ],
        ]);
    }

    /**
     * Create a payload with max tries specified.
     */
    public static function withMaxTries(string $class, int $maxTries): string
    {
        return self::create($class, [
            'maxTries' => $maxTries,
        ]);
    }

    /**
     * Create a payload with timeout specified.
     */
    public static function withTimeout(string $class, int $timeout): string
    {
        return self::create($class, [
            'timeout' => $timeout,
        ]);
    }

    /**
     * Create a payload for a delayed job.
     */
    public static function delayed(string $class, int $delaySeconds): string
    {
        return self::create($class, [
            'delay' => $delaySeconds,
        ]);
    }
}
