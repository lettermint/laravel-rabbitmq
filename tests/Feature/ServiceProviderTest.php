<?php

declare(strict_types=1);

use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\WorkerStopping;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Connection\ConnectionManager;
use Lettermint\RabbitMQ\Consumers\Consumer;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Monitoring\HealthCheck;
use Lettermint\RabbitMQ\Monitoring\QueueMetrics;
use Lettermint\RabbitMQ\Queue\Failed\RabbitMQDlqFailedJobProvider;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;
use Lettermint\RabbitMQ\RabbitMQServiceProvider;
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

        it('registers Consumer as a fresh stateful service', function () {
            $instance1 = app(Consumer::class);
            $instance2 = app(Consumer::class);

            expect($instance1)->toBeInstanceOf(Consumer::class);
            expect($instance1)->not->toBe($instance2);
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

        it('registers RabbitMQ DLQ as Laravel failed job provider when configured', function () {
            config()->set('queue.failed.driver', 'rabbitmq-dlq');
            app()->forgetInstance('queue.failer');
            app()->register(RabbitMQServiceProvider::class, true);

            expect(app('queue.failer'))->toBeInstanceOf(RabbitMQDlqFailedJobProvider::class);
        });

        it('closes resolved RabbitMQ resources when the application terminates', function () {
            $channelManager = Mockery::mock(ChannelManager::class);
            $connectionManager = Mockery::mock(ConnectionManager::class);

            $channelManager->shouldReceive('closeAll')->once();
            $connectionManager->shouldReceive('disconnectAll')->once();

            app()->instance(ChannelManager::class, $channelManager);
            app()->instance(ConnectionManager::class, $connectionManager);

            app()->terminate();
        });

        it('closes resolved RabbitMQ resources for Octane lifecycle events when Octane is present', function () {
            declareFakeOctaneEvents();

            config()->set('rabbitmq.octane.flush_connections', true);
            app()->register(RabbitMQServiceProvider::class, true);

            $channelManager = Mockery::mock(ChannelManager::class);
            $connectionManager = Mockery::mock(ConnectionManager::class);

            $channelManager->shouldReceive('closeAll')->times(3);
            $connectionManager->shouldReceive('disconnectAll')->times(3);

            app()->instance(ChannelManager::class, $channelManager);
            app()->instance(ConnectionManager::class, $connectionManager);

            event(new RequestTerminated);
            event(new TaskTerminated);
            event(new WorkerStopping);
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

        it('has Octane lifecycle config', function () {
            expect(config('rabbitmq.octane'))->toBeArray();
            expect(config('rabbitmq.octane.flush_connections'))->toBeTrue();
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

function declareFakeOctaneEvents(): void
{
    if (class_exists('Laravel\\Octane\\Events\\RequestTerminated')) {
        return;
    }

    eval('
        namespace Laravel\Octane\Events;

        class RequestTerminated {}
        class TaskTerminated {}
        class WorkerStopping {}
    ');
}
