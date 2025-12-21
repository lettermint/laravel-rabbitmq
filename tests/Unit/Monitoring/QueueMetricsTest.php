<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Monitoring\QueueMetrics;

describe('QueueMetrics', function () {
    beforeEach(function () {
        Log::spy();

        $this->mockChannel = mockAMQPChannel();

        $this->channelManager = Mockery::mock(ChannelManager::class);
        $this->channelManager->shouldReceive('topologyChannel')
            ->andReturn($this->mockChannel)
            ->byDefault();
    });

    describe('getQueueStats', function () {
        it('indicates not connected on error', function () {
            $this->channelManager->shouldReceive('topologyChannel')
                ->andThrow(new \Exception('Channel failed'));

            $metrics = new QueueMetrics($this->channelManager);

            $stats = $metrics->getQueueStats('test-queue');

            expect($stats['connected'])->toBeFalse();
            expect($stats['error'])->toBe('Channel failed');
            expect($stats['notice'])->toBeNull();
        });

        it('logs warning on error', function () {
            $this->channelManager->shouldReceive('topologyChannel')
                ->andThrow(new \Exception('Channel failed'));

            $metrics = new QueueMetrics($this->channelManager);
            $metrics->getQueueStats('test-queue');

            Log::shouldHaveReceived('warning')
                ->withArgs(fn ($msg) => str_contains($msg, 'Failed to get queue stats'));
        });
    });

    describe('getAllQueueStats', function () {
        it('returns empty array for empty input', function () {
            $metrics = new QueueMetrics($this->channelManager);

            $stats = $metrics->getAllQueueStats([]);

            expect($stats)->toBeEmpty();
        });
    });

    describe('hasStatistics', function () {
        it('always returns false due to ext-amqp limitations', function () {
            $metrics = new QueueMetrics($this->channelManager);

            expect($metrics->hasStatistics())->toBeFalse();
        });
    });
});
