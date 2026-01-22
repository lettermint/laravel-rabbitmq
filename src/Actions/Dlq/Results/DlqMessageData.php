<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Actions\Dlq\Results;

use Illuminate\Support\Carbon;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Data extracted from a DLQ message for display or processing.
 */
final readonly class DlqMessageData
{
    /**
     * @param  array<string, mixed>|null  $exception
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $id,
        public string $jobClass,
        public int $attempts,
        public ?Carbon $failedAt,
        public string $reason,
        public ?array $exception,
        public array $payload,
        public string $rawBody,
    ) {}

    public static function fromAmqpMessage(AMQPMessage $message): self
    {
        $payload = json_decode($message->getBody(), true) ?? [];

        $headers = $message->has('application_headers')
            ? $message->get('application_headers')->getNativeData()
            : [];

        $xDeath = $headers['x-death'][0] ?? null;

        $attempts = $payload['attempts'] ?? $xDeath['count'] ?? 1;
        $failedAt = self::extractFailedAt($xDeath);
        $reason = $xDeath['reason'] ?? 'unknown';

        $exception = null;
        if (isset($payload['exception'])) {
            $exception = $payload['exception'];
        }

        return new self(
            id: $payload['uuid'] ?? $payload['id'] ?? 'unknown',
            jobClass: $payload['displayName'] ?? $payload['job'] ?? 'Unknown',
            attempts: $attempts,
            failedAt: $failedAt,
            reason: $reason,
            exception: $exception,
            payload: $payload,
            rawBody: $message->getBody(),
        );
    }

    /**
     * @param  array<string, mixed>|null  $xDeath
     */
    private static function extractFailedAt(?array $xDeath): ?Carbon
    {
        if (! isset($xDeath['time'])) {
            return null;
        }

        $timestamp = $xDeath['time'];
        if (is_object($timestamp) && method_exists($timestamp, 'getTimestamp')) {
            return Carbon::createFromTimestamp($timestamp->getTimestamp());
        }
        if (is_numeric($timestamp)) {
            return Carbon::createFromTimestamp($timestamp);
        }

        return null;
    }
}
