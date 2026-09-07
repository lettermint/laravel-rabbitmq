# Configuration and routing

[Back to the README](../README.md)

## Broker connection

Configure one or more broker hosts. The package tries each host in order. It uses a bounded connection recovery process after a connection failure.

```php
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
            'heartbeat' => 60,
            'connection_timeout' => 5,
            'read_timeout' => 10,
            'write_timeout' => 10,
            'channel_rpc_timeout' => 5,
        ],
        'ssl' => [
            'enabled' => false,
            'verify_peer' => true,
        ],
    ],
],
```

The connection, read, write, and channel RPC defaults are 5, 10, 10, and 5 seconds. Keep these limits finite. Include connection cleanup time when you set the total process deadline. The heartbeat sender keeps heartbeats active during long jobs. See [worker operation](operations.md#workers).

## Explicit topology

Use one canonical queue registry. Strict mode rejects a dispatch to a logical queue that is not in this registry. A physical prefix lets two deployments use separate broker objects in the same virtual host.

```php
'physical_prefix' => env('RABBITMQ_PHYSICAL_PREFIX', ''),
'strict_topology' => env('RABBITMQ_STRICT_TOPOLOGY', true),

'topology' => [
    'exchanges' => [
        'jobs' => ['type' => 'topic'],
        'dlx' => ['type' => 'direct'],
    ],
    'queues' => [
        'default' => [
            'bindings' => [
                'jobs' => ['default'],
            ],
            'quorum' => true,
            'delivery_limit' => 20,
            'dead_letter' => true,
            'dead_letter_exchange' => 'dlx',
        ],
        'events' => [
            'bindings' => [
                'jobs' => ['events.#'],
            ],
            'quorum' => true,
            'single_active_consumer' => false,
            'delivery_limit' => 20,
            'max_length' => 100000,
            'max_length_bytes' => 1073741824,
            'dead_letter' => true,
            'dead_letter_exchange' => 'dlx',
        ],
    ],
],
```

The registry supports direct, topic, and fanout exchanges. It rejects headers exchanges and `x-delayed-message` exchanges. It also rejects invalid names, physical-name conflicts, unknown exchange bindings, empty publish keys, wildcard publish keys, and publish keys that do not match the registered queue bindings.

Main queues and final dead-letter queues are durable quorum queues by default. A quorum queue uses `reject-publish`. When dead lettering is active, it also uses RabbitMQ at-least-once dead lettering. The final dead-letter queue has no default message TTL.

RabbitMQ queue arguments are immutable. A change to a queue type, delivery limit, length limit, priority, message TTL, or single-active-consumer setting can require a controlled queue replacement. Test a topology change before you apply it to an existing broker.

Declare the topology before producers or workers start:

```bash
php artisan rabbitmq:declare --dry-run
php artisan rabbitmq:declare
```

Use `rabbitmq:topology --format=json` as normalized input for CI checks.

### Attribute compatibility

The `#[Exchange]` and `#[ConsumesQueue]` attributes remain available. Compile them during the application build:

```bash
php artisan rabbitmq:cache
php artisan rabbitmq:cache --check
```

The cache contains logical topology. The package applies the physical prefix when it loads the cache. The same application image can therefore use a different prefix in each environment. An explicit `topology.queues` configuration takes priority over the attribute cache.

Web requests load the compiled cache and do not scan application files. Console commands scan attributes when no compiled cache or explicit queue registry exists.

Strict mode accepts an explicit registry or a compiled attribute cache. In non-strict mode, an unknown queue uses the RabbitMQ default exchange. The package emits an `UnknownQueueFallbackUsed` event and a structured `rabbitmq.queue.fallback_used` warning. Mandatory publishing and publisher confirms still make a missing physical queue visible as a publish failure.

`rabbitmq:cache` and `rabbitmq:declare` are additive. They do not delete a queue, exchange, or binding that is no longer present in the application. Remove broker topology only through a separate, controlled operation.

## Dispatch and routing

Laravel queue APIs work with the driver:

```php
ProcessEvent::dispatch($event)->onConnection('rabbitmq')->onQueue('events');
```

Jobs without `onQueue()` use the configured default logical queue. Register that queue before you enable strict mode.

Use `HasRoutingKey` only when one logical queue has more than one valid route:

```php
use Lettermint\RabbitMQ\Contracts\HasRoutingKey;

final class ProcessEvent implements HasRoutingKey
{
    public function getRoutingKey(): string
    {
        return 'events.account.created';
    }
}
```

The routing key is stored in the Laravel payload. A release or dead-letter replay keeps the same routing data. The package also keeps the AMQP message ID, correlation ID, timestamp, headers, priority, payload, and other message properties.

Publisher confirmations and mandatory routing cannot be disabled. The package treats a returned message, a negative confirmation, and a confirmation timeout as a dispatch failure. A timeout has an uncertain result: RabbitMQ can have the message even though the dispatch call failed. The application must use an idempotency key when it retries such a dispatch.

`pushBatch()` publishes and confirms messages in order. It is not an atomic operation. A later publish can fail after earlier messages were confirmed.
