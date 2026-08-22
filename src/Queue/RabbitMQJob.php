<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Queue;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Lettermint\RabbitMQ\Events\JobDeadLettered;
use Lettermint\RabbitMQ\Events\JobReleased;
use Lettermint\RabbitMQ\Events\JobRetried;
use Lettermint\RabbitMQ\Exceptions\ConnectionException;
use Lettermint\RabbitMQ\Exceptions\PublishException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * RabbitMQ Job wrapper for Laravel.
 *
 * This class wraps a RabbitMQ message (AMQPMessage) in Laravel's Job interface,
 * allowing it to be processed by Laravel's queue worker.
 */
class RabbitMQJob extends Job implements JobContract
{
    public const ATTEMPT_HEADER = 'x-lettermint-attempt';

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

    protected ?Throwable $failureException = null;

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
     * Publishes the next intentional attempt before it acknowledges this
     * delivery. This order prevents message loss. An acknowledgment failure can
     * cause a duplicate message, which is part of at-least-once delivery.
     *
     * @param  int  $delay  Delay in seconds
     *
     * @throws ConnectionException When acknowledgment fails
     * @throws PublishException When the replacement message cannot be published
     */
    public function release($delay = 0): void
    {
        parent::release($delay);

        $this->republish($this->decoded(), (int) $delay);
    }

    /**
     * Release the job with exception information stored in the payload.
     *
     * This method stores exception details in the message payload before
     * releasing, making them visible in DLQ inspection tools. The publish and
     * acknowledgment use different AMQP channels, so they cannot be atomic.
     * The package publishes first. This can cause a duplicate after an uncertain
     * acknowledgment, but it does not silently discard the job.
     *
     * @param  int  $delay  Delay in seconds
     *
     * @throws ConnectionException When acknowledgment fails
     * @throws PublishException When the replacement message cannot be published
     */
    public function releaseWithException(int $delay, Throwable $exception): void
    {
        parent::release($delay);

        $payload = $this->decoded();
        $payload['exception'] = [
            'class' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
        ];

        $this->republish($payload, $delay);
    }

    /**
     * Publish the next application attempt and then acknowledge this delivery.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function republish(array $payload, int $delay): void
    {
        $properties = $this->message->get_properties();
        $headers = $this->getHeaders();

        // Broker delivery count is only crash-loop protection. An intentional
        // Laravel release uses its own counter and starts a new broker delivery
        // sequence. RabbitMQ owns x-delivery-count and must set it itself.
        unset($headers['x-delivery-count']);
        $headers[self::ATTEMPT_HEADER] = $this->attempts() + 1;
        $properties['application_headers'] = new AMQPTable($headers);

        try {
            $this->rabbitmq->pushRaw(
                json_encode($payload, JSON_THROW_ON_ERROR),
                $this->queueName,
                [
                    'delay' => max(0, $delay),
                    'properties' => $properties,
                ],
            );

            $this->rabbitmq->ack($this->message, $this->channel);

            $this->dispatchEvent(new JobReleased(
                queue: $this->queueName,
                jobId: $this->getJobId(),
                jobName: $this->getName(),
                attempt: $this->attempts() + 1,
                delay: $delay,
                messageTimestamp: $this->getTimestamp(),
                redelivered: $this->message->isRedelivered(),
                brokerDeliveryCount: $this->brokerDeliveryCount(),
            ));

            $this->dispatchEvent(new JobRetried(
                queue: $this->queueName,
                jobId: $this->getJobId(),
                jobName: $this->getName(),
                attempt: $this->attempts() + 1,
                delay: $delay,
                messageTimestamp: $this->getTimestamp(),
                redelivered: $this->message->isRedelivered(),
                brokerDeliveryCount: $this->brokerDeliveryCount(),
            ));
        } catch (Throwable $e) {
            Log::critical('Failed to release RabbitMQ message; original delivery remains unacknowledged', [
                'queue' => $this->queueName,
                'job_id' => $this->getJobId(),
                'job_name' => $this->getName(),
                'delivery_tag' => $this->message->getDeliveryTag(),
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Acknowledge a successful job or reject a final failure to the DLQ.
     */
    public function delete(): void
    {
        parent::delete();

        try {
            if ($this->hasFailed()) {
                $this->rabbitmq->reject($this->message, $this->channel, false);

                $this->dispatchEvent(new JobDeadLettered(
                    queue: $this->queueName,
                    jobId: $this->getJobId(),
                    jobName: $this->getName(),
                    attempt: $this->attempts(),
                    exception: $this->failureException ?? new \RuntimeException('RabbitMQ job failed'),
                    messageTimestamp: $this->getTimestamp(),
                    redelivered: $this->message->isRedelivered(),
                    brokerDeliveryCount: $this->brokerDeliveryCount(),
                ));

                return;
            }

            $this->rabbitmq->ack($this->message, $this->channel);
        } catch (ConnectionException $e) {
            Log::critical('RabbitMQ final delivery action failed', [
                'queue' => $this->queueName,
                'job_id' => $this->getJobId(),
                'job_name' => $this->getName(),
                'delivery_tag' => $this->message->getDeliveryTag(),
                'operation' => $this->hasFailed() ? 'dead_letter' : 'acknowledge',
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Fail the job and route the canonical message to its dead-letter queue.
     */
    public function fail($e = null): void
    {
        $this->failureException = $e instanceof Throwable
            ? $e
            : new \RuntimeException('RabbitMQ job failed');

        parent::fail($e);
    }

    private function dispatchEvent(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Get the number of times the job has been attempted.
     *
     * Intentional Laravel releases use the package header. Broker delivery
     * counters do not increase application attempts because they can increase
     * after a worker crash, a lost connection, or an uncertain acknowledgment.
     * Payload attempts remain as a compatibility fallback for old DLQ replays.
     */
    public function attempts(): int
    {
        $payload = $this->decoded();

        $headers = $this->getHeaders();
        $attempt = $headers[self::ATTEMPT_HEADER] ?? null;

        if (is_int($attempt) && $attempt > 0) {
            return $attempt;
        }

        if (isset($payload['attempts']) && is_int($payload['attempts']) && $payload['attempts'] > 0) {
            return $payload['attempts'];
        }

        return 1;
    }

    /**
     * Get the RabbitMQ delivery count for crash-loop diagnostics.
     */
    public function brokerDeliveryCount(): int
    {
        return max(0, (int) ($this->getHeaders()['x-delivery-count'] ?? 0));
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
