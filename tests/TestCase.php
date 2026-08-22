<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Tests;

use Illuminate\Foundation\Application;
use Lettermint\RabbitMQ\Facades\RabbitMQ;
use Lettermint\RabbitMQ\RabbitMQServiceProvider;
use Monolog\Handler\NullHandler;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * Get package providers.
     *
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            RabbitMQServiceProvider::class,
        ];
    }

    /**
     * Get package aliases.
     *
     * @param  Application  $app
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return [
            'RabbitMQ' => RabbitMQ::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        // Configure logging to handle deprecations properly
        // This prevents errors when older dependencies trigger PHP 8.4 deprecation warnings
        $app['config']->set('logging.deprecations', 'null');
        $app['config']->set('logging.channels.null', [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ]);

        $app['config']->set('rabbitmq', [
            'default' => 'default',
            'connections' => [
                'default' => [
                    'hosts' => [
                        [
                            'host' => 'localhost',
                            'port' => 5672,
                            'user' => 'guest',
                            'password' => 'guest',
                            'vhost' => '/',
                        ],
                    ],
                    'options' => [
                        'heartbeat' => 60,
                        'connection_timeout' => 30,
                        'read_timeout' => 300,
                        'write_timeout' => 300,
                        'channel_rpc_timeout' => 0,
                    ],
                    'ssl' => [
                        'enabled' => false,
                    ],
                ],
            ],
            'discovery' => [
                'paths' => [
                    __DIR__.'/Fixtures/Jobs',
                    __DIR__.'/Fixtures/Exchanges',
                ],
                'cache' => false,
            ],
            'queue' => [
                'default' => 'default',
            ],
            'dead_letter' => [
                'enabled' => true,
                'exchange_suffix' => '.dlq',
                'queue_prefix' => 'dlq:',
            ],
            'retry' => [
                'maximum_delay' => 86400,
                'delay_queue_cleanup_grace' => 86400000,
            ],
            'consumer' => [
                'prefetch_count' => 10,
                'timeout' => 30,
                'heartbeat_sender' => false,
            ],
            'publisher' => [
                'confirm' => true,
                'mandatory' => true,
            ],
            'monitoring' => [
                'health_check' => [
                    'enabled' => true,
                    'interval' => 30,
                ],
            ],
            'logging' => [
                'channel' => 'stack',
                'level' => 'info',
            ],
        ]);
    }
}
