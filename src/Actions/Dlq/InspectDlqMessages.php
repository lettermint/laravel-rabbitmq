<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Actions\Dlq;

use Lettermint\RabbitMQ\Actions\Dlq\Results\DlqInspectResult;
use Lettermint\RabbitMQ\Actions\Dlq\Results\DlqMessageData;
use Lettermint\RabbitMQ\Actions\Dlq\Results\DlqQueueConfig;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Exceptions\DlqOperationException;
use PhpAmqpLib\Channel\AMQPChannel;

/**
 * Inspect messages in a DLQ without removing them.
 */
final class InspectDlqMessages
{
    public function __construct(
        private ChannelManager $channelManager,
        private ResolveDlqQueue $resolveDlqQueue,
        private FindDlqMessage $findDlqMessage,
    ) {}

    /**
     * Inspect messages in a DLQ.
     *
     * @throws DlqOperationException When queue not found
     */
    public function __invoke(
        string $queueName,
        ?string $messageId = null,
        int $limit = 10,
    ): DlqInspectResult {
        $config = ($this->resolveDlqQueue)($queueName);
        $channel = $this->channelManager->channel('dlq-inspect');

        if ($messageId !== null) {
            return $this->inspectById($channel, $config, $messageId);
        }

        return $this->inspectMany($channel, $config, $limit);
    }

    private function inspectById(
        AMQPChannel $channel,
        DlqQueueConfig $config,
        string $messageId,
    ): DlqInspectResult {
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
            return new DlqInspectResult(
                messages: [],
                totalFound: 0,
                notFoundId: $messageId,
            );
        }

        // Requeue target message too (inspect doesn't remove)
        $channel->basic_reject($result['target']->getDeliveryTag(), true);

        return new DlqInspectResult(
            messages: [DlqMessageData::fromAmqpMessage($result['target'])],
            totalFound: 1,
        );
    }

    private function inspectMany(
        AMQPChannel $channel,
        DlqQueueConfig $config,
        int $limit,
    ): DlqInspectResult {
        $fetchedMessages = [];

        while (count($fetchedMessages) < $limit) {
            $message = $channel->basic_get($config->dlqQueueName, false);

            if ($message === null) {
                break;
            }

            $fetchedMessages[] = $message;
        }

        $messages = array_map(
            fn ($m) => DlqMessageData::fromAmqpMessage($m),
            $fetchedMessages,
        );

        // Requeue all messages (inspect doesn't remove)
        foreach ($fetchedMessages as $message) {
            $channel->basic_reject($message->getDeliveryTag(), true);
        }

        return new DlqInspectResult(
            messages: $messages,
            totalFound: count($messages),
        );
    }
}
