<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Queue;

use AMQPEnvelope;
use AMQPQueue;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Lettermint\RabbitMQ\Exceptions\ConnectionException;

/**
 * RabbitMQ Job wrapper for Laravel.
 *
 * This class wraps a RabbitMQ message (AMQPEnvelope) in Laravel's Job interface,
 * allowing it to be processed by Laravel's queue worker.
 */
class RabbitMQJob extends Job implements JobContract
{
    /**
     * The RabbitMQ envelope containing the message.
     */
    protected AMQPEnvelope $envelope;

    /**
     * The RabbitMQ queue instance.
     */
    protected AMQPQueue $amqpQueue;

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
        AMQPQueue $queue,
        AMQPEnvelope $envelope,
        string $connectionName,
        string $queueName
    ) {
        $this->container = $container;
        $this->rabbitmq = $rabbitmq;
        $this->amqpQueue = $queue;
        $this->envelope = $envelope;
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
            $this->rabbitmq->reject($this->envelope, $this->amqpQueue, false);
        } catch (ConnectionException $e) {
            Log::error('Failed to release/reject RabbitMQ message', [
                'queue' => $this->queueName,
                'job_id' => $this->getJobId(),
                'job_name' => $this->getName(),
                'delivery_tag' => $this->envelope->getDeliveryTag(),
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
            $this->rabbitmq->ack($this->envelope, $this->amqpQueue);
        } catch (ConnectionException $e) {
            // CRITICAL: Job completed but ack failed - potential duplicate processing
            Log::critical('Job completed but acknowledgment failed - potential duplicate processing', [
                'queue' => $this->queueName,
                'job_id' => $this->getJobId(),
                'job_name' => $this->getName(),
                'delivery_tag' => $this->envelope->getDeliveryTag(),
                'error' => $e->getMessage(),
            ]);

            // Report to error tracking (Sentry, etc.) but don't throw
            // because the job itself succeeded
            report($e);
        }
    }

    /**
     * Get the number of times the job has been attempted.
     */
    public function attempts(): int
    {
        $headers = $this->envelope->getHeaders();

        // x-death header tracks routing through DLQs
        if (isset($headers['x-death']) && is_array($headers['x-death'])) {
            $totalCount = 0;
            foreach ($headers['x-death'] as $death) {
                $totalCount += (int) ($death['count'] ?? 0);
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
        return $this->envelope->getMessageId() ?: $this->decoded()['uuid'] ?? null;
    }

    /**
     * Get the raw body of the job.
     */
    public function getRawBody(): string
    {
        return $this->envelope->getBody();
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
                    'delivery_tag' => $this->envelope->getDeliveryTag(),
                    'message_id' => $this->envelope->getMessageId(),
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
     * Get the underlying envelope.
     */
    public function getEnvelope(): AMQPEnvelope
    {
        return $this->envelope;
    }

    /**
     * Get the underlying AMQPQueue.
     */
    public function getAMQPQueue(): AMQPQueue
    {
        return $this->amqpQueue;
    }

    /**
     * Get the message priority.
     */
    public function getPriority(): int
    {
        return $this->envelope->getPriority();
    }

    /**
     * Get the message timestamp.
     */
    public function getTimestamp(): int
    {
        return $this->envelope->getTimestamp();
    }

    /**
     * Get message headers.
     *
     * @return array<string, mixed>
     */
    public function getHeaders(): array
    {
        return $this->envelope->getHeaders();
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

        if (isset($headers['x-death'][0]['queue'])) {
            return $headers['x-death'][0]['queue'];
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
