<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Actions\Dlq;

use Illuminate\Contracts\Events\Dispatcher;
use Lettermint\RabbitMQ\Actions\Dlq\Results\DlqMessageData;
use Lettermint\RabbitMQ\Actions\Dlq\Results\DlqQueueConfig;
use Lettermint\RabbitMQ\Actions\Dlq\Results\DlqReplayResult;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Events\DlqMessageReplayed;
use Lettermint\RabbitMQ\Exceptions\DlqOperationException;
use Lettermint\RabbitMQ\Exceptions\PublishException;
use Lettermint\RabbitMQ\Monitoring\ManagementClient;
use Lettermint\RabbitMQ\Queue\RabbitMQJob;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;
use Lettermint\RabbitMQ\Support\DlqOperationAudit;
use Lettermint\RabbitMQ\Support\ExceptionReporter;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * Replay messages from a DLQ back to the original queue.
 */
final class ReplayDlqMessages
{
    private const MAX_DRY_RUN_FETCH = 1000;

    public function __construct(
        private ChannelManager $channelManager,
        private ResolveDlqQueue $resolveDlqQueue,
        private FindDlqMessage $findDlqMessage,
        private RabbitMQQueue $rabbitmq,
        private Dispatcher $events,
    ) {}

    /**
     * Replay messages from a DLQ.
     *
     * @param  callable(DlqMessageData, bool $success, ?string $error): void|null  $onProgress  Progress callback
     *
     * @throws DlqOperationException When queue not found
     */
    public function __invoke(
        string $queueName,
        ?string $messageId = null,
        int $limit = 0,
        int $rate = 0,
        int $batchSize = 0,
        bool $dryRun = false,
        ?callable $onProgress = null,
    ): DlqReplayResult {
        return DlqOperationAudit::run('replay', $queueName, $messageId, $dryRun, fn () => $this->execute($queueName, $messageId, $limit, $rate, $batchSize, $dryRun, $onProgress));
    }

    /** @param callable(DlqMessageData, bool, ?string): void|null $onProgress */
    private function execute(string $queueName, ?string $messageId, int $limit, int $rate, int $batchSize, bool $dryRun, ?callable $onProgress): DlqReplayResult
    {
        $config = ($this->resolveDlqQueue)($queueName);
        app(ManagementClient::class)->assertSafeDeadLetterQueue($config->dlqQueueName, $this->rabbitmq->getBrokerConnectionName());

        try {

            if ($messageId !== null) {
                return $this->replayById($config, $messageId, $dryRun);
            }

            return $this->replayBulk($config, $limit, $rate, $batchSize, $dryRun, $onProgress);
        } finally {
            $this->channelManager->closeChannel('dlq-replay', $this->rabbitmq->getBrokerConnectionName());
        }
    }

    /**
     * Get the message count for a DLQ.
     */
    public function getQueueMessageCount(string $queueName): int
    {
        $config = ($this->resolveDlqQueue)($queueName);
        $channel = $this->channelManager->channel(
            'dlq-count',
            $this->rabbitmq->getBrokerConnectionName(),
        );

        try {
            [$name, $messageCount, $consumerCount] = $channel->queue_declare(
                $config->dlqQueueName,
                true,   // passive
                false,  // durable
                false,  // exclusive
                false,  // auto_delete
            );

            return $messageCount;
        } catch (\Exception $e) {
            throw DlqOperationException::connectionFailed(
                "Failed to read DLQ [{$config->dlqQueueName}]: {$e->getMessage()}",
                $e,
            );
        } finally {
            $this->channelManager->closeChannel('dlq-count', $this->rabbitmq->getBrokerConnectionName());
        }
    }

