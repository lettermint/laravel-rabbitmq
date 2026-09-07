# Laravel RabbitMQ

Laravel RabbitMQ is a Laravel queue driver that uses RabbitMQ as the message broker. It provides an explicit topology registry, mandatory publishing, publisher confirmations, delayed releases, dead-letter queues, Laravel worker behavior, and optional Filament dead-letter tools.

The driver provides at-least-once delivery. A connection failure can cause the same job to run more than once. Jobs must be idempotent. Do not use a successful dispatch call as proof that a job was processed. A successful dispatch call means that RabbitMQ confirmed the publish and did not return the message as unroutable.

## Requirements

- PHP 8.2 or later
- Laravel 11, 12, or 13
- RabbitMQ 4.2.x (broker acceptance tests use 4.2.5)
- The PHP sockets extension
- The PHP PCNTL and POSIX extensions for worker timeouts, signals, and heartbeats during long jobs
- Filament 5 only when you use the optional dead-letter page

The package does not require Redis. It does not require the RabbitMQ delayed-message plug-in.

## Install

```bash
composer require lettermint/laravel-rabbitmq
php artisan vendor:publish --tag=rabbitmq-config
```

Add a Laravel queue connection. The `driver` value must match `rabbitmq.driver_name`.

```php
// config/queue.php
'connections' => [
    'rabbitmq' => [
        'driver' => 'rabbitmq',
        'connection' => 'default',
        'queue' => 'default',
    ],
],
```

Set the driver name and the broker connection in the package configuration.

```php
// config/rabbitmq.php
'driver_name' => env('RABBITMQ_DRIVER_NAME', 'rabbitmq'),
'default' => env('RABBITMQ_CONNECTION', 'default'),
```

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
            'connection_timeout' => 30,
            'read_timeout' => 300,
            'write_timeout' => 300,
            'channel_rpc_timeout' => 0,
        ],
        'ssl' => [
            'enabled' => false,
            'verify_peer' => true,
        ],
    ],
],
```

Do not set a read timeout that is less than two heartbeat intervals.

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

## Workers

Start one consumer for one logical queue:

```bash
php artisan rabbitmq:consume events \
    --connection=rabbitmq \
    --prefetch=1 \
    --tries=3 \
    --timeout=60 \
    --backoff=10,60,300
