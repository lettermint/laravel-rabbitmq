<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Connection;

use AMQPChannel;
use AMQPConnection;
use AMQPConnectionException;
use Lettermint\RabbitMQ\Exceptions\ConnectionException;

/**
 * Manages RabbitMQ channels for the ext-amqp extension.
 *
 * Channels are lightweight connections that share a single TCP connection.
 * This class manages channel lifecycle and provides channels for different
 * purposes (publishing, consuming, etc.).
 */
class ChannelManager
{
    /**
     * Active channels indexed by purpose.
     *
     * @var array<string, AMQPChannel>
     */
    protected array $channels = [];

    public function __construct(
        protected ConnectionManager $connectionManager,
    ) {}

    /**
     * Get or create a channel for a specific purpose.
     *
     * @param  string  $purpose  Channel purpose identifier (e.g., 'publish', 'consume', 'topology')
     * @param  string|null  $connection  Connection name (null for default)
     *
     * @throws ConnectionException
     */
    public function channel(string $purpose = 'default', ?string $connection = null): AMQPChannel
    {
        $key = $this->getChannelKey($purpose, $connection);

        if (! isset($this->channels[$key]) || ! $this->channels[$key]->isConnected()) {
            $this->channels[$key] = $this->createChannel($connection);
        }

        return $this->channels[$key];
    }

    /**
     * Create a new channel on the given connection.
     *
     * @throws ConnectionException
     */
    protected function createChannel(?string $connection = null): AMQPChannel
    {
        try {
            $amqpConnection = $this->connectionManager->connection($connection);

            return new AMQPChannel($amqpConnection);
        } catch (AMQPConnectionException $e) {
            throw new ConnectionException(
                'Failed to create RabbitMQ channel: '.$e->getMessage(),
                previous: $e
            );
        }
    }

    /**
     * Get a channel specifically for publishing.
     *
     * This channel is configured with publisher confirms if enabled.
     *
     * @throws ConnectionException
     */
    public function publishChannel(?string $connection = null): AMQPChannel
    {
        return $this->channel('publish', $connection);
    }

    /**
     * Get a channel specifically for consuming.
     *
     * @throws ConnectionException
     */
    public function consumeChannel(?string $connection = null): AMQPChannel
    {
        return $this->channel('consume', $connection);
    }

    /**
     * Get a channel for topology operations (declaring exchanges, queues, bindings).
     *
     * @throws ConnectionException
     */
    public function topologyChannel(?string $connection = null): AMQPChannel
    {
        return $this->channel('topology', $connection);
    }

    /**
     * Close a specific channel.
     */
    public function closeChannel(string $purpose = 'default', ?string $connection = null): void
    {
        $key = $this->getChannelKey($purpose, $connection);

        if (isset($this->channels[$key])) {
            // ext-amqp doesn't have explicit channel close, just unset
            unset($this->channels[$key]);
        }
    }

    /**
     * Close all channels.
     */
    public function closeAll(): void
    {
        $this->channels = [];
    }

    /**
     * Generate a unique key for channel storage.
     */
    protected function getChannelKey(string $purpose, ?string $connection): string
    {
        $connection ??= $this->connectionManager->getDefaultConnection();

        return "{$connection}:{$purpose}";
    }

    /**
     * Get the underlying AMQPConnection for a channel.
     *
     * @throws ConnectionException
     */
    public function getConnection(?string $name = null): AMQPConnection
    {
        return $this->connectionManager->connection($name);
    }
}
