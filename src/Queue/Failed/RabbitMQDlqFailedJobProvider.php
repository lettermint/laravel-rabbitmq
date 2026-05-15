<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Queue\Failed;

use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use stdClass;
use Throwable;

/**
 * Exposes RabbitMQ DLQ messages through Laravel's failed-job commands.
 */
final class RabbitMQDlqFailedJobProvider implements FailedJobProviderInterface
{
    public function __construct(
        private ChannelManager $channelManager,
        private AttributeScanner $scanner,
        private string $connectionName = 'rabbitmq',
        private int $scanLimit = 100,
    ) {}

    /**
     * RabbitMQ dead lettering is the storage mechanism, so explicit logging is a no-op.
     */
    public function log($connection, $queue, $payload, $exception): string|int|null
    {
        return null;
    }

    /**
     * @return array<int, string>
     */
    public function ids($queue = null): array
    {
        return array_map(
            fn (stdClass $job): string => (string) $job->id,
            $this->jobs($queue),
        );
    }

    /**
     * @return array<int, stdClass>
     */
    public function all(): array
    {
        return $this->jobs();
    }

    public function find($id): ?object
    {
        $channel = $this->channelManager->channel('failed-jobs');

        foreach ($this->dlqQueues() as $queueName => $dlqQueueName) {
            $result = $this->scanForMessage($channel, $dlqQueueName, (string) $id);

            foreach ($result['others'] as $message) {
                $channel->basic_reject($message->getDeliveryTag(), true);
            }

            if ($result['target'] instanceof AMQPMessage) {
                $channel->basic_reject($result['target']->getDeliveryTag(), true);

                return $this->messageToFailedJob($result['target'], $queueName);
            }
        }

        return null;
    }

    public function forget($id): bool
    {
        $channel = $this->channelManager->channel('failed-jobs');

        foreach ($this->dlqQueues() as $dlqQueueName) {
            $result = $this->scanForMessage($channel, $dlqQueueName, (string) $id);

            foreach ($result['others'] as $message) {
                $channel->basic_reject($message->getDeliveryTag(), true);
            }

            if ($result['target'] instanceof AMQPMessage) {
                $channel->basic_ack($result['target']->getDeliveryTag());

                return true;
            }
        }

        return false;
    }

    public function flush($hours = null): void
    {
        $channel = $this->channelManager->channel('failed-jobs');
        $deleteBefore = $hours === null ? null : Carbon::now()->subHours((int) $hours);

        foreach ($this->dlqQueues() as $dlqQueueName) {
            $checked = 0;

            while ($checked < $this->scanLimit) {
                $message = $channel->basic_get($dlqQueueName, false);

                if (! $message instanceof AMQPMessage) {
                    break;
                }

                $checked++;
                $failedAt = $this->failedAt($message);

                if ($deleteBefore === null || ($failedAt !== null && $failedAt->lessThanOrEqualTo($deleteBefore))) {
                    $channel->basic_ack($message->getDeliveryTag());
                } else {
                    $channel->basic_reject($message->getDeliveryTag(), true);
                }
            }
        }
    }

    public function count($connection = null, $queue = null): int
    {
        if ($connection !== null && $connection !== $this->connectionName) {
            return 0;
        }

        $channel = $this->channelManager->channel('failed-jobs');
        $count = 0;

        foreach ($this->dlqQueues($queue) as $dlqQueueName) {
            try {
                [, $messageCount] = $channel->queue_declare(
                    $dlqQueueName,
                    true,
                    false,
                    false,
                    false,
                );

                $count += (int) $messageCount;
            } catch (Throwable) {
                // Missing DLQs should behave like empty failed-job storage.
            }
        }

        return $count;
    }

