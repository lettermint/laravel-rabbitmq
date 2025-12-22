<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Lettermint\RabbitMQ\Connection\ConnectionManager;
use Lettermint\RabbitMQ\Exceptions\ConnectionException;
use Lettermint\RabbitMQ\Monitoring\HealthCheck;

beforeEach(function () {
    $this->mockConnection = mockAMQPConnection(true);

    $this->connectionManager = Mockery::mock(ConnectionManager::class);
    $this->connectionManager->shouldReceive('connection')
        ->andReturn($this->mockConnection)
        ->byDefault();
});

test('check returns healthy when connection is active', function () {
    $healthCheck = new HealthCheck($this->connectionManager);

    $result = $healthCheck->check();

    expect($result['healthy'])->toBeTrue();
    expect($result['checks']['connection']['healthy'])->toBeTrue();
    expect($result['checks']['connection']['message'])->toBe('Connected to RabbitMQ');
});

test('check returns unhealthy when connection fails', function () {
    $this->connectionManager->shouldReceive('connection')
        ->andThrow(new ConnectionException('Connection refused'));

    $healthCheck = new HealthCheck($this->connectionManager);

    $result = $healthCheck->check();

    expect($result['healthy'])->toBeFalse();
    expect($result['checks']['connection']['healthy'])->toBeFalse();
    expect($result['checks']['connection']['message'])->toContain('Failed to connect');
});

test('check returns unhealthy when connection exists but not connected', function () {
    $this->mockConnection->shouldReceive('isConnected')->andReturn(false);

    $healthCheck = new HealthCheck($this->connectionManager);

    $result = $healthCheck->check();

    expect($result['healthy'])->toBeFalse();
    expect($result['checks']['connection']['healthy'])->toBeFalse();
    expect($result['checks']['connection']['message'])->toContain('not connected');
});

test('check logs error when connection exists but not connected', function () {
    Log::spy();

    $this->mockConnection->shouldReceive('isConnected')->andReturn(false);

    $healthCheck = new HealthCheck($this->connectionManager);
    $healthCheck->check();

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($msg) => str_contains($msg, 'not connected'));
});

test('ping returns true when connected', function () {
    $healthCheck = new HealthCheck($this->connectionManager);

    expect($healthCheck->ping())->toBeTrue();
});

test('ping returns false when disconnected', function () {
    $this->mockConnection->shouldReceive('isConnected')->andReturn(false);

    $healthCheck = new HealthCheck($this->connectionManager);

    expect($healthCheck->ping())->toBeFalse();
});

test('ping returns false on exception', function () {
    $this->connectionManager->shouldReceive('connection')
        ->andThrow(new ConnectionException('Connection failed'));

    $healthCheck = new HealthCheck($this->connectionManager);

    expect($healthCheck->ping())->toBeFalse();
});

test('ping logs error when ping fails', function () {
    Log::spy();

    $this->connectionManager->shouldReceive('connection')
        ->andThrow(new ConnectionException('Connection failed'));

    $healthCheck = new HealthCheck($this->connectionManager);
    $healthCheck->ping();

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($msg) => str_contains($msg, 'ping failed'));
});

test('kubernetes returns UP status when healthy', function () {
    $healthCheck = new HealthCheck($this->connectionManager);

    $result = $healthCheck->kubernetes();

    expect($result['status'])->toBe('UP');
    expect($result['components']['rabbitmq']['status'])->toBe('UP');
});

test('kubernetes returns DOWN status when unhealthy', function () {
    $this->connectionManager->shouldReceive('connection')
        ->andThrow(new ConnectionException('Connection failed'));

    $healthCheck = new HealthCheck($this->connectionManager);

    $result = $healthCheck->kubernetes();

    expect($result['status'])->toBe('DOWN');
    expect($result['components']['rabbitmq']['status'])->toBe('DOWN');
});

test('kubernetes includes check details in components', function () {
    $healthCheck = new HealthCheck($this->connectionManager);

    $result = $healthCheck->kubernetes();

    expect($result['components']['rabbitmq'])->toHaveKey('details');
    expect($result['components']['rabbitmq']['details'])->toHaveKey('connection');
});

test('liveness always returns true', function () {
    $healthCheck = new HealthCheck($this->connectionManager);

    expect($healthCheck->liveness())->toBeTrue();
});

test('liveness returns true even when RabbitMQ is down', function () {
    $this->connectionManager->shouldReceive('connection')
        ->andThrow(new ConnectionException('Connection failed'));

    $healthCheck = new HealthCheck($this->connectionManager);

    expect($healthCheck->liveness())->toBeTrue();
});

test('readiness returns true when connected', function () {
    $healthCheck = new HealthCheck($this->connectionManager);

    expect($healthCheck->readiness())->toBeTrue();
});

test('readiness returns false when not connected', function () {
    $this->mockConnection->shouldReceive('isConnected')->andReturn(false);

    $healthCheck = new HealthCheck($this->connectionManager);

    expect($healthCheck->readiness())->toBeFalse();
});
