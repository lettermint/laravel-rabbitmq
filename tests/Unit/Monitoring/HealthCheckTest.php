<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Monitoring\HealthCheck;

beforeEach(function () {
    $this->channel = mockAMQPChannel();
    $this->channelManager = Mockery::mock(ChannelManager::class);
    $this->channelManager->shouldReceive('topologyChannel')->with('broker')->andReturn($this->channel)->byDefault();
    $this->config = [
        'connection' => 'broker',
        'queue' => ['default' => 'default'],
        'physical_prefix' => 'staging.',
    ];
    $this->health = new HealthCheck(
        $this->channelManager,
        testTopologyRegistry($this->config),
        $this->config,
    );
});

test('is healthy only after a passive broker queue operation succeeds', function () {
    $this->channel->shouldReceive('queue_declare')
        ->once()
        ->with('staging.default', true, false, false, false)
        ->andReturn(['staging.default', 0, 1]);

    $result = $this->health->check();

    expect($result['healthy'])->toBeTrue()
        ->and($result['checks']['connection']['healthy'])->toBeTrue()
        ->and($result['checks']['connection']['message'])->toContain('Broker operation succeeded');
});

test('is unhealthy when the broker operation fails', function () {
    Log::spy();
    $this->channelManager->shouldReceive('topologyChannel')->with('broker')->andThrow(new RuntimeException('unavailable'));

    $result = $this->health->check();

    expect($result['healthy'])->toBeFalse()
        ->and($result['checks']['connection']['message'])->toContain('unavailable');
    Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => $message === 'RabbitMQ health check failed');
});

test('ping and readiness use the real broker check', function () {
    $this->channel->shouldReceive('queue_declare')->twice()->andReturn(['staging.default', 0, 1]);

    expect($this->health->ping())->toBeTrue()
        ->and($this->health->readiness())->toBeTrue();
});

test('returns a Kubernetes down response when the broker check fails', function () {
    $this->channelManager->shouldReceive('topologyChannel')->andThrow(new RuntimeException('unavailable'));

    $result = $this->health->kubernetes();

    expect($result['status'])->toBe('DOWN')
        ->and($result['components']['rabbitmq']['status'])->toBe('DOWN')
        ->and($result['components']['rabbitmq']['details']['connection']['healthy'])->toBeFalse();
});

test('keeps liveness independent from RabbitMQ availability', function () {
    $this->channelManager->shouldNotReceive('topologyChannel');

    expect($this->health->liveness())->toBeTrue();
});