```

The command can accept more than one queue, but one queue per worker gives clear scaling and failure isolation.

The consumer delegates job execution to Laravel `Worker`. Laravel job options remain active, including `$tries`, `$backoff`, `retryUntil()`, `$maxExceptions`, `$timeout`, maintenance mode, and queue events. Job settings take priority over the command defaults where Laravel defines that behavior.

Intentional releases use the `x-lettermint-attempt` header. RabbitMQ delivery count is separate and protects against crash loops. A worker crash, a lost connection, or an uncertain acknowledgement can cause redelivery without increasing the Laravel attempt count.

Use prefetch 1 when a KEDA deployment has one queue per worker. A higher value can improve throughput, but it also reserves more jobs in each worker and can reduce scaling accuracy.

The consumer closes and rebuilds all channels for the affected broker connection during recovery. The recovery limit applies to the full process session, including failed reconnects. The process exits with failure when it reaches that limit. A normal exit at the job, time, or memory limit returns success. The `--quiet-exit` option suppresses error text but keeps the failure exit code.

The default heartbeat sender needs PCNTL and POSIX. It keeps the broker heartbeat active while a long PHP job blocks the consumer loop. Do not disable it for long-running workers unless another process provides the same protection.

Single-active-consumer mode prevents concurrent consumption from one queue. It does not provide strict end-to-end FIFO order. A release, rejection, worker failure, or broker redelivery can change order.

## Delayed releases and retries

Retries and DLQ replay target only the originating queue, through the default exchange. This prevents a shared exchange from sending the retry to other bound queues. Payload routing metadata, message identity, and headers are retained. Initial dispatch uses the configured exchange and routing key.

Laravel releases and delayed jobs use durable quorum TTL queues with at-least-once dead-letter transfer. Each exact delay and destination uses a deterministic `delay-v2:` queue. RabbitMQ retains expired messages when the destination is unavailable. The queue has no automatic expiry. An outage can extend delivery beyond the requested delay; RabbitMQ 4.2.5 retries a failed dead-letter transfer at its configured interval, which defaults to 180 seconds.

Set the maximum accepted delay. Also set a broker virtual-host queue limit to bound queue creation; the delay limit alone does not bound queue count. Monitor queue count and creation rate. A rejected delay publish does not acknowledge the original delivery:

```php
'retry' => [
    'maximum_delay' => 86400,
],
```

The package does not use the archived delayed-message plug-in. Laravel job and worker settings control retry attempts and backoff. Legacy retry fields on `ConsumesQueue` remain only for source compatibility.

Use `rabbitmq:delay-cleanup` with explicit physical queue names to clean up drained classic delay queues. The command checks ownership and uses broker conditions for empty and unused queues. It refuses quorum queue deletion: RabbitMQ 4.2.5 does not support these deletion conditions. Retain quorum delay queues, or use a separate maintenance procedure that stops all publishers and verifies retention before deletion. There is no unsafe force option.

## Dead-letter operations

RabbitMQ is the canonical store for final failed messages. A replay publishes and confirms the replacement before it acknowledges the dead-letter message. An acknowledgement failure can cause a duplicate, but the package does not acknowledge the source before the replacement is confirmed.

Use these commands:

```bash
php artisan rabbitmq:dlq-inspect events --limit=20
php artisan rabbitmq:replay-dlq events --id=JOB_UUID
php artisan rabbitmq:replay-dlq events --limit=100 --rate=10
php artisan rabbitmq:dlq-purge events --id=JOB_UUID --dry-run
php artisan rabbitmq:dlq-purge events --id=JOB_UUID --force
```

Configure `rabbitmq.management.url` before DLQ access or strict topology audits. Management requests have bounded timeouts and use the configured CA. The management user needs read access to the queue and topology. The consumer does not need the management API.

New DLQs declare `x-delivery-limit=-1`. Protect existing DLQs with a policy restricted to their names. Verify the policy on your broker version before inspection. The actions require an effective unlimited delivery limit, reject-publish overflow, and no message or queue expiry. They wait briefly for the broker to report the applied limit. They do not recreate existing queues.

Inspection and ID search consume and requeue messages. This can change queue order. Operations close their channels on every exit so held deliveries return to RabbitMQ. Configured message, byte, result, and time limits bound scans. An incomplete search is distinct from a missing message. A bulk operation can finish with a partial result and a failure exit code.

Use `--format=json` for inspection. Replay and purge support `--json`; JSON purge requires `--force` or `--dry-run`. Each writes one JSON result. Replay stops on a failed or uncertain transfer. An expired `retryUntil` stays unchanged in the DLQ and is reported as a failure, including in dry runs. Replay is not an atomic move between queues.

### Filament

Register the optional plugin on a Filament panel:

```php
use Illuminate\Support\Facades\Gate;
use Lettermint\RabbitMQ\Filament\RabbitMQPlugin;

Gate::define('viewRabbitMQDeadLetters', function ($user): bool {
    return $user->isRabbitMQOperator();
});

