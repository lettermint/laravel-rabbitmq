# Worker and queue operations

[Back to the README](../README.md)

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

Start with prefetch 1 when each worker consumes one queue. A higher value can improve throughput, but it also reserves more jobs in each worker and can reduce scaling accuracy.

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

`rabbitmq:health` performs a real passive broker operation. `rabbitmq:audit` passively checks all registered exchanges, main queues, and dead-letter queues. `rabbitmq:probe` publishes a diagnostic Laravel job to each selected logical queue. Add `--wait=60` to require completion replies for the current run. Without `--wait`, publication alone does not prove processing. `rabbitmq:test-event --roundtrip` uses a temporary isolated queue and deletes it after the test.

The package emits events for confirmed and failed publishes, releases, retries, dead lettering, replay, connection recovery, and completed probes. It also writes structured lifecycle logs with queue, job class, job ID, attempt, result, processing time, observed queue wait time, redelivery state, and broker delivery count. The lifecycle logs do not contain a job payload or exception message.

The package does not include a Prometheus exporter or a Grafana dashboard. Use broker Prometheus metrics for queue state, and application lifecycle logs for job outcomes. Keep job IDs and payload data out of metric labels.

Laravel queue metrics have broker limits. `size()` and `pendingSize()` report ready messages. `delayedSize()` and `reservedSize()` return zero because AMQP 0-9-1 does not expose these totals through queue declaration. `creationTimeOfOldestPendingJob()` returns `null` because the broker cannot inspect the oldest message without consuming it. Use RabbitMQ management metrics for production dashboards.

## Upgrade and worker health

Deploy topology protection before you use the new DLQ tools. Keep old classic delay queues until they drain. New delays use different physical names, so an upgrade does not redeclare a classic queue as quorum. Keep old job classes and payload values readable while retained messages can still use them.

Set `RABBITMQ_WORKER_STATUS_FILE` to a local writable path for each worker. Remove a previous status file before process startup. `rabbitmq:worker-status --ready` checks consumer registration. Without `--ready`, it checks recent loop activity or the active job deadline. A long job remains healthy within its deadline. A job that sets its Laravel timeout to zero has no deadline check; use positive job timeouts when a liveness bound is required. Use this status for process probes; a probe must not require an available broker while a worker is in bounded recovery.

SIGTERM stops new job admission. The active job can finish within its timeout. The worker cancels consumption and closes its channel, which returns buffered or unsettled deliveries. Give the process enough termination time for the longest job timeout and bounded connection cleanup. Laravel worker restart and maintenance controls remain available.

`rabbitmq:probe --all --wait=300 --json` waits for a reply from each probe in that run. A publication alone is not completion. Run-specific IDs prevent earlier replies from satisfying a later run. The reply queue is temporary; it does not store job history.

The default connection, read, write, and channel RPC timeouts are 5, 10, 10, and 5 seconds. Keep I/O deadlines bounded when overriding them. Keep application request deadlines below job timeouts.

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
