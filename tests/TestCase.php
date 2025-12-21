<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Tests;

use Lettermint\RabbitMQ\RabbitMQServiceProvider;
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
     * @param  \Illuminate\Foundation\Application  $app
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
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return [
            'RabbitMQ' => \Lettermint\RabbitMQ\Facades\RabbitMQ::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
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
                'exchange' => '',
                'durable' => true,
                'quorum' => true,
                'auto_delete' => false,
            ],
            'exchange' => [
                'type' => 'topic',
                'durable' => true,
                'auto_delete' => false,
            ],
            'dead_letter' => [
                'enabled' => true,
                'exchange_suffix' => '.dlq',
                'queue_prefix' => 'dlq:',
                'default_ttl' => 604800000,
                'retry' => [
                    'enabled' => true,
                    'max_attempts' => 3,
                    'strategy' => 'exponential',
                    'delays' => [60, 300, 900, 3600],
                ],
            ],
            'delayed' => [
                'enabled' => true,
                'exchange' => 'delayed',
                'type' => 'x-delayed-message',
                'max_delay' => 86400000,
            ],
            'consumer' => [
                'prefetch_count' => 10,
                'prefetch_size' => 0,
                'timeout' => 30,
                'auto_ack' => false,
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
                'metrics' => [
                    'enabled' => true,
                    'driver' => 'prometheus',
                ],
            ],
            'logging' => [
                'channel' => 'stack',
                'level' => 'info',
            ],
        ]);
    }
}
