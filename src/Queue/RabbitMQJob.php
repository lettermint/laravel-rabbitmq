<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Queue;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Lettermint\RabbitMQ\Exceptions\ConnectionException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * RabbitMQ Job wrapper for Laravel.
 *
 * This class wraps a RabbitMQ message (AMQPMessage) in Laravel's Job interface,
 * allowing it to be processed by Laravel's queue worker.
 */
class RabbitMQJob extends Job implements JobContract
{
    /**
     * The RabbitMQ message.
     */
    protected AMQPMessage $message;

    /**
     * The RabbitMQ channel for acknowledgments.
     */
    protected AMQPChannel $channel;

    /**
     * The RabbitMQ queue implementation.
     */
    protected RabbitMQQueue $rabbitmq;

    /**
     * The name of the queue the job was pulled from.
     */
    protected string $queueName;

    /**
     * Decoded payload data.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $decoded = null;

    public function __construct(
        Container $container,
        RabbitMQQueue $rabbitmq,
        AMQPChannel $channel,
        AMQPMessage $message,
        string $connectionName,
        string $queueName
    ) {
        $this->container = $container;
        $this->rabbitmq = $rabbitmq;
        $this->channel = $channel;
        $this->message = $message;
        $this->connectionName = $connectionName;
        $this->queueName = $queueName;
    }

    /**
     * Release the job back into the queue.
     *
     * Rejects the message without requeue - the Dead Letter Queue (DLQ)
     * configuration handles retry logic. Note: The $delay parameter is
     * passed to parent::release() for Laravel compatibility but is not
     * directly used; retry delays are controlled by the DLQ/ConsumesQueue
     * retryDelays configuration.
     *
     * @param  int  $delay  Delay hint (not directly used, see DLQ configuration)
     *
     * @throws ConnectionException When message rejection fails
     */
    public function release($delay = 0): void
    {
        parent::release($delay);

        try {
            // Reject without requeue - let DLQ handle retry with configured delay
            $this->rabbitmq->reject($this->message, $this->channel, false);
        } catch (ConnectionException $e) {
            Log::error('Failed to release/reject RabbitMQ message', [
                'queue' => $this->queueName,
                'job_id' => $this->getJobId(),
                'job_name' => $this->getName(),
                'delivery_tag' => $this->message->getDeliveryTag(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Release the job with exception information stored in the payload.
     *
     * This method stores exception details in the message payload before
     * releasing, making them visible in DLQ inspection tools. Uses a
     * transactional ack+republish pattern since we can't modify the
     * original message body during rejection.
     *
     * @param  int  $delay  Delay hint (not directly used, see DLQ configuration)
     *
     * @throws ConnectionException When release fails
     */
    public function releaseWithException(int $delay, \Throwable $exception): void
    {
        parent::release($delay);

        try {
            // Build modified payload with exception info
            $payload = $this->decoded();
            $payload['exception'] = [
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => array_slice($exception->getTrace(), 0, 5),
            ];

            // Use transaction for atomic ack+republish
            $this->channel->tx_select();

            try {
                // Republish with exception info to the same queue
                // This will go through normal retry/DLQ flow
                $this->rabbitmq->pushRaw(
                    json_encode($payload, JSON_THROW_ON_ERROR),
                    $this->queueName
                );

                // Acknowledge original message
                $this->rabbitmq->ack($this->message, $this->channel);

                $this->channel->tx_commit();

                Log::debug('Job released with exception info', [
                    'queue' => $this->queueName,
                    'job_id' => $this->getJobId(),
                    'job_name' => $this->getName(),
                    'exception' => $exception->getMessage(),
                ]);
            } catch (\Throwable $txException) {
                // Rollback transaction
                try {
                    $this->channel->tx_rollback();
                } catch (\Throwable $rollbackException) {
                    // Channel may be in bad state
                }

                // Fall back to normal rejection (without exception info)
                Log::warning('Failed to release with exception info, falling back to reject', [
                    'queue' => $this->queueName,
                    'job_id' => $this->getJobId(),
                    'error' => $txException->getMessage(),
                ]);

                $this->rabbitmq->reject($this->message, $this->channel, false);
            }
        } catch (ConnectionException $e) {
            Log::error('Failed to release RabbitMQ message with exception', [
                'queue' => $this->queueName,
                'job_id' => $this->getJobId(),
                'job_name' => $this->getName(),
                'delivery_tag' => $this->message->getDeliveryTag(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Delete the job from the queue (acknowledge successful processing).
     *
     * CRITICAL: If acknowledgment fails after job completion, the message
     * may be redelivered causing duplicate processing. We log at CRITICAL
     * level but don't throw to avoid masking the original job success.
     */
    public function delete(): void
    {
        parent::delete();

        try {
            $this->rabbitmq->ack($this->message, $this->channel);
        } catch (ConnectionException $e) {
            // CRITICAL: Job completed but ack failed - potential duplicate processing
            Log::critical('Job completed but acknowledgment failed - potential duplicate processing', [
                'queue' => $this->queueName,
                'job_id' => $this->getJobId(),
                'job_name' => $this->getName(),
                'delivery_tag' => $this->message->getDeliveryTag(),
                'error' => $e->getMessage(),
            ]);

            // Report to error tracking (Sentry, etc.) but don't throw
            // because the job itself succeeded
            report($e);
        }
    }

    /**
     * Get the number of times the job has been attempted.
     *
     * Checks payload first (for replayed DLQ messages), then falls back
     * to x-death headers. This is necessary because x-death headers are
     * lost when messages are replayed via pushRaw().
     */
    public function attempts(): int
    {
        $payload = $this->decoded();

        // Check payload first - this is set when replaying from DLQ
        if (isset($payload['attempts']) && is_int($payload['attempts'])) {
            return $payload['attempts'];
        }

        // Fall back to x-death header (native RabbitMQ retry tracking)
        $headers = $this->getHeaders();

        if (isset($headers['x-death']) && is_array($headers['x-death'])) {
            $totalCount = 0;
            foreach ($headers['x-death'] as $death) {
                // php-amqplib may return AMQPTable for nested structures
                $deathData = $death instanceof AMQPTable ? $death->getNativeData() : $death;
                $totalCount += (int) ($deathData['count'] ?? 0);
            }

            return $totalCount + 1;
        }

        return 1;
    }

    /**
     * Get the job identifier.
     */
    public function getJobId(): ?string
    {
        $messageId = $this->getMessageProperty('message_id');

        return $messageId ?: $this->decoded()['uuid'] ?? null;
    }

    /**
     * Get the raw body of the job.
     */
    public function getRawBody(): string
    {
        return $this->message->getBody();
    }

    /**
     * Get the name of the queue the job belongs to.
     */
    public function getQueue(): string
    {
        return $this->queueName;
    }

    /**
     * Get the decoded payload.
     *
     * Throws an exception on JSON decode failure to prevent processing
     * of malformed messages. The exception will cause the job to fail
     * and be sent to the DLQ.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException When payload cannot be decoded
     */
    protected function decoded(): array
    {
        if ($this->decoded === null) {
            $body = $this->getRawBody();
            $decoded = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $errorMsg = json_last_error_msg();

                Log::critical('Failed to decode RabbitMQ message payload - job will be rejected', [
                    'queue' => $this->queueName,
                    'delivery_tag' => $this->message->getDeliveryTag(),
                    'message_id' => $this->getMessageProperty('message_id'),
                    'json_error' => $errorMsg,
                    'body_preview' => substr($body, 0, 200),
                ]);

                throw new \RuntimeException(
                    "Cannot process job: invalid JSON payload - {$errorMsg}"
                );
            }

            $this->decoded = $decoded ?? [];
        }

        return $this->decoded;
    }

    /**
     * Get the name of the job's class.
     */
    public function getName(): string
    {
        return $this->decoded()['displayName'] ?? $this->decoded()['job'] ?? 'Unknown';
    }

    /**
     * Get the resolved name of the job's class.
     */
    public function resolveName(): string
    {
        return $this->decoded()['displayName'] ?? $this->decoded()['data']['commandName'] ?? 'Unknown';
    }

    /**
     * Get the underlying message.
     */
    public function getMessage(): AMQPMessage
    {
        return $this->message;
    }

    /**
     * Get the underlying channel.
     */
    public function getChannel(): AMQPChannel
    {
        return $this->channel;
    }

    /**
     * Get a message property safely.
     */
    protected function getMessageProperty(string $name): mixed
    {
        if ($this->message->has($name)) {
            return $this->message->get($name);
        }

        return null;
    }

    /**
     * Get the message priority.
     */
    public function getPriority(): int
    {
        return (int) ($this->getMessageProperty('priority') ?? 0);
    }

    /**
     * Get the message timestamp.
     */
    public function getTimestamp(): int
    {
        return (int) ($this->getMessageProperty('timestamp') ?? 0);
    }

    /**
     * Get message headers.
     *
     * @return array<string, mixed>
     */
    public function getHeaders(): array
    {
        $headers = $this->getMessageProperty('application_headers');

        if ($headers instanceof AMQPTable) {
            return $headers->getNativeData();
        }

        return $headers ?? [];
    }

    /**
     * Check if this message was dead-lettered.
     */
    public function wasDeadLettered(): bool
    {
        $headers = $this->getHeaders();

        return isset($headers['x-death']);
    }

    /**
     * Get the original queue before dead-lettering.
     */
    public function getOriginalQueue(): ?string
    {
        $headers = $this->getHeaders();

        if (isset($headers['x-death'][0])) {
            $xDeath = $headers['x-death'][0];
            // php-amqplib may return AMQPTable for nested structures
            $xDeathData = $xDeath instanceof AMQPTable ? $xDeath->getNativeData() : $xDeath;

            return $xDeathData['queue'] ?? null;
        }

        return null;
    }

    /**
     * Get the payload array from the job.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->decoded();
    }

    /**
     * Get the maximum number of tries.
     */
    public function maxTries(): ?int
    {
        return Arr::get($this->decoded(), 'maxTries');
    }

    /**
     * Get the maximum exceptions.
     */
    public function maxExceptions(): ?int
    {
        return Arr::get($this->decoded(), 'maxExceptions');
    }

    /**
     * Get the number of seconds to wait before retrying.
     *
     * @return int|int[]|null
     */
    public function backoff(): int|array|null
    {
        return Arr::get($this->decoded(), 'backoff');
    }

    /**
     * Get the job timeout.
     */
    public function timeout(): ?int
    {
        return Arr::get($this->decoded(), 'timeout');
    }

    /**
     * Get the timestamp indicating when the job should timeout.
     */
    public function retryUntil(): ?int
    {
        return Arr::get($this->decoded(), 'retryUntil');
    }
}
