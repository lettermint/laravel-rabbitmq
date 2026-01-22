<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\ServiceProvider;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Connection\ConnectionManager;
use Lettermint\RabbitMQ\Console\Commands\ConsumeCommand;
use Lettermint\RabbitMQ\Console\Commands\DeclareCommand;
use Lettermint\RabbitMQ\Console\Commands\DlqInspectCommand;
use Lettermint\RabbitMQ\Console\Commands\DlqPurgeCommand;
use Lettermint\RabbitMQ\Console\Commands\HealthCommand;
use Lettermint\RabbitMQ\Console\Commands\PurgeCommand;
use Lettermint\RabbitMQ\Console\Commands\QueuesCommand;
use Lettermint\RabbitMQ\Console\Commands\ReplayDlqCommand;
use Lettermint\RabbitMQ\Console\Commands\TestEventCommand;
use Lettermint\RabbitMQ\Console\Commands\TopologyCommand;
use Lettermint\RabbitMQ\Consumers\Consumer;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Monitoring\HealthCheck;
use Lettermint\RabbitMQ\Monitoring\QueueMetrics;
use Lettermint\RabbitMQ\Queue\RabbitMQConnector;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;
use Lettermint\RabbitMQ\Topology\TopologyManager;

class RabbitMQServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rabbitmq.php', 'rabbitmq');

        $this->registerConnectionManager();
        $this->registerChannelManager();
        $this->registerAttributeScanner();
        $this->registerTopologyManager();
        $this->registerQueueComponents();
        $this->registerConsumer();
        $this->registerMonitoring();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->publishConfig();
        $this->registerCommands();
        $this->registerQueueConnector();
        $this->scanTopology();
    }

    /**
     * Register the connection manager.
     */
    protected function registerConnectionManager(): void
    {
        $this->app->singleton(ConnectionManager::class, function ($app) {
            return new ConnectionManager(
                config: $app['config']['rabbitmq'] ?? [],
            );
        });
    }

    /**
     * Register the channel manager.
     */
    protected function registerChannelManager(): void
    {
        $this->app->singleton(ChannelManager::class, function ($app) {
            return new ChannelManager(
                connectionManager: $app[ConnectionManager::class],
            );
        });
    }

    /**
     * Register the attribute scanner.
     */
    protected function registerAttributeScanner(): void
    {
        $this->app->singleton(AttributeScanner::class, function () {
            return new AttributeScanner;
        });
    }

    /**
     * Register the topology manager.
     */
    protected function registerTopologyManager(): void
    {
        $this->app->singleton(TopologyManager::class, function ($app) {
            return new TopologyManager(
                channelManager: $app[ChannelManager::class],
                scanner: $app[AttributeScanner::class],
                config: $app['config']['rabbitmq'] ?? [],
            );
        });
    }

    /**
     * Register queue components.
     */
    protected function registerQueueComponents(): void
    {
        $this->app->singleton(RabbitMQQueue::class, function ($app) {
            return new RabbitMQQueue(
                channelManager: $app[ChannelManager::class],
                scanner: $app[AttributeScanner::class],
                config: $app['config']['rabbitmq'] ?? [],
            );
        });
    }

    /**
     * Register the consumer.
     */
    protected function registerConsumer(): void
    {
        $this->app->singleton(Consumer::class, function ($app) {
            return new Consumer(
                channelManager: $app[ChannelManager::class],
                scanner: $app[AttributeScanner::class],
                rabbitmq: $app[RabbitMQQueue::class],
                exceptions: $app[ExceptionHandler::class],
                events: $app[Dispatcher::class],
            );
        });
    }

    /**
     * Register monitoring services.
     */
    protected function registerMonitoring(): void
    {
        $this->app->singleton(HealthCheck::class, function ($app) {
            return new HealthCheck(
                connectionManager: $app[ConnectionManager::class],
            );
        });

        $this->app->singleton(QueueMetrics::class, function ($app) {
            return new QueueMetrics(
                channelManager: $app[ChannelManager::class],
            );
        });
    }

    /**
     * Publish configuration file.
     */
    protected function publishConfig(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/rabbitmq.php' => config_path('rabbitmq.php'),
            ], 'rabbitmq-config');
        }
    }

    /**
     * Register artisan commands.
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ConsumeCommand::class,
                DeclareCommand::class,
                DlqInspectCommand::class,
                DlqPurgeCommand::class,
                HealthCommand::class,
                PurgeCommand::class,
                QueuesCommand::class,
                ReplayDlqCommand::class,
                TestEventCommand::class,
                TopologyCommand::class,
            ]);
        }
    }

    /**
     * Register the RabbitMQ queue connector.
     */
    protected function registerQueueConnector(): void
    {
        $this->app->afterResolving(QueueManager::class, function (QueueManager $manager) {
            $manager->addConnector('rabbitmq', function () {
                return new RabbitMQConnector(
                    channelManager: $this->app[ChannelManager::class],
                    scanner: $this->app[AttributeScanner::class],
                );
            });
        });
    }

    /**
     * Scan for topology attributes.
     */
    protected function scanTopology(): void
    {
        $paths = config('rabbitmq.discovery.paths', [
            app_path('Jobs'),
            app_path('RabbitMQ'),
        ]);

        // Filter to only existing paths
        $paths = array_filter($paths, fn ($path) => is_dir($path));

        if (! empty($paths)) {
            $this->app[AttributeScanner::class]->scan($paths);
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<string>
     */
    public function provides(): array
    {
        return [
            ConnectionManager::class,
            ChannelManager::class,
            AttributeScanner::class,
            TopologyManager::class,
            RabbitMQQueue::class,
            Consumer::class,
            HealthCheck::class,
            QueueMetrics::class,
        ];
    }
}
