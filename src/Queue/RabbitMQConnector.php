<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Queue;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;

/**
 * Laravel Queue Connector for RabbitMQ.
 *
 * This connector integrates the RabbitMQ package with Laravel's queue system,
 * allowing standard dispatch() calls to route through RabbitMQ.
 */
class RabbitMQConnector implements ConnectorInterface
{
    public function __construct(
        protected ChannelManager $channelManager,
        protected AttributeScanner $scanner,
    ) {}

    /**
     * Establish a queue connection.
     *
     * @param  array<string, mixed>  $config
     */
    public function connect(array $config): Queue
    {
        return new RabbitMQQueue(
            channelManager: $this->channelManager,
            scanner: $this->scanner,
            config: $config,
        );
    }
}
