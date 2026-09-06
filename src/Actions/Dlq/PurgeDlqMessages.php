<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Actions\Dlq;

use Illuminate\Support\Carbon;
use Lettermint\RabbitMQ\Actions\Dlq\Results\DlqMessageData;
use Lettermint\RabbitMQ\Actions\Dlq\Results\DlqPurgeResult;
use Lettermint\RabbitMQ\Actions\Dlq\Results\DlqQueueConfig;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Exceptions\DlqOperationException;
use Lettermint\RabbitMQ\Monitoring\ManagementClient;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;
use Lettermint\RabbitMQ\Support\DlqOperationAudit;
use Lettermint\RabbitMQ\Support\FailedJobDetails;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * Purge messages from a DLQ (permanently delete).
 */
final class PurgeDlqMessages
{
    public function __construct(
        private ChannelManager $channelManager,
        private ResolveDlqQueue $resolveDlqQueue,
        private FindDlqMessage $findDlqMessage,
        private RabbitMQQueue $rabbitmq,
    ) {}

    /**
     * Purge messages from a DLQ.
     *
     * @throws DlqOperationException When queue not found
     */
    public function __invoke(
        string $queueName,
        ?string $messageId = null,
        ?Carbon $olderThan = null,
        bool $dryRun = false,
    ): DlqPurgeResult {
        return DlqOperationAudit::run('purge', $queueName, $messageId, $dryRun, fn () => $this->execute($queueName, $messageId, $olderThan, $dryRun));
    }

    private function execute(string $queueName, ?string $messageId, ?Carbon $olderThan, bool $dryRun): DlqPurgeResult
    {
        $config = ($this->resolveDlqQueue)($queueName);
        app(ManagementClient::class)->assertSafeDeadLetterQueue($config->dlqQueueName, $this->rabbitmq->getBrokerConnectionName());

        try {
            $channel = $this->channelManager->channel(
                'dlq-purge',
                $this->rabbitmq->getBrokerConnectionName(),
            );

            if ($messageId !== null) {
                return $this->purgeById($channel, $config, $messageId, $dryRun);
            }

            return $this->purgeBulk($channel, $config, $olderThan, $dryRun);
        } finally {
            $this->channelManager->closeChannel('dlq-purge', $this->rabbitmq->getBrokerConnectionName());
        }
    }

    private function purgeById(
        AMQPChannel $channel,
        DlqQueueConfig $config,
        string $messageId,
        bool $dryRun,
    ): DlqPurgeResult {
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
            return new DlqPurgeResult(
                purgedCount: 0,
                skippedCount: 0,
                wasDryRun: $dryRun,
                notFoundId: $messageId,
                incomplete: $result['incomplete'],
            );
        }

        $messageData = DlqMessageData::fromAmqpMessage($result['target']);

        if ($dryRun) {
            $channel->basic_reject($result['target']->getDeliveryTag(), true);
        } else {
            try {
                $channel->basic_ack($result['target']->getDeliveryTag());
            } catch (Throwable $exception) {
                return new DlqPurgeResult(0, 0, false, incomplete: true, error: $exception->getMessage(), uncertain: true);
            }
            app(FailedJobDetails::class)->forget($messageData->id);
        }

        return new DlqPurgeResult(
            purgedCount: 1,
            skippedCount: 0,
            wasDryRun: $dryRun,
            purgedMessages: [$messageData],
        );
    }

    private function purgeBulk(
        AMQPChannel $channel,
        DlqQueueConfig $config,
        ?Carbon $olderThan,
        bool $dryRun,
    ): DlqPurgeResult {
        // Fetch all messages first
        $fetchedMessages = [];
        $bytes = 0;
        $maxMessages = max(1, (int) config('rabbitmq.dlq.max_scan_messages', 1000));
        $maxBytes = max(1, (int) config('rabbitmq.dlq.max_scan_bytes', 16777216));
        $deadline = microtime(true) + (int) config('rabbitmq.dlq.max_runtime_seconds', 30);
        while (count($fetchedMessages) < $maxMessages && $bytes < $maxBytes && microtime(true) < $deadline) {
            $message = $channel->basic_get($config->dlqQueueName, false);

            if ($message === null) {
                break;
            }

            $fetchedMessages[] = $message;
            $bytes += strlen($message->getBody());
        }

        $purgedMessages = [];
        $purged = 0;
        $skipped = 0;
        $error = null;
        $uncertain = false;
        foreach ($fetchedMessages as $message) {
            try {
                $messageTime = $olderThan === null ? null : $this->getMessageTime($message);
                if ($olderThan !== null && ($messageTime === null || $messageTime->isAfter($olderThan))) {
                    $channel->basic_reject($message->getDeliveryTag(), true);
                    $skipped++;

                    continue;
                }

                $data = DlqMessageData::fromAmqpMessage($message);
                if ($dryRun) {
                    $channel->basic_reject($message->getDeliveryTag(), true);
                } else {
                    $uncertain = true;
                    $channel->basic_ack($message->getDeliveryTag());
                    $uncertain = false;
                    app(FailedJobDetails::class)->forget($data->id);
                }
                $purged++;
                if (count($purgedMessages) < (int) config('rabbitmq.dlq.max_result_messages', 100)) {
                    $purgedMessages[] = $data;
                }
            } catch (Throwable $exception) {
                $error = $exception->getMessage();
                break;
            }
        }

        return new DlqPurgeResult(
            purgedCount: $purged,
            skippedCount: $skipped,
            wasDryRun: $dryRun,
            purgedMessages: $purgedMessages,
            incomplete: $error !== null || count($fetchedMessages) >= $maxMessages || $bytes >= $maxBytes || microtime(true) >= $deadline,
            error: $error,
            uncertain: $uncertain,
        );
    }

    private function getMessageTime(AMQPMessage $message): ?Carbon
    {
        $headers = $message->has('application_headers')
            ? $message->get('application_headers')->getNativeData()
            : [];

        $xDeath = $headers['x-death'][0] ?? null;
        $xDeath = $xDeath instanceof AMQPTable ? $xDeath->getNativeData() : $xDeath;

        if (is_array($xDeath) && isset($xDeath['time'])) {
            $timestamp = $xDeath['time'];
            if (is_object($timestamp) && method_exists($timestamp, 'getTimestamp')) {
                return Carbon::createFromTimestamp($timestamp->getTimestamp());
            }
            if (is_numeric($timestamp)) {
                return Carbon::createFromTimestamp($timestamp);
            }
        }

        if ($message->has('timestamp')) {
            $timestamp = $message->get('timestamp');
            if (is_numeric($timestamp)) {
                return Carbon::createFromTimestamp($timestamp);
            }
        }

        return null;
    }
}
