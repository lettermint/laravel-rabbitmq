<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Connection\ConnectionManager;
use Lettermint\RabbitMQ\Console\Commands\AuditCommand;
use Lettermint\RabbitMQ\Console\Commands\CacheTopologyCommand;
use Lettermint\RabbitMQ\Console\Commands\ConsumeCommand;
use Lettermint\RabbitMQ\Console\Commands\DeclareCommand;
use Lettermint\RabbitMQ\Console\Commands\DelayCleanupCommand;
use Lettermint\RabbitMQ\Console\Commands\DlqInspectCommand;
use Lettermint\RabbitMQ\Console\Commands\DlqPurgeCommand;
use Lettermint\RabbitMQ\Console\Commands\HealthCommand;
use Lettermint\RabbitMQ\Console\Commands\ProbeQueuesCommand;
use Lettermint\RabbitMQ\Console\Commands\PurgeCommand;
use Lettermint\RabbitMQ\Console\Commands\QueuesCommand;
use Lettermint\RabbitMQ\Console\Commands\ReplayDlqCommand;
use Lettermint\RabbitMQ\Console\Commands\TestEventCommand;
use Lettermint\RabbitMQ\Console\Commands\TopologyCommand;
use Lettermint\RabbitMQ\Console\Commands\WorkerStatusCommand;
use Lettermint\RabbitMQ\Consumers\Consumer;
use Lettermint\RabbitMQ\Consumers\RabbitMQWorker;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Discovery\AttributeTopologyCache;
use Lettermint\RabbitMQ\Monitoring\HealthCheck;
use Lettermint\RabbitMQ\Monitoring\QueueLifecycleSubscriber;
use Lettermint\RabbitMQ\Monitoring\QueueMetrics;
use Lettermint\RabbitMQ\Queue\RabbitMQConnector;
use Lettermint\RabbitMQ\Queue\RabbitMQQueue;
use Lettermint\RabbitMQ\Topology\TopologyManager;
use Lettermint\RabbitMQ\Topology\TopologyRegistry;

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
        $this->registerAttributeTopologyCache();
        $this->registerTopologyRegistry();
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
        $this->loadCachedTopology();
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'rabbitmq');
        $this->app[Dispatcher::class]->subscribe(QueueLifecycleSubscriber::class);
        $this->publishConfig();
        $this->registerCommands();
        $this->registerQueueConnector();
        $this->scanTopologyForConsole();
    }

    /**
     * Register the connection manager.
     */
    protected function registerConnectionManager(): void
    {
        $this->app->singleton(ConnectionManager::class, function ($app) {
            return new ConnectionManager(
                config: $app['config']['rabbitmq'] ?? [],
                events: $app[Dispatcher::class],
            );
        });
    }

    protected function registerTopologyRegistry(): void
    {
        $this->app->singleton(TopologyRegistry::class, function ($app) {
            return new TopologyRegistry(
                scanner: $app[AttributeScanner::class],
                config: $app['config']['rabbitmq'] ?? [],
                events: $app[Dispatcher::class],
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

    protected function registerAttributeTopologyCache(): void
    {
        $this->app->singleton(AttributeTopologyCache::class, function ($app) {
            return new AttributeTopologyCache(
                scanner: $app[AttributeScanner::class],
                config: $app['config']['rabbitmq'] ?? [],
            );
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
                registry: $app[TopologyRegistry::class],
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
                registry: $app[TopologyRegistry::class],
                events: $app[Dispatcher::class],
                config: $app['config']['rabbitmq'] ?? [],
            );
        });
    }

    /**
     * Register the consumer.
     */
    protected function registerConsumer(): void
    {
        $this->app->singleton(RabbitMQWorker::class, function ($app) {
            $resetScope = function () use ($app): void {
                if (method_exists($app['log'], 'flushSharedContext')) {
                    $app['log']->flushSharedContext();
                }

                if (method_exists($app['log'], 'withoutContext')) {
                    $app['log']->withoutContext();
                }

                if ($app->bound('db') && method_exists($app['db'], 'getConnections')) {
                    foreach ($app['db']->getConnections() as $connection) {
                        $connection->resetTotalQueryDuration();
                        $connection->allowQueryDurationHandlersToRunAgain();
                    }
                }

                $app->forgetScopedInstances();
                Facade::clearResolvedInstances();

                if (function_exists('memory_reset_peak_usage')) {
                    memory_reset_peak_usage();
                }
            };

            $worker = new RabbitMQWorker(
                $app['queue'],
                $app['events'],
                $app[ExceptionHandler::class],
                fn () => $app->isDownForMaintenance(),
                $resetScope,
            );

            if ($app->bound('cache')) {
                $worker->setCache($app['cache']->driver());
            }

            return $worker;
        });

        $this->app->singleton(Consumer::class, function ($app) {
            return new Consumer(
                channelManager: $app[ChannelManager::class],
                queueManager: $app['queue'],
                worker: $app[RabbitMQWorker::class],
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
                channelManager: $app[ChannelManager::class],
                registry: $app[TopologyRegistry::class],
                config: $app['config']['rabbitmq'] ?? [],
            );
        });

        $this->app->singleton(QueueMetrics::class, function ($app) {
            return new QueueMetrics(
                channelManager: $app[ChannelManager::class],
                registry: $app[TopologyRegistry::class],
                config: $app['config']['rabbitmq'] ?? [],
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
                DelayCleanupCommand::class,
                WorkerStatusCommand::class,
                AuditCommand::class,
                CacheTopologyCommand::class,
                DeclareCommand::class,
                DlqInspectCommand::class,
                DlqPurgeCommand::class,
                HealthCommand::class,
                PurgeCommand::class,
                ProbeQueuesCommand::class,
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
            $driverName = (string) config('rabbitmq.driver_name', 'rabbitmq');

            $manager->addConnector($driverName, function () {
                return new RabbitMQConnector(
                    channelManager: $this->app[ChannelManager::class],
                    registry: $this->app[TopologyRegistry::class],
                    events: $this->app[Dispatcher::class],
                    config: $this->app['config']['rabbitmq'] ?? [],
                );
            });
        });
    }

    /**
     * Scan for topology attributes.
     */
    protected function scanTopologyForConsole(): void
    {
        if (! $this->app->runningInConsole() || config('rabbitmq.topology.queues', []) !== []) {
            return;
        }

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

    protected function loadCachedTopology(): void
    {
        if (config('rabbitmq.topology.queues', []) !== []) {
            return;
        }

        $topology = $this->app[AttributeTopologyCache::class]->load();

        if ($topology !== null) {
            config(['rabbitmq.topology' => $topology]);
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
            AttributeTopologyCache::class,
            TopologyManager::class,
            TopologyRegistry::class,
            RabbitMQQueue::class,
            Consumer::class,
            RabbitMQWorker::class,
            HealthCheck::class,
            QueueMetrics::class,
        ];
    }
}