    private function replayById(
        DlqQueueConfig $config,
        string $messageId,
        bool $dryRun,
    ): DlqReplayResult {
        $channel = $this->channelManager->channel(
            'dlq-replay',
            $this->rabbitmq->getBrokerConnectionName(),
        );

        $result = ($this->findDlqMessage)(
            dlqName: $config->dlqQueueName,
            targetId: $messageId,
            channel: $channel,
        );

        // Requeue all non-matching messages
        foreach ($result['others'] as $msg) {
            $channel->basic_reject($msg->getDeliveryTag(), true);
        }

        if ($result['target'] === null) {
            return new DlqReplayResult(
                replayedCount: 0,
                failedCount: 0,
                wasDryRun: $dryRun,
                notFoundId: $messageId,
                incomplete: $result['incomplete'],
            );
        }

        $messageData = DlqMessageData::fromAmqpMessage($result['target']);

        if ($dryRun) {
            $channel->basic_reject($result['target']->getDeliveryTag(), true);
            $error = $this->replayError($messageData);

            return new DlqReplayResult(
                replayedCount: $error === null ? 1 : 0,
                failedCount: $error === null ? 0 : 1,
                wasDryRun: true,
                replayedMessages: $error === null ? [$messageData] : [],
                failures: $error === null ? [] : [['message' => $messageData, 'error' => $error]],
            );
        }

        try {
            $this->replayMessage($channel, $config, $result['target'], $messageData);

            return new DlqReplayResult(
                replayedCount: 1,
                failedCount: 0,
                wasDryRun: false,
                replayedMessages: [$messageData],
            );
        } catch (Throwable $e) {
            try {
                $channel->basic_reject($result['target']->getDeliveryTag(), true);
            } catch (Throwable) {
                // Message will be requeued automatically when channel closes
            }

            return new DlqReplayResult(
                replayedCount: 0,
                failedCount: 1,
                wasDryRun: false,
                failures: [['message' => $messageData, 'error' => $e->getMessage()]],
                uncertain: $e instanceof PublishException && $e->uncertain,
            );
        }
    }

    /**
     * @param  callable(DlqMessageData, bool $success, ?string $error): void|null  $onProgress
     */
    private function replayBulk(
        DlqQueueConfig $config,
        int $limit,
        int $rate,
        int $batchSize,
        bool $dryRun,
        ?callable $onProgress,
    ): DlqReplayResult {
        $channel = $this->channelManager->channel(
            'dlq-replay',
            $this->rabbitmq->getBrokerConnectionName(),
        );

        // For dry-run, use "fetch all, then reject all" pattern
        if ($dryRun) {
            return $this->replayBulkDryRun($channel, $config, $limit);
        }

        $replayed = 0;
        $failed = 0;
        $batchCount = 0;
        $replayedMessages = [];
        $failures = [];

        // Calculate delay between messages for rate limiting
        $delayMicroseconds = $rate > 0 ? (int) (1_000_000 / $rate) : 0;

        $processed = 0;
        [, $ready] = $channel->queue_declare($config->dlqQueueName, true);
        $maximum = min((int) $ready, max(1, (int) config('rabbitmq.dlq.max_scan_messages', 1000)));
        $maximum = $limit > 0 ? min($maximum, $limit) : $maximum;
        $deadline = microtime(true) + max(1, (int) config('rabbitmq.dlq.max_runtime_seconds', 30));
        $uncertain = false;
        $resultBytes = 0;

        while ($processed < $maximum && microtime(true) < $deadline) {
            if ($limit > 0 && $processed >= $limit) {
                break;
            }

            $message = $channel->basic_get($config->dlqQueueName, false);

            if ($message === null) {
                break;
            }

            $processed++;
            $messageData = DlqMessageData::fromAmqpMessage($message);

            try {
                $this->replayMessage($channel, $config, $message, $messageData);

                $replayed++;
                if (count($replayedMessages) < (int) config('rabbitmq.dlq.max_result_messages', 100)
                    && $resultBytes < (int) config('rabbitmq.dlq.max_scan_bytes', 16777216)) {
                    $replayedMessages[] = $messageData;
                    $resultBytes += strlen($messageData->rawBody);
                }

                if ($onProgress !== null) {
                    try {
                        $onProgress($messageData, true, null);
                    } catch (Throwable $exception) {
                        ExceptionReporter::report($exception);
                    }
                }
            } catch (Throwable $e) {
                $failed++;
                $uncertain = $e instanceof PublishException && $e->uncertain;
                $failures[] = ['message' => $messageData, 'error' => $e->getMessage()];

                if ($onProgress !== null) {
                    try {
                        $onProgress($messageData, false, $e->getMessage());
                    } catch (Throwable $exception) {
                        ExceptionReporter::report($exception);
                    }
                }

                try {
                    $channel->basic_reject($message->getDeliveryTag(), true);
                } catch (Throwable) {
                    // Message will be requeued automatically when channel closes
                }

                break;
            }

            // Apply rate limiting
            if ($delayMicroseconds > 0) {
                usleep($delayMicroseconds);
            }

            // Apply batch pausing
            if ($batchSize > 0) {
                $batchCount++;
                if ($batchCount >= $batchSize) {
                    $batchCount = 0;
                    sleep(1);
                }
            }
        }

        return new DlqReplayResult(
            replayedCount: $replayed,
            failedCount: $failed,
            wasDryRun: false,
            replayedMessages: $replayedMessages,
            failures: $failures,
            incomplete: $processed < (int) $ready && ($limit === 0 || $processed < $limit),
            uncertain: $uncertain,
        );
    }

