# Laravel RabbitMQ

A RabbitMQ queue driver for Laravel with native consumers, confirmed publishing, delayed jobs, retries, and dead-letter tools. An optional Filament page lets operators inspect and replay failed jobs.

Jobs, delays, and dead letters stay in RabbitMQ. The package does not require Redis, a failed-job database, or the RabbitMQ delayed-message plugin.

**Delivery is at least once.** A lost connection or acknowledgement can cause a job to run again. Make job effects safe to repeat. A confirmed publish means the broker accepted the message; it does not mean the job completed.

## Requirements

| Component | Supported versions |
|---|---|
| PHP | 8.2–8.5; Laravel 13 requires PHP 8.3 or later |
| Laravel | 11, 12, 13 |
| RabbitMQ | 4.2.x; broker tests use 4.2.5 |
| PHP extensions | `sockets`; `pcntl` and `posix` for native worker signals, timeouts, and heartbeats |
| Filament | 5, only for the optional dead-letter page |

## Quick start

Install the package and publish its configuration:

```bash
composer require lettermint/laravel-rabbitmq
php artisan vendor:publish --tag=rabbitmq-config
```

Add this entry to `connections` in `config/queue.php`:

```php
'rabbitmq' => [
    'driver' => 'rabbitmq',
    'connection' => 'default',
    'queue' => 'default',
],
```

Set the broker connection in `.env`:

```dotenv
QUEUE_CONNECTION=rabbitmq
RABBITMQ_HOST=127.0.0.1
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/
```

Use credentials for your broker. The `guest` values above are for local development.

Set `strict_topology` and `topology` in the published `config/rabbitmq.php`. This example declares one logical job queue and its dead-letter destination:

```php
'strict_topology' => true,

'topology' => [
    'exchanges' => [
        'jobs' => ['type' => 'direct'],
        'dlx' => ['type' => 'direct'],
    ],
    'queues' => [
        'default' => [
            'bindings' => ['jobs' => ['default']],
            'quorum' => true,
            'dead_letter' => true,
            'dead_letter_exchange' => 'dlx',
        ],
    ],
],
```

Declare the topology before you dispatch jobs, then start a consumer:

```bash
php artisan rabbitmq:declare --dry-run
php artisan rabbitmq:declare
php artisan rabbitmq:consume default --connection=rabbitmq --tries=3 --timeout=60
```

Dispatch your existing Laravel jobs:

```php
ProcessOrder::dispatch($orderId)->onConnection('rabbitmq');
ProcessOrder::dispatch($orderId)->onConnection('rabbitmq')->delay(now()->addSeconds(30));
```

Laravel job settings control attempts, backoff, deadlines, middleware, chains, and batches. Register each queue before using `onQueue()`. For multiple hosts, custom routing, prefixes, or topology attributes, see [configuration and routing](docs/configuration.md).

## Failed jobs

RabbitMQ dead-letter queues (DLQs) retain final failed messages. Configure `RABBITMQ_MANAGEMENT_URL` for DLQ tools and strict audits. The broker management user needs read access to the queues and topology; the native consumer does not need the management API.

```dotenv
RABBITMQ_MANAGEMENT_URL=http://127.0.0.1:15672
```

```bash
php artisan rabbitmq:dlq-inspect default --limit=20
php artisan rabbitmq:replay-dlq default --id=JOB_UUID --dry-run
php artisan rabbitmq:replay-dlq default --id=JOB_UUID
php artisan rabbitmq:dlq-purge default --id=JOB_UUID --dry-run
```

Replay confirms the replacement before acknowledging the source. An interrupted replay can leave both copies. Inspection and search are bounded; an incomplete search is reported separately from a missing message. Expired `retryUntil()` deadlines remain unchanged.

The [Filament plugin](docs/operations.md#filament) uses the same actions and requires an authorization gate. Protect existing DLQs before inspection; see [dead-letter operations](docs/operations.md#dead-letter-operations).

## Run and monitor workers

Start with one queue per worker and prefetch 1. Use positive job timeouts and allow enough shutdown time for the active job and connection cleanup. SIGTERM stops new job admission; buffered and unsettled deliveries return to RabbitMQ when the channel closes.

```bash
php artisan rabbitmq:health --json
php artisan rabbitmq:audit --strict --json
php artisan rabbitmq:probe --all --connection=rabbitmq --wait=60 --json
```

Health checks test broker access. Probes with `--wait` require replies from the current run. For process readiness and liveness, configure a separate `RABBITMQ_WORKER_STATUS_FILE` for each worker and use `rabbitmq:worker-status`.

Use broker metrics for queue state and structured lifecycle logs for job outcomes. The package includes no metric storage or monitoring dashboard. See [worker and queue operations](docs/operations.md) for shutdown, retries, delayed queues, health checks, and delivery limits.

## Upgrading

Before upgrading existing queues, read the [upgrade guide](docs/operations.md#upgrade-and-worker-health). Queue types cannot be changed in place. Retain old delay queues until they drain, protect existing DLQs, and keep old payload classes readable while messages remain queued.

## Development

See [contributing](CONTRIBUTING.md) for local tests, the three-broker fixture, and changelog automation. See [CHANGELOG.md](CHANGELOG.md) for release history.

## License

[MIT](LICENSE.md)
