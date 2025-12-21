<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Queue\RabbitMQConnector;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;

describe('RabbitMQConnector', function () {
    beforeEach(function () {
        $this->channelManager = Mockery::mock(ChannelManager::class);
        $this->scanner = Mockery::mock(AttributeScanner::class);
    });

    it('implements ConnectorInterface', function () {
        $connector = new RabbitMQConnector(
            $this->channelManager,
            $this->scanner
        );

        expect($connector)->toBeInstanceOf(\Illuminate\Queue\Connectors\ConnectorInterface::class);
    });

    it('returns RabbitMQQueue from connect', function () {
        $connector = new RabbitMQConnector(
            $this->channelManager,
            $this->scanner
        );

        $queue = $connector->connect([
            'queue' => 'test-queue',
        ]);

        expect($queue)->toBeInstanceOf(RabbitMQQueue::class);
    });

    it('passes config to queue instance', function () {
        $connector = new RabbitMQConnector(
            $this->channelManager,
            $this->scanner
        );

        $queue = $connector->connect([
            'queue' => 'custom-queue',
        ]);

        expect($queue->getQueue(null))->toBe('custom-queue');
    });
});
