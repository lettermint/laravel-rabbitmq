<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Actions\Dlq;

use Lettermint\RabbitMQ\Actions\Dlq\Results\DlqMessageData;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Monitoring\ManagementClient;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

/**
 * Find a specific message by ID in a DLQ.
 */
final class FindDlqMessage
{
    public function __construct(
        private ChannelManager $channelManager,
        private RabbitMQQueue $rabbitmq,
    ) {}

    /**
     * Find a message by ID in the DLQ.
     *
     * Returns both the target message and other messages encountered during search.
     * The caller must settle the returned messages and close the channel.
     * When no channel is supplied, this action uses the dlq-search channel.
     * A failed scan closes a channel that this action created.
     *
     * @return array{target: AMQPMessage|null, others: array<AMQPMessage>, incomplete: bool}
     */
    public function __invoke(
        string $dlqName,
        string $targetId,
        ?AMQPChannel $channel = null,
    ): array {
        $ownsChannel = $channel === null;
        if ($ownsChannel) {
            app(ManagementClient::class)->assertSafeDeadLetterQueue($dlqName, $this->rabbitmq->getBrokerConnectionName());
        }
        $channel ??= $this->channelManager->channel(
            'dlq-search',
            $this->rabbitmq->getBrokerConnectionName(),
        );

        $checked = 0;
        $bytes = 0;
        $deadline = microtime(true) + max(1, (int) config('rabbitmq.dlq.max_runtime_seconds', 30));
        $maxMessages = max(1, (int) config('rabbitmq.dlq.max_scan_messages', 1000));
        $maxBytes = max(1, (int) config('rabbitmq.dlq.max_scan_bytes', 16777216));
        $exhausted = false;
        $otherMessages = [];
        $targetMessage = null;

        try {
            while ($checked < $maxMessages && $bytes < $maxBytes && microtime(true) < $deadline) {
                $message = $channel->basic_get($dlqName, false);

                if ($message === null) {
                    $exhausted = true;
                    break;
                }

                $bytes += strlen($message->getBody());
                $decoded = json_decode($message->getBody(), true);
                $payload = is_array($decoded) ? $decoded : [];
                $messageId = $payload['uuid'] ?? $payload['id'] ?? null;
                $propertyId = $message->has('message_id') ? $message->get('message_id') : null;

                if ($messageId === $targetId || $propertyId === $targetId || DlqMessageData::fromAmqpMessage($message)->id === $targetId) {
                    $targetMessage = $message;
                    break;
                }

                $otherMessages[] = $message;
                $checked++;
            }
        } catch (Throwable $exception) {
            if ($ownsChannel) {
                $this->channelManager->closeChannel('dlq-search', $this->rabbitmq->getBrokerConnectionName());
            }

            throw $exception;
        }

        return [
            'target' => $targetMessage,
            'others' => $otherMessages,
            'incomplete' => $targetMessage === null && ! $exhausted,
        ];
    }
}
