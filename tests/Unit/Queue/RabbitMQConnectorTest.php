<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Queue\RabbitMQConnector;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;

describe('RabbitMQConnector', function () {
    beforeEach(function () {
        $this->channelManager = Mockery::mock(ChannelManager::class);
        $this->registry = testTopologyRegistry();
        $this->events = Mockery::mock(Dispatcher::class);
        $this->config = [
            'publisher' => ['confirm' => true, 'mandatory' => true],
        ];
    });

    it('implements ConnectorInterface', function () {
        $connector = new RabbitMQConnector(
            $this->channelManager,
            $this->registry,
            $this->events,
            $this->config,
        );

        expect($connector)->toBeInstanceOf(ConnectorInterface::class);
    });

    it('returns RabbitMQQueue from connect', function () {
        $connector = new RabbitMQConnector(
            $this->channelManager,
            $this->registry,
            $this->events,
            $this->config,
        );

        $queue = $connector->connect([
            'queue' => 'test-queue',
        ]);

        expect($queue)->toBeInstanceOf(RabbitMQQueue::class);
    });

    it('passes config to queue instance', function () {
        $connector = new RabbitMQConnector(
            $this->channelManager,
            $this->registry,
            $this->events,
            $this->config,
        );

        $queue = $connector->connect([
            'queue' => 'custom-queue',
        ]);

        expect($queue->getQueue(null))->toBe('custom-queue');
    });
});
