<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Connection;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Lettermint\RabbitMQ\Exceptions\ConnectionException;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPRuntimeException;

/**
 * Manages RabbitMQ connections using php-amqplib.
 *
 * This class handles connection creation, pooling, and lifecycle management.
 * It supports multiple hosts for failover, SSL connections, and circuit breaker
 * protection to prevent cascading failures during RabbitMQ outages.
 */
class ConnectionManager
{
    /**
     * Active connections indexed by name.
     *
     * @var array<string, AbstractConnection>
     */
    protected array $connections = [];

    /**
     * Circuit breaker for connection resilience.
     */
    protected CircuitBreaker $circuitBreaker;

    /**
     * @param  array<string, mixed>  $config  Full RabbitMQ configuration array
     */
    public function __construct(
        protected array $config,
        ?CircuitBreaker $circuitBreaker = null,
    ) {
        $this->circuitBreaker = $circuitBreaker ?? new CircuitBreaker(
            failureThreshold: (int) Arr::get($config, 'circuit_breaker.failure_threshold', 5),
            recoveryTimeout: (float) Arr::get($config, 'circuit_breaker.recovery_timeout', 30.0),
        );
    }

    /**
     * Get or create a connection by name.
     *
     * Automatically handles reconnection if the connection was closed,
     * with appropriate logging for visibility into connection issues.
     * Uses circuit breaker to prevent cascading failures during outages.
     *
     * @throws ConnectionException When connection fails or circuit breaker is open
     */
    public function connection(?string $name = null): AbstractConnection
    {
        $name ??= $this->getDefaultConnection();

        // Check circuit breaker before attempting connection
        if (! $this->circuitBreaker->isAvailable()) {
            throw new ConnectionException(
                'RabbitMQ circuit breaker is open - connections are temporarily blocked after repeated failures. '.
                "Retry after {$this->circuitBreaker->getOpenDuration()} seconds.",
            );
        }

        try {
            if (! isset($this->connections[$name])) {
                $this->connections[$name] = $this->createConnection($name);
            }

            // Reconnect if connection was closed
            if (! $this->connections[$name]->isConnected()) {
                Log::info('RabbitMQ connection lost, attempting reconnect', [
                    'connection' => $name,
                ]);

                $this->connections[$name]->reconnect();

                Log::info('RabbitMQ reconnection successful', [
                    'connection' => $name,
                ]);
            }

            // Record success with circuit breaker
            $this->circuitBreaker->recordSuccess();

            return $this->connections[$name];
        } catch (AMQPIOException|AMQPConnectionClosedException|AMQPRuntimeException $e) {
            // Record failure with circuit breaker
            $this->circuitBreaker->recordFailure();

            Log::error('RabbitMQ connection failed', [
                'connection' => $name,
                'error' => $e->getMessage(),
                'circuit_breaker_failures' => $this->circuitBreaker->getFailures(),
                'circuit_breaker_state' => $this->circuitBreaker->getState()->value,
            ]);

            throw new ConnectionException(
                "Failed to connect to RabbitMQ [{$name}]: {$e->getMessage()}",
                previous: $e
            );
        }
    }

    /**
     * Create a new connection for the given configuration name.
     *
     * Tries each configured host in order until one succeeds. Logs warnings
     * when primary hosts fail and a fallback host is used.
     *
     * @throws ConnectionException When all hosts fail
     */
    protected function createConnection(string $name): AbstractConnection
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
            } catch (AMQPIOException|AMQPConnectionClosedException|AMQPRuntimeException $e) {
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
     * @throws AMQPIOException|AMQPConnectionClosedException|AMQPRuntimeException
     */
    protected function createConnectionToHost(array $host, array $options, array $ssl): AbstractConnection
    {
        $config = new AMQPConnectionConfig;

        // Connection settings
        $config->setHost(Arr::get($host, 'host', 'localhost'));
        $config->setPort((int) Arr::get($host, 'port', 5672));
        $config->setUser(Arr::get($host, 'user', 'guest'));
        $config->setPassword(Arr::get($host, 'password', 'guest'));
        $config->setVhost(Arr::get($host, 'vhost', '/'));

        // Timeouts and heartbeat
        $config->setHeartbeat((int) Arr::get($options, 'heartbeat', 60));
        $config->setConnectionTimeout((float) Arr::get($options, 'connection_timeout', 30.0));
        $config->setReadTimeout((float) Arr::get($options, 'read_timeout', 300.0));
        $config->setWriteTimeout((float) Arr::get($options, 'write_timeout', 300.0));
        $config->setChannelRPCTimeout((float) Arr::get($options, 'channel_rpc_timeout', 0.0));

        // SSL configuration
        if (Arr::get($ssl, 'enabled', false)) {
            $config->setIsSecure(true);

            if ($caFile = Arr::get($ssl, 'cafile')) {
                $config->setSslCaCert($caFile);
            }

            if ($localCert = Arr::get($ssl, 'local_cert')) {
                $config->setSslCert($localCert);
            }

            if ($localKey = Arr::get($ssl, 'local_key')) {
                $config->setSslKey($localKey);
            }

            $config->setSslVerify(Arr::get($ssl, 'verify_peer', true));
        }

        return AMQPConnectionFactory::create($config);
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
     *
     * Gracefully handles exceptions during close - the connection is removed
     * from the pool regardless of whether close() succeeds.
     */
    public function disconnect(?string $name = null): void
    {
        $name ??= $this->getDefaultConnection();

        try {
            if (isset($this->connections[$name]) && $this->connections[$name]->isConnected()) {
                $this->connections[$name]->close();
            }
        } catch (AMQPIOException|AMQPConnectionClosedException $e) {
            Log::debug('Connection close during disconnect (expected during cleanup)', [
                'connection' => $name,
                'error' => $e->getMessage(),
            ]);
        } finally {
            unset($this->connections[$name]);
        }
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
            fn (AbstractConnection $conn) => $conn->isConnected()
        ));
    }

    /**
     * Get the heartbeat interval for a connection.
     */
    public function getHeartbeat(?string $name = null): int
    {
        $connection = $this->connection($name);

        return $connection->getHeartbeat();
    }

    /**
     * Get the circuit breaker instance.
     */
    public function getCircuitBreaker(): CircuitBreaker
    {
        return $this->circuitBreaker;
    }
}
