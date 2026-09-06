<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Actions\Dlq\Results;

use Illuminate\Support\Carbon;
use Lettermint\RabbitMQ\Queue\RabbitMQJob;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

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
        $decoded = json_decode($message->getBody(), true);
        $payload = is_array($decoded) ? $decoded : [];

        $headerTable = $message->has('application_headers')
            ? $message->get('application_headers')
            : null;
        $headers = $headerTable instanceof AMQPTable ? $headerTable->getNativeData() : [];

        $xDeath = $headers['x-death'][0] ?? null;
        $xDeath = $xDeath instanceof AMQPTable ? $xDeath->getNativeData() : $xDeath;

        $attemptValue = $headers[RabbitMQJob::ATTEMPT_HEADER] ?? $payload['attempts'] ?? 1;
        $attempts = is_numeric($attemptValue) ? max(1, (int) $attemptValue) : 1;
        $failedAt = self::extractFailedAt(is_array($xDeath) ? $xDeath : null);
        $reason = is_array($xDeath) && is_string($xDeath['reason'] ?? null) ? $xDeath['reason'] : 'unknown';

        $exception = null;
        if (isset($payload['exception']) && is_array($payload['exception'])) {
            $exception = $payload['exception'];
        }

        $id = $payload['uuid'] ?? $payload['id'] ?? null;
        $id = is_string($id) || is_numeric($id)
            ? (string) $id
            : ($message->has('message_id') ? (string) $message->get('message_id') : 'body:'.hash('sha256', $message->getBody()));
        $jobClass = $payload['displayName'] ?? $payload['job'] ?? 'Unknown';
        $jobClass = is_string($jobClass) ? $jobClass : 'Unknown';

        return new self(
            id: $id,
            jobClass: $jobClass,
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
        try {
            if (is_object($timestamp) && method_exists($timestamp, 'getTimestamp')) {
                return Carbon::createFromTimestamp($timestamp->getTimestamp());
            }
            if (is_numeric($timestamp)) {
                return Carbon::createFromTimestamp($timestamp);
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
