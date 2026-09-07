<?php

return [

    'driver_name' => env('RABBITMQ_DRIVER_NAME', 'rabbitmq'),

    'physical_prefix' => env('RABBITMQ_PHYSICAL_PREFIX', ''),

    'strict_topology' => env('RABBITMQ_STRICT_TOPOLOGY', false),

    /*
    |--------------------------------------------------------------------------
    | Default Connection
    |--------------------------------------------------------------------------
    |
    | The default RabbitMQ connection to use.
    |
    */

    'default' => env('RABBITMQ_CONNECTION', 'default'),

    'management' => [
        'url' => env('RABBITMQ_MANAGEMENT_URL'),
        'user' => env('RABBITMQ_MANAGEMENT_USER'),
        'password' => env('RABBITMQ_MANAGEMENT_PASSWORD'),
        'ca_file' => env('RABBITMQ_MANAGEMENT_CA_FILE'),
    ],

    'dlq' => [
        'max_scan_messages' => 1000,
        'max_scan_bytes' => 16777216,
        'max_result_messages' => 100,
        'max_runtime_seconds' => 30,
    ],

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
                'connection_timeout' => env('RABBITMQ_CONNECTION_TIMEOUT', 5),
                'read_timeout' => env('RABBITMQ_READ_TIMEOUT', 10),
                'write_timeout' => env('RABBITMQ_WRITE_TIMEOUT', 10),
                'channel_rpc_timeout' => env('RABBITMQ_CHANNEL_RPC_TIMEOUT', 5),
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
    | Explicit Topology
    |--------------------------------------------------------------------------
    |
    | Use logical names as keys. The physical prefix is added to every queue
    | and exchange. An empty queue list keeps attribute discovery available.
    | A compiled attribute cache is loaded as explicit topology at runtime.
    |
    */

    'topology' => [
        'exchanges' => [],
        'queues' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Attribute Discovery
    |--------------------------------------------------------------------------
    |
    | Use rabbitmq:cache during the build to compile the attributes. Web
    | requests load this cache and do not scan application files. The cache
    | contains logical names. The physical prefix is applied after it is loaded.
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
    | Default Logical Queue
    |--------------------------------------------------------------------------
    |
    | Jobs without an explicit queue use this logical queue. The queue must be
    | present in the topology registry when strict mode is active.
    |
    */

    'queue' => [
        'default' => env('RABBITMQ_QUEUE', 'default'),
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
        'exchange' => 'dlx',
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry and Delayed Release
    |--------------------------------------------------------------------------
    |
    | Delayed releases use durable quorum TTL queues. The package does not
    | require the RabbitMQ delayed-message plug-in.
    |
    */

    'retry' => [
        'maximum_delay' => env('RABBITMQ_MAXIMUM_DELAY', 86400),
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
        'status_file' => env('RABBITMQ_WORKER_STATUS_FILE'),
        'prefetch_count' => env('RABBITMQ_PREFETCH_COUNT', 1),
        'timeout' => 30,
        'heartbeat_sender' => env('RABBITMQ_HEARTBEAT_SENDER', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Connection Recovery
    |--------------------------------------------------------------------------
    |
    | The package closes all channels for a failed connection and then builds a
    | fresh connection. Recovery is bounded so a worker can exit for Kubernetes
    | to restart it when the broker stays unavailable.
    |
    */

    'recovery' => [
        'max_attempts' => env('RABBITMQ_RECOVERY_ATTEMPTS', 3),
        'initial_delay_ms' => env('RABBITMQ_RECOVERY_INITIAL_DELAY', 100),
        'max_delay_ms' => env('RABBITMQ_RECOVERY_MAX_DELAY', 2000),
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
        'mandatory' => env('RABBITMQ_PUBLISHER_MANDATORY', true),
        'confirm_timeout' => env('RABBITMQ_PUBLISHER_CONFIRM_TIMEOUT', 5.0),
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
    ],

    /*
    |--------------------------------------------------------------------------
    | Filament Authorization
    |--------------------------------------------------------------------------
    |
    | The optional dead-letter page denies access unless the current user can
    | use this Laravel Gate ability. Define the ability in the application
    | before the RabbitMQ Filament plugin is registered.
    |
    */

    'filament' => [
        'gate' => env('RABBITMQ_FILAMENT_GATE', 'viewRabbitMQDeadLetters'),
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