return $panel->plugins([
    RabbitMQPlugin::make(),
]);
```

The page denies access unless the current user passes the `viewRabbitMQDeadLetters` Laravel Gate ability. Set `rabbitmq.filament.gate` if the application uses another ability name.

The first page shows every registered dead-letter queue and its current RabbitMQ message count. Open a queue to inspect, retry, forget, retry in bulk, or forget in bulk. The Inspect action loads one message at a time and shows its exception and payload in separate scrollable fields. The shared actions write operator audit events for inspection, replay, and purge, including incomplete or uncertain results. RabbitMQ remains the canonical dead-letter store. If Laravel has a failed-job provider, the page can read exception details from it and remove those optional details after a forget action. Replay retains the details until a later failure replaces them. The page does not require Redis or a new database migration. Dead-letter payloads can contain sensitive application data.

## Diagnostics and monitoring

Use the broker commands for deployment and runtime checks:

```bash
php artisan rabbitmq:health --json
php artisan rabbitmq:audit --strict --json
php artisan rabbitmq:probe --all --connection=rabbitmq --json
php artisan rabbitmq:test-event default --connection=rabbitmq --roundtrip --json
php artisan rabbitmq:queues --include-dlq
```

`rabbitmq:health` performs a real passive broker operation. `rabbitmq:audit` passively checks all registered exchanges, main queues, and dead-letter queues. `rabbitmq:probe` publishes a safe Laravel job to each selected logical queue. The application must collect the matching `rabbitmq.queue_probe.processed` log or `QueueProbeProcessed` event to prove end-to-end processing. `rabbitmq:test-event --roundtrip` uses a temporary isolated queue and deletes it after the test.

The package emits events for confirmed and failed publishes, releases, retries, dead lettering, replay, connection recovery, and completed probes. It also writes structured lifecycle logs with queue, job class, job ID, attempt, result, processing time, observed queue wait time, redelivery state, and broker delivery count. The lifecycle logs do not contain a job payload or exception message.

The package does not include a Prometheus exporter or a Grafana dashboard. Use the RabbitMQ Prometheus plug-in, KEDA metrics, application logs, and an error reporter such as Sentry for deployment monitoring.

Laravel queue metrics have broker limits. `size()` and `pendingSize()` report ready messages. `delayedSize()` and `reservedSize()` return zero because AMQP 0-9-1 does not expose these totals through queue declaration. `creationTimeOfOldestPendingJob()` returns `null` because the broker cannot inspect the oldest message without consuming it. Use RabbitMQ management metrics for production dashboards.

## Delivery model

The package reduces silent-loss risks with these controls:

- A strict logical queue registry
- Mandatory routing
- Publisher confirmations
- Publish-before-acknowledge release and replay operations
- Durable queues and persistent messages
- Bounded connection recovery
- Runtime topology audits
- End-to-end queue probes

These controls do not provide exactly-once delivery. Network failures and lost acknowledgements can cause duplicates. Broker durability also depends on the RabbitMQ cluster, storage, policies, and operator procedures. Test broker restarts, worker termination, delayed releases, final failures, and replay in the target environment before a production rollout.

## Development

```bash
composer test
composer analyse
composer format
RABBITMQ_USER=guest RABBITMQ_PASSWORD=guest composer test-integration
```

The integration tests need a real RabbitMQ broker.

## License

Laravel RabbitMQ is available under the MIT License.

## Upgrade and worker health

Deploy topology protection before you use the new DLQ tools. Keep old classic delay queues until they drain. New delays use different physical names, so an upgrade does not redeclare a classic queue as quorum. Keep old job classes and payload values readable while retained messages can still use them.

Set `RABBITMQ_WORKER_STATUS_FILE` to a local writable path for each worker. Remove a previous status file before process startup. `rabbitmq:worker-status --ready` checks consumer registration. Without `--ready`, it checks recent loop activity or the active job deadline. A long job remains healthy within its deadline. A job that sets its Laravel timeout to zero has no deadline check; use positive job timeouts when a liveness bound is required. Use this status for process probes; a probe must not require an available broker while a worker is in bounded recovery.

SIGTERM stops new job admission. The active job can finish within its timeout. The worker cancels consumption and closes its channel, which returns buffered or unsettled deliveries. Give the process enough termination time for the longest job timeout and bounded connection cleanup. Laravel worker restart and maintenance controls remain available.

`rabbitmq:probe --all --wait=300 --json` waits for a reply from each probe in that run. A publication alone is not completion. Run-specific IDs prevent earlier replies from satisfying a later run. The reply queue is temporary; it does not store job history.

The default connection, read, write, and channel RPC timeouts are 5, 10, 10, and 5 seconds. Keep I/O deadlines bounded when overriding them. Keep application request deadlines below job timeouts.

## Broker failure tests

The integration suite requires the pinned three-node Docker Compose fixture in `tests/Integration/docker`. Each broker has a separate test volume. Fault tests verify three online queue members before leader loss, majority loss, or network partition. Missing infrastructure fails the suite. These tests do not establish independent host or cloud-storage failure recovery, or production capacity.
