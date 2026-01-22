<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Actions\Dlq;

use Illuminate\Support\Carbon;
use Lettermint\RabbitMQ\Actions\Dlq\Results\DlqMessageData;
use Lettermint\RabbitMQ\Actions\Dlq\Results\DlqPurgeResult;
use Lettermint\RabbitMQ\Actions\Dlq\Results\DlqQueueConfig;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Exceptions\DlqOperationException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Purge messages from a DLQ (permanently delete).
 */
final class PurgeDlqMessages
{
    private const MAX_MESSAGES = 100000;

    public function __construct(
        private ChannelManager $channelManager,
        private ResolveDlqQueue $resolveDlqQueue,
        private FindDlqMessage $findDlqMessage,
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
        $config = ($this->resolveDlqQueue)($queueName);
        $channel = $this->channelManager->channel('dlq-purge');

        if ($messageId !== null) {
            return $this->purgeById($channel, $config, $messageId, $dryRun);
        }

        return $this->purgeBulk($channel, $config, $olderThan, $dryRun);
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
            );
        }

        $messageData = DlqMessageData::fromAmqpMessage($result['target']);

        if ($dryRun) {
            $channel->basic_reject($result['target']->getDeliveryTag(), true);
        } else {
            $channel->basic_ack($result['target']->getDeliveryTag());
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
        while (count($fetchedMessages) < self::MAX_MESSAGES) {
            $message = $channel->basic_get($config->dlqQueueName, false);

            if ($message === null) {
                break;
            }

            $fetchedMessages[] = $message;
        }

        // Categorize messages into purge vs skip
        $toPurge = [];
        $toSkip = [];

        foreach ($fetchedMessages as $message) {
            if ($olderThan !== null) {
                $messageTime = $this->getMessageTime($message);

                if ($messageTime !== null && $messageTime->isAfter($olderThan)) {
                    $toSkip[] = $message;

                    continue;
                }
            }

            $toPurge[] = $message;
        }

        // Process messages
        foreach ($toSkip as $message) {
            $channel->basic_reject($message->getDeliveryTag(), true);
        }

        $purgedMessages = [];
        foreach ($toPurge as $message) {
            $purgedMessages[] = DlqMessageData::fromAmqpMessage($message);

            if ($dryRun) {
                $channel->basic_reject($message->getDeliveryTag(), true);
            } else {
                $channel->basic_ack($message->getDeliveryTag());
            }
        }

        return new DlqPurgeResult(
            purgedCount: count($toPurge),
            skippedCount: count($toSkip),
            wasDryRun: $dryRun,
            purgedMessages: $purgedMessages,
        );
    }

    private function getMessageTime(AMQPMessage $message): ?Carbon
    {
        $headers = $message->has('application_headers')
            ? $message->get('application_headers')->getNativeData()
            : [];

        $xDeath = $headers['x-death'][0] ?? null;

        if (isset($xDeath['time'])) {
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