    /**
     * @return array<int, stdClass>
     */
    private function jobs(?string $queue = null): array
    {
        $channel = $this->channelManager->channel('failed-jobs');
        $jobs = [];

        foreach ($this->dlqQueues($queue) as $queueName => $dlqQueueName) {
            $messages = [];

            try {
                while (count($messages) < $this->scanLimit) {
                    $message = $channel->basic_get($dlqQueueName, false);

                    if (! $message instanceof AMQPMessage) {
                        break;
                    }

                    $messages[] = $message;
                    $jobs[] = $this->messageToFailedJob($message, $queueName);
                }
            } finally {
                foreach ($messages as $message) {
                    $channel->basic_reject($message->getDeliveryTag(), true);
                }
            }
        }

        return $jobs;
    }

    /**
     * @return array{target: AMQPMessage|null, others: array<int, AMQPMessage>}
     */
    private function scanForMessage(AMQPChannel $channel, string $dlqQueueName, string $id): array
    {
        $others = [];
        $checked = 0;

        while ($checked < $this->scanLimit) {
            $message = $channel->basic_get($dlqQueueName, false);

            if (! $message instanceof AMQPMessage) {
                break;
            }

            if ($this->messageId($message) === $id) {
                return ['target' => $message, 'others' => $others];
            }

            $others[] = $message;
            $checked++;
        }

        return ['target' => null, 'others' => $others];
    }

    private function messageToFailedJob(AMQPMessage $message, string $queueName): stdClass
    {
        $payload = $this->payload($message);
        $job = new stdClass;
        $job->id = $this->messageId($message);
        $job->connection = $this->connectionName;
        $job->queue = $queueName;
        $job->payload = $message->getBody();
        $job->exception = $this->exceptionSummary($payload);
        $job->failed_at = $this->failedAt($message)?->toDateTimeString() ?? Carbon::now()->toDateTimeString();

        return $job;
    }

    private function messageId(AMQPMessage $message): string
    {
        $payload = $this->payload($message);

        if (isset($payload['uuid']) && is_string($payload['uuid'])) {
            return $payload['uuid'];
        }

        if (isset($payload['id']) && is_string($payload['id'])) {
            return $payload['id'];
        }

        if ($message->has('message_id')) {
            return (string) $message->get('message_id');
        }

        return (string) Str::uuid();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AMQPMessage $message): array
    {
        $payload = json_decode($message->getBody(), true);

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function exceptionSummary(array $payload): string
    {
        $exception = $payload['exception'] ?? null;

        if (is_array($exception)) {
            $class = $exception['class'] ?? null;
            $message = $exception['message'] ?? null;

            return trim(implode(': ', array_filter([$class, $message], 'is_string')));
        }

        if (is_string($exception)) {
            return $exception;
        }

        return '';
    }

    private function failedAt(AMQPMessage $message): ?Carbon
    {
        $xDeath = $this->firstXDeath($message);
        $time = $xDeath['time'] ?? null;

        if (is_object($time) && method_exists($time, 'getTimestamp')) {
            return Carbon::createFromTimestamp($time->getTimestamp());
        }

        if (is_numeric($time)) {
            return Carbon::createFromTimestamp((int) $time);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function firstXDeath(AMQPMessage $message): array
    {
        if (! $message->has('application_headers')) {
            return [];
        }

        $headers = $message->get('application_headers');
        $headers = $headers instanceof AMQPTable ? $headers->getNativeData() : $headers;

        if (! is_array($headers) || ! isset($headers['x-death'][0])) {
            return [];
        }

        $xDeath = $headers['x-death'][0];

        if ($xDeath instanceof AMQPTable) {
            return $xDeath->getNativeData();
        }

        return is_array($xDeath) ? $xDeath : [];
    }

    /**
     * @return array<string, string>
     */
    private function dlqQueues(?string $queue = null): array
    {
        $queues = $this->scanner->getTopology()['queues'];
        $dlqQueues = [];

        foreach ($queues as $queueName => $queueData) {
            if ($queue !== null && $queueName !== $queue) {
                continue;
            }

            $attribute = $queueData['attribute'];

            $dlqQueues[$queueName] = $attribute->getDlqQueueName();
        }

        return $dlqQueues;
    }
}
