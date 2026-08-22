<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Queue;

use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Contracts\HasPriority;
use Lettermint\RabbitMQ\Contracts\HasRoutingKey;
use Lettermint\RabbitMQ\Events\MessagePublished;
use Lettermint\RabbitMQ\Events\MessagePublishFailed;
use Lettermint\RabbitMQ\Exceptions\ConnectionException;
use Lettermint\RabbitMQ\Exceptions\PublishException;
use Lettermint\RabbitMQ\Support\ExceptionReporter;
use Lettermint\RabbitMQ\Topology\QueueDefinition;
use Lettermint\RabbitMQ\Topology\TopologyRegistry;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

final class RabbitMQQueue extends Queue implements QueueContract
{
    /** @var array<string, mixed> */
    protected $config;

    protected string $default;

    protected string $brokerConnection;

    protected float $confirmTimeout;

    protected int $maximumDelay;

    protected bool $mandatory;

    protected bool $useConfirms;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        protected ChannelManager $channelManager,
        protected TopologyRegistry $registry,
        protected Dispatcher $events,
        array $config,
    ) {
        $this->config = $config;
        $queue = $config['queue'] ?? 'default';
        $this->default = is_array($queue) ? (string) ($queue['default'] ?? 'default') : (string) $queue;
        $this->brokerConnection = (string) ($config['connection'] ?? $config['default'] ?? 'default');
        $publisher = is_array($config['publisher'] ?? null) ? $config['publisher'] : [];
        $this->confirmTimeout = (float) ($publisher['confirm_timeout'] ?? $config['confirm_timeout'] ?? 5.0);
        $this->useConfirms = (bool) ($publisher['confirm'] ?? $config['publisher_confirms'] ?? true);
        $this->mandatory = (bool) ($publisher['mandatory'] ?? $config['mandatory'] ?? true);
        $this->maximumDelay = (int) ($config['retry']['maximum_delay'] ?? $config['maximum_delay'] ?? 86400);

        if (! $this->useConfirms || ! $this->mandatory) {
            throw new InvalidArgumentException(
                'The Lettermint RabbitMQ driver requires publisher confirmations and mandatory routing.'
            );
        }

        if ($this->confirmTimeout <= 0) {
            throw new InvalidArgumentException('RabbitMQ publisher confirm_timeout must be greater than zero.');
        }
    }

    public function size($queue = null): int
    {
        $logicalQueue = $this->getQueue($queue);
        $physicalQueue = $this->physicalQueue($logicalQueue);

        try {
            [, $messageCount] = $this->channelManager
                ->topologyChannel($this->brokerConnection)
                ->queue_declare($physicalQueue, true, false, false, false);

            return (int) $messageCount;
        } catch (Throwable $exception) {
            if ($exception instanceof AMQPProtocolChannelException) {
                $this->channelManager->closeChannel('topology', $this->brokerConnection);
            }

            throw new ConnectionException(
                "Failed to read RabbitMQ queue [{$logicalQueue}]: {$exception->getMessage()}",
                previous: $exception,
            );
        }
    }

    public function pendingSize($queue = null): int
    {
        return $this->size($queue);
    }

    /**
     * AMQP 0-9-1 does not expose the number of messages in TTL delay queues.
     */
    public function delayedSize($queue = null): int
    {
        $this->getQueue($queue);

        return 0;
    }

    /**
     * AMQP 0-9-1 queue declarations do not expose unacknowledged messages.
     */
    public function reservedSize($queue = null): int
    {
        $this->getQueue($queue);

        return 0;
    }

    /**
     * AMQP 0-9-1 cannot inspect the oldest message without consuming it.
     */
    public function creationTimeOfOldestPendingJob($queue = null): ?int
    {
        $this->getQueue($queue);

        return null;
    }

    public function push($job, $data = '', $queue = null): mixed
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            null,
            fn ($payload, $queue) => $this->pushRaw($payload, $queue, [
                'priority' => $this->getJobPriority($job),
            ]),
        );
    }

    /** @param  array<string, mixed>  $options */
    public function pushRaw($payload, $queue = null, array $options = []): mixed
    {
        $logicalQueue = $this->getQueue($queue);
        $definition = $this->registry->queue($logicalQueue);
        [$exchange, $routingKey] = $this->route($definition, (string) $payload);
        $delay = max(0, (int) ($options['delay'] ?? 0));

        if ($delay > 0) {
            $this->publishDelayed(
                definition: $definition,
                exchange: $exchange,
                routingKey: $routingKey,
                payload: (string) $payload,
                delaySeconds: $delay,
                priority: isset($options['priority']) ? (int) $options['priority'] : null,
                properties: is_array($options['properties'] ?? null) ? $options['properties'] : [],
            );
        } else {
            $this->publishMessage(
                definition: $definition,
                exchange: $exchange,
                routingKey: $routingKey,
                payload: (string) $payload,
                priority: isset($options['priority']) ? (int) $options['priority'] : null,
                properties: is_array($options['properties'] ?? null) ? $options['properties'] : [],
            );
        }

        return $this->getPayloadId((string) $payload);
    }

    public function later($delay, $job, $data = '', $queue = null): mixed
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            $delay,
            fn ($payload, $queue, $delay) => $this->laterRaw($delay, $payload, $queue, [
                'priority' => $this->getJobPriority($job),
            ]),
        );
    }

    /**
     * @param  DateInterval|DateTimeInterface|int  $delay
     * @param  array<string, mixed>  $options
     */
    protected function laterRaw($delay, string $payload, ?string $queue = null, array $options = []): mixed
    {
        return $this->pushRaw($payload, $queue, array_replace($options, [
            'delay' => max(0, $this->secondsUntil($delay)),
        ]));
    }

    public function pop($queue = null): ?Job
    {
        $logicalQueue = $this->getQueue($queue);
        $physicalQueue = $this->physicalQueue($logicalQueue);

        try {
            $channel = $this->channelManager->consumeChannel($this->brokerConnection);
            $message = $channel->basic_get($physicalQueue, false);

            if (! $message instanceof AMQPMessage) {
                return null;
            }

            return new RabbitMQJob(
                container: $this->container,
                rabbitmq: $this,
                channel: $channel,
                message: $message,
                connectionName: $this->connectionName,
                queueName: $logicalQueue,
            );
        } catch (Throwable $exception) {
            throw new ConnectionException(
                "Failed to pop RabbitMQ queue [{$logicalQueue}]: {$exception->getMessage()}",
                previous: $exception,
            );
        }
    }

    /**
     * Publish jobs in order. A failure can occur after earlier jobs are confirmed.
     *
     * @param  array<object|string>  $jobs
     * @return array<array{status: string, job: string, id: string}>
     */
    public function pushBatch(array $jobs, ?string $queue = null): array
    {
        $results = [];

        foreach ($jobs as $job) {
            $id = (string) $this->push($job, '', $queue);
            $results[] = [
                'status' => 'confirmed',
                'job' => is_object($job) ? $job::class : $job,
                'id' => $id,
            ];
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    protected function publishDelayed(
        QueueDefinition $definition,
        string $exchange,
        string $routingKey,
        string $payload,
        int $delaySeconds,
        ?int $priority,
        array $properties,
    ): void {
        if ($delaySeconds > $this->maximumDelay) {
            throw new InvalidArgumentException(
                "RabbitMQ delay [{$delaySeconds}] exceeds the configured maximum [{$this->maximumDelay}] seconds."
            );
        }

        $delayMilliseconds = max(1, $delaySeconds * 1000);
        $delayQueue = $this->delayQueueName($definition, $exchange, $routingKey, $delayMilliseconds);
        $cleanupGrace = max(60000, (int) ($this->config['retry']['delay_queue_cleanup_grace'] ?? 86400000));
        $arguments = new AMQPTable([
            'x-queue-type' => 'classic',
            'x-message-ttl' => $delayMilliseconds,
            'x-expires' => $delayMilliseconds + $cleanupGrace,
            'x-dead-letter-exchange' => $exchange,
            'x-dead-letter-routing-key' => $routingKey,
        ]);

        try {
            $this->channelManager
                ->topologyChannel($this->brokerConnection)
                ->queue_declare($delayQueue, false, true, false, false, false, $arguments);
        } catch (Throwable $exception) {
            $this->channelManager->closeChannel('topology', $this->brokerConnection);

            $this->failPublish(
                definition: $definition,
                exchange: $exchange,
                routingKey: $routingKey,
                messageId: (string) ($properties['message_id'] ?? $this->getPayloadId($payload)),
                exception: new PublishException(
                    "RabbitMQ delayed publish setup failed: {$exception->getMessage()}",
                    exchange: $exchange,
                    routingKey: $routingKey,
                    previous: $exception,
                ),
            );
        }

        $this->publishMessage(
            definition: $definition,
            exchange: '',
            routingKey: $delayQueue,
            payload: $payload,
            priority: $priority,
            properties: $properties,
        );
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    protected function publishMessage(
        QueueDefinition $definition,
        string $exchange,
        string $routingKey,
        string $payload,
        ?int $priority = null,
        array $properties = [],
    ): void {
        $message = $this->buildMessage($payload, $priority, $properties);
        $messageId = (string) $message->get('message_id');
        $startedAt = microtime(true);
        $returned = null;
        $nacked = false;

        try {
            $channel = $this->channelManager->publishChannel($this->brokerConnection);

            $channel->set_return_listener(
                function (int $code, string $text, string $returnedExchange, string $returnedRoutingKey) use (&$returned): void {
                    $returned = compact('code', 'text', 'returnedExchange', 'returnedRoutingKey');
                },
            );
            $channel->set_nack_handler(function () use (&$nacked): void {
                $nacked = true;
            });

            $channel->basic_publish($message, $exchange, $routingKey, $this->mandatory);
            $channel->wait_for_pending_acks_returns($this->confirmTimeout);

            if (is_array($returned)) {
                throw new PublishException(
                    "RabbitMQ returned an unroutable message: {$returned['code']} {$returned['text']}",
                    exchange: $exchange,
                    routingKey: $routingKey,
                );
            }

            if ($nacked) {
                throw new PublishException(
                    'RabbitMQ negatively confirmed the published message.',
                    exchange: $exchange,
                    routingKey: $routingKey,
                );
            }

            $this->dispatchEvent(new MessagePublished(
                queue: $definition->logicalName,
                physicalQueue: $definition->physicalName,
                exchange: $exchange,
                routingKey: $routingKey,
                messageId: $messageId,
                durationMilliseconds: (microtime(true) - $startedAt) * 1000,
            ));
        } catch (AMQPTimeoutException $exception) {
            $this->channelManager->closeChannel('publish', $this->brokerConnection);

            $this->failPublish(
                definition: $definition,
                exchange: $exchange,
                routingKey: $routingKey,
                messageId: $messageId,
                exception: new PublishException(
                    'RabbitMQ publisher confirmation timed out. The publish result is uncertain.',
                    exchange: $exchange,
                    routingKey: $routingKey,
                    previous: $exception,
                ),
            );
        } catch (PublishException $exception) {
            $this->failPublish($definition, $exchange, $routingKey, $messageId, $exception);
        } catch (AMQPIOException|AMQPConnectionClosedException|AMQPChannelClosedException|AMQPProtocolChannelException|AMQPRuntimeException|ConnectionException $exception) {
            $this->channelManager->closeChannel('publish', $this->brokerConnection);

            try {
                $this->channelManager->recoverConnection($this->brokerConnection);
            } catch (Throwable $recoveryException) {
                ExceptionReporter::report($recoveryException);
            }

            $this->failPublish(
                definition: $definition,
                exchange: $exchange,
                routingKey: $routingKey,
                messageId: $messageId,
                exception: new PublishException(
                    "RabbitMQ publish failed: {$exception->getMessage()}",
                    exchange: $exchange,
                    routingKey: $routingKey,
                    previous: $exception,
                ),
            );
        }
    }

    protected function failPublish(
        QueueDefinition $definition,
        string $exchange,
        string $routingKey,
        string $messageId,
        PublishException $exception,
    ): never {
        $this->dispatchEvent(new MessagePublishFailed(
            queue: $definition->logicalName,
            physicalQueue: $definition->physicalName,
            exchange: $exchange,
            routingKey: $routingKey,
            messageId: $messageId,
            exception: $exception,
        ));
        ExceptionReporter::report($exception);

        throw $exception;
    }

    private function dispatchEvent(object $event): void
    {
        try {
            $this->events->dispatch($event);
        } catch (Throwable $exception) {
            ExceptionReporter::report($exception);
        }
    }

    /** @param  array<string, mixed>  $properties */
    protected function buildMessage(string $payload, ?int $priority = null, array $properties = []): AMQPMessage
    {
        $properties = array_replace([
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'content_type' => 'application/json',
            'message_id' => $this->getPayloadId($payload),
            'timestamp' => time(),
        ], $properties);

        if ($priority !== null) {
            $properties['priority'] = $priority;
        }

        return new AMQPMessage($payload, $properties);
    }

    /** @return array{0: string, 1: string} */
    protected function route(QueueDefinition $definition, string $payload): array
    {
        $data = json_decode($payload, true);
        $dynamicRoutingKey = is_array($data) && isset($data['routingKey'])
            ? $this->registry->validateRoutingKey($definition->logicalName, (string) $data['routingKey'])
            : null;

        $routingKey = $dynamicRoutingKey ?? $definition->publishRoutingKey();

        if ($definition->publishExchange() !== '') {
            $routingKey = $this->registry->validateRoutingKey($definition->logicalName, $routingKey);
        }

        return [$definition->publishExchange(), $routingKey];
    }

    protected function delayQueueName(
        QueueDefinition $definition,
        string $exchange,
        string $routingKey,
        int $delayMilliseconds,
    ): string {
        $suffix = substr(hash('sha256', $exchange."\0".$routingKey), 0, 12);
        $name = $this->registry->physicalName(
            'delay:'.$definition->logicalName.':'.$delayMilliseconds.':'.$suffix
        );

        if (strlen($name) <= 255) {
            return $name;
        }

        $shortName = $this->registry->physicalName(
            'delay:'.substr(hash('sha256', $definition->logicalName), 0, 20).':'.$delayMilliseconds.':'.$suffix
        );

        if (strlen($shortName) > 255) {
            throw new InvalidArgumentException('RabbitMQ physical_prefix is too long for delayed queue names.');
        }

        return $shortName;
    }

    protected function getJobPriority(mixed $job): ?int
    {
        return $job instanceof HasPriority ? $job->getPriority() : null;
    }

    /**
     * @param  object|string  $job
     * @param  mixed  $data
     * @return array<string, mixed>
     */
    protected function createPayloadArray($job, $queue, $data = ''): array
    {
        $payload = parent::createPayloadArray($job, $queue, $data);

        if ($job instanceof HasRoutingKey) {
            $payload['routingKey'] = $this->registry->validateRoutingKey(
                $this->getQueue($queue),
                $job->getRoutingKey(),
            );
        }

        return $payload;
    }

    protected function getPayloadId(string $payload): string
    {
        $data = json_decode($payload, true);

        return is_array($data) && isset($data['uuid'])
            ? (string) $data['uuid']
            : Str::uuid()->toString();
    }

    public function getQueue(?string $queue): string
    {
        $queue ??= $this->default;
        $this->registry->queue($queue);

        return $queue;
    }

    public function physicalQueue(string $queue): string
    {
        return $this->registry->physicalQueue($queue);
    }

    public function getBrokerConnectionName(): string
    {
        return $this->brokerConnection;
    }

    public function getChannelManager(): ChannelManager
    {
        return $this->channelManager;
    }

    public function ack(AMQPMessage $message, AMQPChannel $channel): void
    {
        try {
            $channel->basic_ack($message->getDeliveryTag());
        } catch (AMQPIOException|AMQPChannelClosedException|AMQPConnectionClosedException|AMQPProtocolChannelException|AMQPRuntimeException $exception) {
            throw new ConnectionException(
                "Failed to acknowledge RabbitMQ message: {$exception->getMessage()}",
                previous: $exception,
            );
        }
    }

    public function reject(AMQPMessage $message, AMQPChannel $channel, bool $requeue = false): void
    {
        try {
            $channel->basic_reject($message->getDeliveryTag(), $requeue);
        } catch (AMQPIOException|AMQPChannelClosedException|AMQPConnectionClosedException|AMQPProtocolChannelException|AMQPRuntimeException $exception) {
            throw new ConnectionException(
                "Failed to reject RabbitMQ message: {$exception->getMessage()}",
                previous: $exception,
            );
        }
    }
}