    private function replayBulkDryRun(
        AMQPChannel $channel,
        DlqQueueConfig $config,
        int $limit,
    ): DlqReplayResult {
        $fetchedMessages = [];
        $maxFetch = min($limit > 0 ? $limit : self::MAX_DRY_RUN_FETCH, (int) config('rabbitmq.dlq.max_result_messages', 100));
        $bytes = 0;
        $deadline = microtime(true) + (int) config('rabbitmq.dlq.max_runtime_seconds', 30);

        while (count($fetchedMessages) < $maxFetch && $bytes < (int) config('rabbitmq.dlq.max_scan_bytes', 16777216) && microtime(true) < $deadline) {
            $message = $channel->basic_get($config->dlqQueueName, false);

            if ($message === null) {
                break;
            }

            $fetchedMessages[] = $message;
            $bytes += strlen($message->getBody());
        }

        $replayedMessages = [];
        $failures = [];
        foreach ($fetchedMessages as $message) {
            $data = DlqMessageData::fromAmqpMessage($message);
            $error = $this->replayError($data);
            if ($error === null) {
                $replayedMessages[] = $data;
            } else {
                $failures[] = ['message' => $data, 'error' => $error];
            }
        }

        // Requeue all messages
        foreach ($fetchedMessages as $message) {
            $channel->basic_reject($message->getDeliveryTag(), true);
        }

        return new DlqReplayResult(
            replayedCount: count($replayedMessages),
            failedCount: count($failures),
            wasDryRun: true,
            replayedMessages: $replayedMessages,
            failures: $failures,
            incomplete: count($fetchedMessages) >= $maxFetch || $bytes >= (int) config('rabbitmq.dlq.max_scan_bytes', 16777216) || microtime(true) >= $deadline,
        );
    }

    private function replayMessage(
        AMQPChannel $channel,
        DlqQueueConfig $config,
        AMQPMessage $message,
        DlqMessageData $messageData,
    ): void {
        if (($error = $this->replayError($messageData)) !== null) {
            throw new DlqOperationException($error);
        }

        $properties = $message->get_properties();
        $headers = $properties['application_headers'] ?? null;
        $headers = $headers instanceof AMQPTable ? $headers->getNativeData() : [];

        foreach (array_keys($headers) as $name) {
            if ($name === 'x-delivery-count' || str_starts_with($name, 'x-death') || str_starts_with($name, 'x-first-death') || str_starts_with($name, 'x-last-death')) {
                unset($headers[$name]);
            }
        }

        // An operator replay starts a new Laravel attempt sequence. Keeping the
        // exhausted attempt count would make Laravel fail the replay at once.
        $attempt = 1;
        $headers[RabbitMQJob::ATTEMPT_HEADER] = $attempt;
        $properties['application_headers'] = new AMQPTable($headers);

        $this->rabbitmq->pushRaw(
            $message->getBody(),
            $config->originalQueueName,
            ['properties' => $properties, 'queue_only' => true],
        );
        try {
            $channel->basic_ack($message->getDeliveryTag());
        } catch (Throwable $exception) {
            throw new PublishException('The replay was published, but its DLQ acknowledgement is uncertain.', previous: $exception, uncertain: true);
        }

        try {
            $this->events->dispatch(new DlqMessageReplayed(
                queue: $config->originalQueueName,
                jobId: $messageData->id,
                jobName: $messageData->jobClass,
                attempt: $attempt,
            ));
        } catch (Throwable $exception) {
            ExceptionReporter::report($exception);
        }
    }

    private function replayError(DlqMessageData $message): ?string
    {
        $retryUntil = $message->payload['retryUntil'] ?? null;

        return is_numeric($retryUntil) && (int) $retryUntil <= time()
            ? 'The job retry deadline has expired. The message remains in the DLQ.'
            : null;
    }
}
