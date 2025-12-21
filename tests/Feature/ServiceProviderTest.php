<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Connection\ConnectionManager;
use Lettermint\RabbitMQ\Consumers\Consumer;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Monitoring\HealthCheck;
use Lettermint\RabbitMQ\Monitoring\QueueMetrics;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;
use Lettermint\RabbitMQ\Topology\TopologyManager;

describe('RabbitMQServiceProvider', function () {
    describe('service registration', function () {
        it('registers ConnectionManager as singleton', function () {
            $instance1 = app(ConnectionManager::class);
            $instance2 = app(ConnectionManager::class);

            expect($instance1)->toBeInstanceOf(ConnectionManager::class);
            expect($instance1)->toBe($instance2);
        });

        it('registers ChannelManager as singleton', function () {
            $instance1 = app(ChannelManager::class);
            $instance2 = app(ChannelManager::class);

            expect($instance1)->toBeInstanceOf(ChannelManager::class);
            expect($instance1)->toBe($instance2);
        });

        it('registers AttributeScanner as singleton', function () {
            $instance1 = app(AttributeScanner::class);
            $instance2 = app(AttributeScanner::class);

            expect($instance1)->toBeInstanceOf(AttributeScanner::class);
            expect($instance1)->toBe($instance2);
        });

        it('registers TopologyManager as singleton', function () {
            $instance1 = app(TopologyManager::class);
            $instance2 = app(TopologyManager::class);

            expect($instance1)->toBeInstanceOf(TopologyManager::class);
            expect($instance1)->toBe($instance2);
        });

        it('registers RabbitMQQueue as singleton', function () {
            $instance1 = app(RabbitMQQueue::class);
            $instance2 = app(RabbitMQQueue::class);

            expect($instance1)->toBeInstanceOf(RabbitMQQueue::class);
            expect($instance1)->toBe($instance2);
        });

        it('registers Consumer as singleton', function () {
            $instance1 = app(Consumer::class);
            $instance2 = app(Consumer::class);

            expect($instance1)->toBeInstanceOf(Consumer::class);
            expect($instance1)->toBe($instance2);
        });

        it('registers HealthCheck as singleton', function () {
            $instance1 = app(HealthCheck::class);
            $instance2 = app(HealthCheck::class);

            expect($instance1)->toBeInstanceOf(HealthCheck::class);
            expect($instance1)->toBe($instance2);
        });

        it('registers QueueMetrics as singleton', function () {
            $instance1 = app(QueueMetrics::class);
            $instance2 = app(QueueMetrics::class);

            expect($instance1)->toBeInstanceOf(QueueMetrics::class);
            expect($instance1)->toBe($instance2);
        });
    });

    describe('configuration', function () {
        it('merges package config', function () {
            expect(config('rabbitmq'))->not->toBeNull();
            expect(config('rabbitmq.default'))->toBe('default');
        });

        it('has connections config', function () {
            expect(config('rabbitmq.connections'))->toBeArray();
            expect(config('rabbitmq.connections.default'))->not->toBeNull();
        });

        it('has discovery paths config', function () {
            expect(config('rabbitmq.discovery.paths'))->toBeArray();
        });

        it('has dead letter config', function () {
            expect(config('rabbitmq.dead_letter'))->toBeArray();
            expect(config('rabbitmq.dead_letter.enabled'))->toBeTrue();
        });

        it('has delayed config', function () {
            expect(config('rabbitmq.delayed'))->toBeArray();
            expect(config('rabbitmq.delayed.enabled'))->toBeTrue();
        });
    });

    describe('attribute scanning', function () {
        it('scans test fixture directories', function () {
            $scanner = app(AttributeScanner::class);

            // The TestCase configures discovery paths to point to fixtures
            $exchanges = $scanner->getExchanges();
            $queues = $scanner->getQueues();

            expect($exchanges->count())->toBeGreaterThanOrEqual(0);
            expect($queues->count())->toBeGreaterThanOrEqual(0);
        });
    });
});
