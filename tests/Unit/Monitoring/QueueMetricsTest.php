<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Monitoring\QueueMetrics;

beforeEach(function () {
    $this->mockChannel = mockAMQPChannel();

    $this->channelManager = Mockery::mock(ChannelManager::class);
    $this->channelManager->shouldReceive('topologyChannel')
        ->andReturn($this->mockChannel)
        ->byDefault();
});

test('getQueueStats indicates not connected on error', function () {
    $this->channelManager->shouldReceive('topologyChannel')
        ->andThrow(new \Exception('Channel failed'));

    $metrics = new QueueMetrics($this->channelManager);

    $stats = $metrics->getQueueStats('test-queue');

    expect($stats['connected'])->toBeFalse();
    expect($stats['error'])->toBe('Channel failed');
    expect($stats['notice'])->toBeNull();
});

test('getQueueStats logs warning on error', function () {
    Log::spy();

    $this->channelManager->shouldReceive('topologyChannel')
        ->andThrow(new \Exception('Channel failed'));

    $metrics = new QueueMetrics($this->channelManager);
    $metrics->getQueueStats('test-queue');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($msg) => str_contains($msg, 'Failed to get queue stats'));
});

test('getAllQueueStats returns empty array for empty input', function () {
    $metrics = new QueueMetrics($this->channelManager);

    $stats = $metrics->getAllQueueStats([]);

    expect($stats)->toBeEmpty();
});

test('hasStatistics always returns false due to ext-amqp limitations', function () {
    $metrics = new QueueMetrics($this->channelManager);

    expect($metrics->hasStatistics())->toBeFalse();
});
