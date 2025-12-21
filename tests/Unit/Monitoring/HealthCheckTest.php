<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Lettermint\RabbitMQ\Connection\ConnectionManager;
use Lettermint\RabbitMQ\Monitoring\HealthCheck;

describe('HealthCheck', function () {
    beforeEach(function () {
        Log::spy();

        $this->mockConnection = mockAMQPConnection(connected: true);

        $this->connectionManager = Mockery::mock(ConnectionManager::class);
        $this->connectionManager->shouldReceive('connection')
            ->andReturn($this->mockConnection)
            ->byDefault();
    });

    describe('check', function () {
        it('returns healthy when connection is active', function () {
            $healthCheck = new HealthCheck($this->connectionManager);

            $result = $healthCheck->check();

            expect($result['healthy'])->toBeTrue();
            expect($result['checks']['connection']['healthy'])->toBeTrue();
            expect($result['checks']['connection']['message'])->toBe('Connected to RabbitMQ');
        });

        it('returns unhealthy when connection fails', function () {
            $this->connectionManager->shouldReceive('connection')
                ->andThrow(new \Exception('Connection refused'));

            $healthCheck = new HealthCheck($this->connectionManager);

            $result = $healthCheck->check();

            expect($result['healthy'])->toBeFalse();
            expect($result['checks']['connection']['healthy'])->toBeFalse();
            expect($result['checks']['connection']['message'])->toContain('Failed to connect');
        });

        it('returns unhealthy when connection exists but not connected', function () {
            $this->mockConnection->shouldReceive('isConnected')->andReturn(false);

            $healthCheck = new HealthCheck($this->connectionManager);

            $result = $healthCheck->check();

            expect($result['healthy'])->toBeFalse();
            expect($result['checks']['connection']['healthy'])->toBeFalse();
            expect($result['checks']['connection']['message'])->toContain('not connected');
        });

        it('logs warning when connection exists but not connected', function () {
            $this->mockConnection->shouldReceive('isConnected')->andReturn(false);

            $healthCheck = new HealthCheck($this->connectionManager);
            $healthCheck->check();

            Log::shouldHaveReceived('warning')
                ->withArgs(fn ($msg) => str_contains($msg, 'not connected'));
        });
    });

    describe('ping', function () {
        it('returns true when connected', function () {
            $healthCheck = new HealthCheck($this->connectionManager);

            expect($healthCheck->ping())->toBeTrue();
        });

        it('returns false when disconnected', function () {
            $this->mockConnection->shouldReceive('isConnected')->andReturn(false);

            $healthCheck = new HealthCheck($this->connectionManager);

            expect($healthCheck->ping())->toBeFalse();
        });

        it('returns false on exception', function () {
            $this->connectionManager->shouldReceive('connection')
                ->andThrow(new \Exception('Connection failed'));

            $healthCheck = new HealthCheck($this->connectionManager);

            expect($healthCheck->ping())->toBeFalse();
        });

        it('logs warning when ping fails', function () {
            $this->connectionManager->shouldReceive('connection')
                ->andThrow(new \Exception('Connection failed'));

            $healthCheck = new HealthCheck($this->connectionManager);
            $healthCheck->ping();

            Log::shouldHaveReceived('warning')
                ->withArgs(fn ($msg) => str_contains($msg, 'ping failed'));
        });
    });

    describe('kubernetes', function () {
        it('returns UP status when healthy', function () {
            $healthCheck = new HealthCheck($this->connectionManager);

            $result = $healthCheck->kubernetes();

            expect($result['status'])->toBe('UP');
            expect($result['components']['rabbitmq']['status'])->toBe('UP');
        });

        it('returns DOWN status when unhealthy', function () {
            $this->connectionManager->shouldReceive('connection')
                ->andThrow(new \Exception('Connection failed'));

            $healthCheck = new HealthCheck($this->connectionManager);

            $result = $healthCheck->kubernetes();

            expect($result['status'])->toBe('DOWN');
            expect($result['components']['rabbitmq']['status'])->toBe('DOWN');
        });

        it('includes check details in components', function () {
            $healthCheck = new HealthCheck($this->connectionManager);

            $result = $healthCheck->kubernetes();

            expect($result['components']['rabbitmq'])->toHaveKey('details');
            expect($result['components']['rabbitmq']['details'])->toHaveKey('connection');
        });
    });

    describe('liveness', function () {
        it('always returns true', function () {
            $healthCheck = new HealthCheck($this->connectionManager);

            expect($healthCheck->liveness())->toBeTrue();
        });

        it('returns true even when RabbitMQ is down', function () {
            $this->connectionManager->shouldReceive('connection')
                ->andThrow(new \Exception('Connection failed'));

            $healthCheck = new HealthCheck($this->connectionManager);

            // Liveness should still be true - service is alive
            expect($healthCheck->liveness())->toBeTrue();
        });
    });

    describe('readiness', function () {
        it('returns true when connected', function () {
            $healthCheck = new HealthCheck($this->connectionManager);

            expect($healthCheck->readiness())->toBeTrue();
        });

        it('returns false when not connected', function () {
            $this->mockConnection->shouldReceive('isConnected')->andReturn(false);

            $healthCheck = new HealthCheck($this->connectionManager);

            expect($healthCheck->readiness())->toBeFalse();
        });
    });
});
