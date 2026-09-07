<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Connection;

use Lettermint\RabbitMQ\Exceptions\ConnectionException;
use Lettermint\RabbitMQ\Support\ExceptionReporter;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use Throwable;

/**
 * Manages RabbitMQ channels for php-amqplib.
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

    /** @var array<string, array{connection: string, purpose: string}> */
    protected array $channelMetadata = [];

    /** @var array<int, true> */
    protected array $publisherConfirmChannels = [];

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

        if (isset($this->channels[$key]) && ! $this->channels[$key]->is_open()) {
            $this->closeChannel($purpose, $connection);
        }

        if (! isset($this->channels[$key])) {
            $this->channels[$key] = $this->createChannel($connection);
            $this->channelMetadata[$key] = [
                'connection' => $connection ?? $this->connectionManager->getDefaultConnection(),
                'purpose' => $purpose,
            ];
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

            return $amqpConnection->channel();
        } catch (AMQPIOException|AMQPConnectionClosedException|AMQPRuntimeException $e) {
            throw new ConnectionException(
                'Failed to create RabbitMQ channel: '.$e->getMessage(),
                previous: $e
            );
        }
    }

    /**
     * Get a channel specifically for publishing.
     *
     * Publisher confirms are enabled once for each publish channel.
     *
     * @throws ConnectionException
     */
    public function publishChannel(?string $connection = null): AMQPChannel
    {
        $channel = $this->channel('publish', $connection);
        $channelId = spl_object_id($channel);

        if (! isset($this->publisherConfirmChannels[$channelId])) {
            try {
                $channel->confirm_select();
                $this->publisherConfirmChannels[$channelId] = true;
            } catch (Throwable $exception) {
                $this->closeChannel('publish', $connection);

                throw new ConnectionException(
                    'Failed to enable RabbitMQ publisher confirmations: '.$exception->getMessage(),
                    previous: $exception,
                );
            }
        }

        return $channel;
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
            unset($this->publisherConfirmChannels[spl_object_id($this->channels[$key])]);

            try {
                if ($this->channels[$key]->is_open()) {
                    $this->channels[$key]->close();
                }
            } catch (Throwable $e) {
                ExceptionReporter::report($e);
            } finally {
                unset($this->channels[$key], $this->channelMetadata[$key]);
            }
        }
    }

    /**
     * Close all channels.
     */
    public function closeAll(): void
    {
        foreach (array_keys($this->channels) as $key) {
            $metadata = $this->channelMetadata[$key] ?? null;

            if ($metadata === null) {
                unset($this->channels[$key]);

                continue;
            }

            $this->closeChannel($metadata['purpose'], $metadata['connection']);
        }
    }

    public function closeConnectionChannels(?string $connection = null): void
    {
        $connection ??= $this->connectionManager->getDefaultConnection();

        foreach (array_keys($this->channels) as $key) {
            $metadata = $this->channelMetadata[$key] ?? null;

            if ($metadata === null || $metadata['connection'] !== $connection) {
                continue;
            }

            $this->closeChannel($metadata['purpose'], $connection);
        }
    }

    public function recoverConnection(?string $connection = null, ?int $maximumAttempts = null): AbstractConnection
    {
        $this->closeConnectionChannels($connection);

        return $this->connectionManager->recover($connection, $maximumAttempts);
    }

    /**
     * Generate a unique key for channel storage.
     */
    protected function getChannelKey(string $purpose, ?string $connection): string
    {
        $connection ??= $this->connectionManager->getDefaultConnection();

        return hash('sha256', $connection."\0".$purpose);
    }

    /**
     * Get the underlying connection for a channel.
     *
     * @throws ConnectionException
     */
    public function getConnection(?string $name = null): AbstractConnection
    {
        return $this->connectionManager->connection($name);
    }
}
