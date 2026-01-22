<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Actions\Dlq;

use Lettermint\RabbitMQ\Actions\Dlq\Results\DlqMessageData;
use Lettermint\RabbitMQ\Actions\Dlq\Results\DlqQueueConfig;
use Lettermint\RabbitMQ\Actions\Dlq\Results\DlqReplayResult;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Exceptions\DlqOperationException;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Replay messages from a DLQ back to the original queue.
 */
final class ReplayDlqMessages
{
    private const MAX_DRY_RUN_FETCH = 100000;

    public function __construct(
        private ChannelManager $channelManager,
        private ResolveDlqQueue $resolveDlqQueue,
        private FindDlqMessage $findDlqMessage,
        private RabbitMQQueue $rabbitmq,
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
        $config = ($this->resolveDlqQueue)($queueName);

        if ($messageId !== null) {
            return $this->replayById($config, $messageId, $dryRun);
        }

        return $this->replayBulk($config, $limit, $rate, $batchSize, $dryRun, $onProgress);
    }

    /**
     * Get the message count for a DLQ.
     */
    public function getQueueMessageCount(string $queueName): int
    {
        $config = ($this->resolveDlqQueue)($queueName);
        $channel = $this->channelManager->channel('dlq-count');

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
            return 0;
        }
    }

    private function replayById(
        DlqQueueConfig $config,
        string $messageId,
        bool $dryRun,
    ): DlqReplayResult {
        $channel = $this->channelManager->channel('dlq-replay');

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
            );
        }

        $messageData = DlqMessageData::fromAmqpMessage($result['target']);

        if ($dryRun) {
            $channel->basic_reject($result['target']->getDeliveryTag(), true);

            return new DlqReplayResult(
                replayedCount: 1,
                failedCount: 0,
                wasDryRun: true,
                replayedMessages: [$messageData],
            );
        }

        try {
            $channel->tx_select();
            $preparedPayload = $this->preparePayloadForReplay($result['target']);
            $this->rabbitmq->pushRaw($preparedPayload, $config->originalQueueName);
            $channel->basic_ack($result['target']->getDeliveryTag());
            $channel->tx_commit();

            return new DlqReplayResult(
                replayedCount: 1,
                failedCount: 0,
                wasDryRun: false,
                replayedMessages: [$messageData],
            );
        } catch (\Exception $e) {
            try {
                $channel->tx_rollback();
            } catch (\Exception $rollbackException) {
                // Channel may be closed
            }

            try {
                $channel->basic_reject($result['target']->getDeliveryTag(), true);
            } catch (\Exception $rejectException) {
                // Message will be requeued automatically when channel closes
            }

            return new DlqReplayResult(
                replayedCount: 0,
                failedCount: 1,
                wasDryRun: false,
                failures: [['message' => $messageData, 'error' => $e->getMessage()]],
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
        $channel = $this->channelManager->channel('dlq-replay');

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
        while (true) {
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
                $channel->tx_select();
                $preparedPayload = $this->preparePayloadForReplay($message);
                $this->rabbitmq->pushRaw($preparedPayload, $config->originalQueueName);
                $channel->basic_ack($message->getDeliveryTag());
                $channel->tx_commit();

                $replayed++;
                $replayedMessages[] = $messageData;

                if ($onProgress !== null) {
                    $onProgress($messageData, true, null);
                }
            } catch (\Exception $e) {
                try {
                    $channel->tx_rollback();
                } catch (\Exception $rollbackException) {
                    $channel = $this->channelManager->channel('dlq-replay');
                }

                $failed++;
                $failures[] = ['message' => $messageData, 'error' => $e->getMessage()];

                if ($onProgress !== null) {
                    $onProgress($messageData, false, $e->getMessage());
                }

                try {
                    $channel->basic_reject($message->getDeliveryTag(), true);
                } catch (\Exception $rejectException) {
                    // Message will be requeued automatically when channel closes
                }
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
        );
    }

    private function replayBulkDryRun(
        AMQPChannel $channel,
        DlqQueueConfig $config,
        int $limit,
    ): DlqReplayResult {
        $fetchedMessages = [];
        $maxFetch = $limit > 0 ? $limit : self::MAX_DRY_RUN_FETCH;

        while (count($fetchedMessages) < $maxFetch) {
            $message = $channel->basic_get($config->dlqQueueName, false);

            if ($message === null) {
                break;
            }

            $fetchedMessages[] = $message;
        }

        $replayedMessages = array_map(
            fn ($m) => DlqMessageData::fromAmqpMessage($m),
            $fetchedMessages,
        );

        // Requeue all messages
        foreach ($fetchedMessages as $message) {
            $channel->basic_reject($message->getDeliveryTag(), true);
        }

        return new DlqReplayResult(
            replayedCount: count($fetchedMessages),
            failedCount: 0,
            wasDryRun: true,
            replayedMessages: $replayedMessages,
        );
    }

    private function preparePayloadForReplay(AMQPMessage $message): string
    {
        $payload = json_decode($message->getBody(), true) ?? [];
        $currentAttempts = $this->getAttemptsFromMessage($message);

        // Increment attempts for the upcoming retry
        $payload['attempts'] = $currentAttempts + 1;

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    private function getAttemptsFromMessage(AMQPMessage $message): int
    {
        $payload = json_decode($message->getBody(), true) ?? [];

        // Check payload first (for previously replayed messages)
        if (isset($payload['attempts']) && is_int($payload['attempts'])) {
            return $payload['attempts'];
        }

        // Fall back to x-death headers
        $headers = $message->has('application_headers')
            ? $message->get('application_headers')
            : null;

        if ($headers instanceof AMQPTable) {
            $nativeHeaders = $headers->getNativeData();

            if (isset($nativeHeaders['x-death']) && is_array($nativeHeaders['x-death'])) {
                $totalCount = 0;
                foreach ($nativeHeaders['x-death'] as $death) {
                    $deathData = $death instanceof AMQPTable ? $death->getNativeData() : $death;
                    $totalCount += (int) ($deathData['count'] ?? 0);
                }

                return $totalCount + 1;
            }
        }

        return 1;
    }
}
