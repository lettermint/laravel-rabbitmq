<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Actions\Dlq;

use Lettermint\RabbitMQ\Connection\ChannelManager;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Find a specific message by ID in a DLQ.
 */
final class FindDlqMessage
{
    private const MAX_SEARCH_MESSAGES = 10000;

    public function __construct(
        private ChannelManager $channelManager,
    ) {}

    /**
     * Find a message by ID in the DLQ.
     *
     * Returns both the target message and other messages encountered during search.
     * The caller is responsible for requeueing the other messages.
     *
     * @return array{target: AMQPMessage|null, others: array<AMQPMessage>}
     */
    public function __invoke(
        string $dlqName,
        string $targetId,
        ?AMQPChannel $channel = null,
    ): array {
        $channel ??= $this->channelManager->channel('dlq-search');

        $checked = 0;
        $otherMessages = [];
        $targetMessage = null;

        while ($checked < self::MAX_SEARCH_MESSAGES) {
            $message = $channel->basic_get($dlqName, false);

            if ($message === null) {
                break;
            }

            $payload = json_decode($message->getBody(), true);
            $messageId = $payload['uuid'] ?? $payload['id'] ?? null;

            if ($messageId === $targetId) {
                $targetMessage = $message;
                break;
            }

            $otherMessages[] = $message;
            $checked++;
        }

        return [
            'target' => $targetMessage,
            'others' => $otherMessages,
        ];
    }
}
