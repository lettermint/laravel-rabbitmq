<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Connection
    |--------------------------------------------------------------------------
    |
    | The default RabbitMQ connection to use.
    |
    */

    'default' => env('RABBITMQ_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | RabbitMQ Connections
    |--------------------------------------------------------------------------
    |
    | Configure your RabbitMQ connections here. You can define multiple
    | connections for different environments or purposes.
    |
    */

    'connections' => [
        'default' => [
            'hosts' => [
                [
                    'host' => env('RABBITMQ_HOST', 'localhost'),
                    'port' => env('RABBITMQ_PORT', 5672),
                    'user' => env('RABBITMQ_USER', 'guest'),
                    'password' => env('RABBITMQ_PASSWORD', 'guest'),
                    'vhost' => env('RABBITMQ_VHOST', '/'),
                ],
            ],
            'options' => [
                'heartbeat' => env('RABBITMQ_HEARTBEAT', 60),
                'connection_timeout' => env('RABBITMQ_CONNECTION_TIMEOUT', 30),
                'read_timeout' => env('RABBITMQ_READ_TIMEOUT', 300),
                'write_timeout' => env('RABBITMQ_WRITE_TIMEOUT', 300),
                'channel_rpc_timeout' => env('RABBITMQ_CHANNEL_RPC_TIMEOUT', 0),
            ],
            'ssl' => [
                'enabled' => env('RABBITMQ_SSL', false),
                'cafile' => env('RABBITMQ_SSL_CAFILE'),
                'local_cert' => env('RABBITMQ_SSL_LOCAL_CERT'),
                'local_key' => env('RABBITMQ_SSL_LOCAL_KEY'),
                'verify_peer' => env('RABBITMQ_SSL_VERIFY_PEER', true),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Topology Discovery
    |--------------------------------------------------------------------------
    |
    | Directories to scan for Exchange and ConsumesQueue attributes.
    | The package will automatically discover and register these.
    |
    */

    'discovery' => [
        'paths' => [
            app_path('Jobs'),
            app_path('RabbitMQ'),
        ],
        'cache' => env('RABBITMQ_CACHE_TOPOLOGY', true),
        'cache_path' => storage_path('framework/cache/rabbitmq-topology.php'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Queue Settings
    |--------------------------------------------------------------------------
    |
    | Default settings applied to queues when not specified in attributes.
    |
    | The 'exchange' setting is used for jobs WITHOUT the #[ConsumesQueue]
    | attribute. When set, jobs are published to this exchange with a routing
    | key of 'fallback.{queue_name}'. Options:
    |
    | - Empty string '': Uses RabbitMQ's default exchange (routes directly
    |   to queues by name). Simple but no DLQ support.
    |
    | - Custom exchange name: Your fallback exchange for non-attributed jobs.
    |   Create a queue bound to 'fallback.#' to catch these messages.
    |
    */

    'queue' => [
        'default' => env('RABBITMQ_QUEUE', 'default'),
        'exchange' => env('RABBITMQ_EXCHANGE', ''),  // Fallback exchange for jobs without attributes
        'durable' => true,
        'quorum' => true,
        'auto_delete' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Exchange Settings
    |--------------------------------------------------------------------------
    |
    | Default settings applied to exchanges when not specified in attributes.
    |
    */

    'exchange' => [
        'type' => 'topic',
        'durable' => true,
        'auto_delete' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dead Letter Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for dead letter queues and retry behavior.
    |
    */

    'dead_letter' => [
        'enabled' => true,
        'exchange_suffix' => '.dlq',
        'queue_prefix' => 'dlq:',
        'default_ttl' => 604800000, // 7 days in milliseconds
        'retry' => [
            'enabled' => true,
            'max_attempts' => 3,
            'strategy' => 'exponential', // exponential, linear, fixed
            'delays' => [60, 300, 900, 3600], // seconds
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Delayed Messages
    |--------------------------------------------------------------------------
    |
    | Configuration for delayed message support.
    | Requires the rabbitmq_delayed_message_exchange plugin.
    |
    */

    'delayed' => [
        'enabled' => env('RABBITMQ_DELAYED_ENABLED', true),
        'exchange' => 'delayed',
        'type' => 'x-delayed-message',
        'max_delay' => 86400000, // 24 hours in milliseconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Consumer Settings
    |--------------------------------------------------------------------------
    |
    | Default consumer configuration options.
    |
    */

    'consumer' => [
        'prefetch_count' => env('RABBITMQ_PREFETCH_COUNT', 10),
        'prefetch_size' => 0, // 0 = no limit
        'timeout' => 30,
        'auto_ack' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Publisher Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for message publishing.
    |
    */

    'publisher' => [
        'confirm' => env('RABBITMQ_PUBLISHER_CONFIRM', true),
        'mandatory' => true, // Return unroutable messages
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring & Health Checks
    |--------------------------------------------------------------------------
    |
    | Health check and monitoring configuration.
    |
    */

    'monitoring' => [
        'health_check' => [
            'enabled' => true,
            'interval' => 30, // seconds
        ],
        'metrics' => [
            'enabled' => env('RABBITMQ_METRICS_ENABLED', true),
            'driver' => 'prometheus', // prometheus, statsd
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Logging configuration for RabbitMQ operations.
    |
    */

    'logging' => [
        'channel' => env('RABBITMQ_LOG_CHANNEL', 'stack'),
        'level' => env('RABBITMQ_LOG_LEVEL', 'info'),
    ],

];
