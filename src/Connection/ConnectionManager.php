<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Connection;

use AMQPConnection;
use AMQPConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Lettermint\RabbitMQ\Exceptions\ConnectionException;

/**
 * Manages RabbitMQ connections using ext-amqp.
 *
 * This class handles connection creation, pooling, and lifecycle management.
 * It supports multiple hosts for failover and SSL connections.
 */
class ConnectionManager
{
    /**
     * Active connections indexed by name.
     *
     * @var array<string, AMQPConnection>
     */
    protected array $connections = [];

    /**
     * @param  array<string, mixed>  $config  Full RabbitMQ configuration array
     */
    public function __construct(
        protected array $config,
    ) {}

    /**
     * Get or create a connection by name.
     *
     * Automatically handles reconnection if the connection was closed,
     * with appropriate logging for visibility into connection issues.
     *
     * @throws ConnectionException When connection or reconnection fails
     */
    public function connection(?string $name = null): AMQPConnection
    {
        $name ??= $this->getDefaultConnection();

        if (! isset($this->connections[$name])) {
            $this->connections[$name] = $this->createConnection($name);
        }

        // Reconnect if connection was closed
        if (! $this->connections[$name]->isConnected()) {
            Log::info('RabbitMQ connection lost, attempting reconnect', [
                'connection' => $name,
            ]);

            try {
                $this->connections[$name]->reconnect();

                Log::info('RabbitMQ reconnection successful', [
                    'connection' => $name,
                ]);
            } catch (AMQPConnectionException $e) {
                Log::error('RabbitMQ reconnection failed', [
                    'connection' => $name,
                    'error' => $e->getMessage(),
                ]);

                throw new ConnectionException(
                    "Failed to reconnect to RabbitMQ [{$name}]: {$e->getMessage()}",
                    previous: $e
                );
            }
        }

        return $this->connections[$name];
    }

    /**
     * Create a new connection for the given configuration name.
     *
     * Tries each configured host in order until one succeeds. Logs warnings
     * when primary hosts fail and a fallback host is used.
     *
     * @throws ConnectionException When all hosts fail
     */
    protected function createConnection(string $name): AMQPConnection
    {
        $config = $this->getConnectionConfig($name);

        if ($config === null) {
            throw new ConnectionException("RabbitMQ connection [{$name}] not configured.");
        }

        $hosts = Arr::get($config, 'hosts', []);
        $options = Arr::get($config, 'options', []);
        $ssl = Arr::get($config, 'ssl', []);

        if (empty($hosts)) {
            throw new ConnectionException("RabbitMQ connection [{$name}] has no hosts configured.");
        }

        // Try each host until one succeeds, tracking failures
        $lastException = null;
        $failures = [];

        foreach ($hosts as $index => $host) {
            $hostIdentifier = Arr::get($host, 'host', "host_{$index}");

            try {
                $connection = $this->createConnectionToHost($host, $options, $ssl);

                // Log if we connected to a fallback host
                if (count($failures) > 0) {
                    Log::warning('RabbitMQ connected to fallback host', [
                        'connection' => $name,
                        'connected_host' => $hostIdentifier,
                        'failed_hosts' => array_column($failures, 'host'),
                    ]);
                }

                return $connection;
            } catch (AMQPConnectionException $e) {
                $failures[] = [
                    'host' => $hostIdentifier,
                    'error' => $e->getMessage(),
                ];

                Log::warning('RabbitMQ host connection failed, trying next', [
                    'connection' => $name,
                    'host' => $hostIdentifier,
                    'error' => $e->getMessage(),
                    'remaining_hosts' => count($hosts) - $index - 1,
                ]);

                $lastException = $e;
            }
        }

        Log::error('All RabbitMQ hosts failed', [
            'connection' => $name,
            'failures' => $failures,
        ]);

        throw new ConnectionException(
            "Failed to connect to RabbitMQ [{$name}]. All ".count($hosts).' hosts failed.',
            previous: $lastException
        );
    }

    /**
     * Create a connection to a specific host.
     *
     * @param  array<string, mixed>  $host
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $ssl
     *
     * @throws AMQPConnectionException
     */
    protected function createConnectionToHost(array $host, array $options, array $ssl): AMQPConnection
    {
        $credentials = [
            'host' => Arr::get($host, 'host', 'localhost'),
            'port' => Arr::get($host, 'port', 5672),
            'login' => Arr::get($host, 'user', 'guest'),
            'password' => Arr::get($host, 'password', 'guest'),
            'vhost' => Arr::get($host, 'vhost', '/'),
            'heartbeat' => Arr::get($options, 'heartbeat', 60),
            'connect_timeout' => Arr::get($options, 'connection_timeout', 30),
            'read_timeout' => Arr::get($options, 'read_timeout', 300),
            'write_timeout' => Arr::get($options, 'write_timeout', 300),
            'rpc_timeout' => Arr::get($options, 'channel_rpc_timeout', 0),
        ];

        // Add SSL configuration if enabled
        if (Arr::get($ssl, 'enabled', false)) {
            $credentials['cacert'] = Arr::get($ssl, 'cafile');
            $credentials['cert'] = Arr::get($ssl, 'local_cert');
            $credentials['key'] = Arr::get($ssl, 'local_key');
            $credentials['verify'] = Arr::get($ssl, 'verify_peer', true);
        }

        $connection = new AMQPConnection($credentials);
        $connection->connect();

        return $connection;
    }

    /**
     * Get configuration for a specific connection.
     *
     * @return array<string, mixed>|null
     */
    protected function getConnectionConfig(string $name): ?array
    {
        return Arr::get($this->config, "connections.{$name}");
    }

    /**
     * Get the default connection name.
     */
    public function getDefaultConnection(): string
    {
        return Arr::get($this->config, 'default', 'default');
    }

    /**
     * Disconnect from a specific connection.
     */
    public function disconnect(?string $name = null): void
    {
        $name ??= $this->getDefaultConnection();

        if (isset($this->connections[$name]) && $this->connections[$name]->isConnected()) {
            $this->connections[$name]->disconnect();
        }

        unset($this->connections[$name]);
    }

    /**
     * Disconnect from all connections.
     */
    public function disconnectAll(): void
    {
        foreach (array_keys($this->connections) as $name) {
            $this->disconnect($name);
        }
    }

    /**
     * Check if a connection is established and healthy.
     */
    public function isConnected(?string $name = null): bool
    {
        $name ??= $this->getDefaultConnection();

        return isset($this->connections[$name]) && $this->connections[$name]->isConnected();
    }

    /**
     * Get all active connection names.
     *
     * @return array<string>
     */
    public function getActiveConnections(): array
    {
        return array_keys(array_filter(
            $this->connections,
            fn (AMQPConnection $conn) => $conn->isConnected()
        ));
    }
}
