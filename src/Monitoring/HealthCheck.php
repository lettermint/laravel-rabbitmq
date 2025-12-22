<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Monitoring;

use AMQPConnectionException;
use Illuminate\Support\Facades\Log;
use Lettermint\RabbitMQ\Connection\ConnectionManager;
use Lettermint\RabbitMQ\Exceptions\ConnectionException;

/**
 * RabbitMQ health check service.
 *
 * Provides health check endpoints compatible with Kubernetes probes
 * and general monitoring systems.
 */
class HealthCheck
{
    public function __construct(
        protected ConnectionManager $connectionManager,
    ) {}

    /**
     * Perform comprehensive health checks.
     *
     * @return array{healthy: bool, checks: array<string, array{healthy: bool, message: string}>}
     */
    public function check(): array
    {
        $checks = [];
        $allHealthy = true;

        // Check connection
        $connectionCheck = $this->checkConnection();
        $checks['connection'] = $connectionCheck;
        if (! $connectionCheck['healthy']) {
            $allHealthy = false;
        }

        return [
            'healthy' => $allHealthy,
            'checks' => $checks,
        ];
    }

    /**
     * Check RabbitMQ connection.
     *
     * @return array{healthy: bool, message: string}
     */
    protected function checkConnection(): array
    {
        try {
            $connection = $this->connectionManager->connection();

            if ($connection->isConnected()) {
                return [
                    'healthy' => true,
                    'message' => 'Connected to RabbitMQ',
                ];
            }

            Log::error('RabbitMQ health check: connection exists but not connected');

            return [
                'healthy' => false,
                'message' => 'Connection established but not connected',
            ];
        } catch (ConnectionException|AMQPConnectionException $e) {
            Log::error('RabbitMQ health check failed', [
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);

            return [
                'healthy' => false,
                'message' => 'Failed to connect: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Simple ping check for quick health verification.
     *
     * Returns true if RabbitMQ is reachable and connected.
     */
    public function ping(): bool
    {
        try {
            $connected = $this->connectionManager->connection()->isConnected();

            if (! $connected) {
                Log::error('RabbitMQ ping: connection not connected');
            }

            return $connected;
        } catch (ConnectionException|AMQPConnectionException $e) {
            Log::error('RabbitMQ ping failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Kubernetes-compatible health check response.
     *
     * Returns a response format suitable for Kubernetes health check endpoints
     * with standard UP/DOWN status indicators.
     *
     * @return array{status: string, components: array<string, array{status: string, details: array}>}
     */
    public function kubernetes(): array
    {
        $check = $this->check();

        return [
            'status' => $check['healthy'] ? 'UP' : 'DOWN',
            'components' => [
                'rabbitmq' => [
                    'status' => $check['healthy'] ? 'UP' : 'DOWN',
                    'details' => $check['checks'],
                ],
            ],
        ];
    }

    /**
     * Kubernetes liveness probe check.
     *
     * Indicates whether the service is alive and running. Should return true
     * even if RabbitMQ is temporarily unavailable - we only return false if
     * the service itself is in a bad state.
     *
     * @see https://kubernetes.io/docs/tasks/configure-pod-container/configure-liveness-readiness-startup-probes/
     */
    public function liveness(): bool
    {
        // Liveness just checks if the service is running
        // We're alive even if RabbitMQ is temporarily down
        return true;
    }

    /**
     * Kubernetes readiness probe check.
     *
     * Indicates whether the service is ready to accept traffic. Returns true
     * only if RabbitMQ is connected and ready to handle messages.
     *
     * @see https://kubernetes.io/docs/tasks/configure-pod-container/configure-liveness-readiness-startup-probes/
     */
    public function readiness(): bool
    {
        return $this->ping();
    }
}
