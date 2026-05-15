# Laravel RabbitMQ

[![Latest Version on Packagist](https://img.shields.io/packagist/v/lettermint/laravel-rabbitmq.svg?style=flat-square)](https://packagist.org/packages/lettermint/laravel-rabbitmq)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/lettermint/laravel-rabbitmq/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/lettermint/laravel-rabbitmq/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/lettermint/laravel-rabbitmq/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/lettermint/laravel-rabbitmq/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/lettermint/laravel-rabbitmq.svg?style=flat-square)](https://packagist.org/packages/lettermint/laravel-rabbitmq)

**A production-ready RabbitMQ queue driver for Laravel with attribute-based topology, automatic retries, and Kubernetes-native deployment.**

Build resilient, scalable queue systems using RabbitMQ's powerful routing with Laravel's familiar job syntax. No Horizon required.

## ✨ Features

- 🎯 **Attribute-Based Topology** - Define exchanges and queues using PHP 8 attributes on your job classes
- 🔄 **Automatic Retries & DLQ** - Built-in dead letter queues with configurable retry strategies
- 📊 **Priority Queues** - Support for message priorities (0-255) on classic queues
- ⏰ **Delayed Messages** - Schedule jobs with native RabbitMQ delayed message exchange
- 🚀 **Laravel-Native** - Works with standard `dispatch()` - no learning curve
- ☸️ **Kubernetes-Ready** - Custom consumer commands designed for containerized deployments
- 💪 **Production-Proven** - Built on php-amqplib with heartbeat support and publisher confirms

## 🤔 Why Use RabbitMQ?

This package is ideal for applications that need:

- **Advanced Routing** - Route messages based on patterns, headers, or broadcast to multiple queues
- **Guaranteed Delivery** - RabbitMQ's persistence and publisher confirms ensure messages aren't lost
- **Complex Workflows** - Multi-tenant systems, event-driven architectures, microservices communication
- **Infrastructure-Level Control** - Manage queue topology, clustering, and federation through RabbitMQ itself
- **Kubernetes-Native Workers** - Deploy queue consumers as standard Kubernetes Deployments with HPA
- **Protocol Flexibility** - AMQP protocol support for cross-platform messaging (Node.js, Python, Go, etc.)

## 📋 Requirements

- PHP 8.2+
- Laravel 11.0, 12.0, or 13.0
- RabbitMQ 3.12+
- php-amqplib/php-amqplib ^3.6

**Optional:**
- `rabbitmq_delayed_message_exchange` plugin for delayed messages
- `rabbitmq_prometheus` plugin for Prometheus metrics

## 📦 Installation

```bash
composer require lettermint/laravel-rabbitmq
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=rabbitmq-config
```

Update your `config/queue.php`:

```php
'connections' => [
    'rabbitmq' => [
        'driver' => 'rabbitmq',
        'queue' => env('RABBITMQ_QUEUE', 'default'),
        'exchange' => env('RABBITMQ_EXCHANGE', ''),
    ],
],

// Set as default if desired
'default' => env('QUEUE_CONNECTION', 'rabbitmq'),
```

Add to your `.env`:

```env
QUEUE_CONNECTION=rabbitmq
RABBITMQ_HOST=localhost
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/
```

## 🚀 Quick Start

Here's a complete example - from defining a job to processing it:

### 1. Define Your Job

```php
<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Lettermint\RabbitMQ\Attributes\ConsumesQueue;

#[ConsumesQueue(
    queue: 'emails',
    bindings: ['notifications' => 'email.*'],  // Listens to notifications exchange
    quorum: true,                               // High availability queue
    retryAttempts: 3,                           // Retry up to 3 times
)]
class SendEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public function __construct(
        public string $email,
        public string $subject,
        public string $message,
    ) {}

    public function handle(): void
    {
        // Send your email
        Mail::to($this->email)->send(new NotificationMail($this->subject, $this->message));
    }
}
```

### 2. Declare Topology

```bash
# Create the exchange, queue, and bindings in RabbitMQ
php artisan rabbitmq:declare
```

### 3. Dispatch & Consume

```php
// Dispatch the job (from anywhere in your app)
SendEmailJob::dispatch('user@example.com', 'Welcome!', 'Thanks for signing up');
```

```bash
# Start consuming (in production, run this in a container/supervisor)
php artisan rabbitmq:consume emails
```

That's it! Your job will be routed through RabbitMQ and processed with automatic retries and dead letter handling.

> **💡 Tip:** In production, run consumers as Kubernetes Deployments or supervisor processes.

## 📚 Core Concepts

Understanding these RabbitMQ concepts will help you use this package effectively:

### Exchange → Binding → Queue Flow

```
┌─────────────┐    routing key: email.welcome     ┌──────────────┐
│   Producer  │──────────────────────────────────►│   Exchange   │
└─────────────┘                                   │ "notifications"│
                                                  └───────┬────────┘
                                                          │ binding: email.*
                                                          ▼
                                                  ┌──────────────┐
                                                  │    Queue     │
                                                  │   "emails"   │
                                                  └───────┬──────┘
                                                          │
                                                          ▼
                                                  ┌──────────────┐
                                                  │   Consumer   │
                                                  │ (Your Job)   │
                                                  └──────────────┘
```

- **Exchange**: Routes messages based on routing keys (like a post office)
- **Queue**: Stores messages until consumed (like a mailbox)
- **Binding**: Routing rule connecting exchange to queue (e.g., `email.*` matches `email.welcome`)
- **Routing Key**: Label on each message determining which queue(s) receive it

### Attribute-Based Configuration

Instead of manually configuring exchanges and queues in RabbitMQ, define them with attributes:

```php
// This attribute tells the package:
// 1. Create a queue named "emails"
// 2. Create a quorum queue (HA, durable)
// 3. Bind it to the "notifications" exchange with pattern "email.*"
// 4. Set up DLQ with 3 retry attempts
#[ConsumesQueue(
    queue: 'emails',
    bindings: ['notifications' => 'email.*'],
    quorum: true,
    retryAttempts: 3,
)]
```

When you run `php artisan rabbitmq:declare`, the package scans your job classes and creates everything automatically.

## 📖 Usage Examples

### Basic Usage

**Simple job with a queue:**

```php
#[ConsumesQueue(
    queue: 'default',
    bindings: ['tasks' => '#'],  // Catch all messages from 'tasks' exchange
)]
class ProcessTaskJob implements ShouldQueue
{
    public function handle(): void
    {
        // Process the task
    }
}
```

**Creating an exchange** (optional - useful for organization):

```php
<?php

namespace App\RabbitMQ\Exchanges;

use Lettermint\RabbitMQ\Attributes\Exchange;
use Lettermint\RabbitMQ\Enums\ExchangeType;

#[Exchange(name: 'tasks', type: ExchangeType::Topic)]
class TasksExchange {}
```

### Intermediate Usage

**Priority queues** for time-sensitive jobs:

```php
use Lettermint\RabbitMQ\Contracts\HasPriority;

#[ConsumesQueue(
    queue: 'urgent-tasks',
    bindings: ['tasks' => 'urgent.*'],
    quorum: false,        // Priority requires classic queue
    maxPriority: 10,      // 0 = lowest, 10 = highest
)]
class UrgentTaskJob implements ShouldQueue, HasPriority
{
    public function __construct(
        public string $taskId,
        public int $priority = 5,
    ) {}

    public function getPriority(): int
    {
        return $this->priority;
    }
}

// Dispatch with high priority
UrgentTaskJob::dispatch($taskId, priority: 10);
```

**Delayed/scheduled messages:**

```php
// Requires rabbitmq_delayed_message_exchange plugin
// Enable in config/rabbitmq.php: 'delayed.enabled' => true

// Delay by seconds
SendEmailJob::dispatch($email)->delay(300);  // 5 minutes

// Delay with Carbon
SendEmailJob::dispatch($email)->delay(now()->addHours(2));

// Schedule for specific time
SendEmailJob::dispatch($email)->delay(now()->tomorrow()->setHour(9));
```

### Advanced Usage

**Multiple bindings** (listen to multiple routing patterns):

```php
#[ConsumesQueue(
    queue: 'notifications',
    bindings: [
        'events' => ['user.created', 'user.updated'],
        'alerts' => 'critical.*',
    ],
)]
```

**Exchange-to-exchange binding** (hierarchical routing):

```php
// Parent exchange
#[Exchange(name: 'events', type: ExchangeType::Topic)]
class EventsExchange {}

// Child exchange bound to parent
#[Exchange(
    name: 'user-events',
    type: ExchangeType::Topic,
    bindTo: 'events',
    bindRoutingKey: 'user.#',
)]
class UserEventsExchange {}
```

**Custom retry strategy:**

```php
use Lettermint\RabbitMQ\Enums\RetryStrategy;

#[ConsumesQueue(
    queue: 'api-calls',
    bindings: ['tasks' => 'api.*'],
    retryAttempts: 5,
    retryStrategy: RetryStrategy::Exponential,
    retryDelays: [30, 60, 300, 900, 3600],  // 30s, 1m, 5m, 15m, 1h
)]
```

**Repeatable attribute** (one job, multiple queues):

```php
// This job can be consumed from either queue
#[ConsumesQueue(queue: 'primary', bindings: ['tasks' => 'important.*'])]
#[ConsumesQueue(queue: 'secondary', bindings: ['tasks' => 'background.*'])]
class FlexibleJob implements ShouldQueue
{
    // ...
}
```

## ⚙️ Configuration Reference

### Environment Variables

```env
# Connection
RABBITMQ_HOST=localhost
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/

# Behavior
RABBITMQ_HEARTBEAT=60
RABBITMQ_PREFETCH_COUNT=10
RABBITMQ_DELAYED_ENABLED=true

# Publisher
RABBITMQ_PUBLISHER_CONFIRM=true
```

### Attribute: `#[Exchange]`

```php
#[Exchange(
    name: 'events',                    // Required: Exchange name
    type: ExchangeType::Topic,         // topic, direct, fanout, headers, x-delayed-message
    durable: true,                     // Survive broker restart
    autoDelete: false,                 // Delete when no bindings
    internal: false,                   // Only accessible via e2e bindings
    bindTo: 'parent-exchange',         // Parent exchange for e2e binding
    bindRoutingKey: 'events.#',        // Routing pattern for parent
    arguments: [],                     // Custom exchange arguments
)]
```

**Exchange Types:**
- `Topic`: Pattern-based routing (e.g., `user.*.created`)
- `Direct`: Exact routing key match
- `Fanout`: Broadcast to all bound queues
- `Headers`: Route by message headers
- `DelayedMessage`: Delayed delivery (requires plugin)

### Attribute: `#[ConsumesQueue]`

```php
#[ConsumesQueue(
    // Required
    queue: 'my-queue',                           // Queue name

    // Bindings
    bindings: [                                  // Exchange => routing key(s)
        'exchange-name' => 'routing.key',
        'other-exchange' => ['key1', 'key2'],
    ],

    // Queue type (choose one)
    quorum: true,                                // Quorum queue (HA, recommended)
    maxPriority: 10,                             // Classic with priority (quorum: false)

    // Limits
    messageTtl: 86400000,                        // Message TTL in ms (24h)
    maxLength: 1000000,                          // Max queue length
    overflow: OverflowBehavior::RejectPublishDlx, // Overflow behavior

    // Dead letter & retry
    dlqExchange: null,                           // Custom DLQ exchange (auto-derived if null)
    retryAttempts: 3,                            // Max retries before permanent DLQ
    retryStrategy: RetryStrategy::Exponential,   // exponential, fixed, linear
    retryDelays: [60, 300, 900],                 // Delays in seconds

    // Consumer settings
    prefetch: 10,                                // Messages to prefetch (QoS)
    timeout: 30,                                 // Job timeout in seconds
)]
```

**Important Notes:**
- Quorum queues provide high availability but don't support priorities
- Use classic queues (`quorum: false`) if you need `maxPriority`
- DLQ exchange is auto-created based on your first binding exchange

### Config File Options

See `config/rabbitmq.php` for full options. Key settings:

```php
// Discovery paths (where to scan for attributes)
'discovery' => [
    'paths' => [
        app_path('Jobs'),
        app_path('RabbitMQ'),
    ],
],

// Default queue settings (for jobs without attributes)
'queue' => [
    'exchange' => '',  // Fallback exchange
],

// Delayed messages
'delayed' => [
    'enabled' => true,
    'max_delay' => 86400000,  // 24 hours max
],
```

## 🎮 Artisan Commands

### Topology Management

```bash
# Declare all exchanges, queues, and bindings
php artisan rabbitmq:declare

# Preview what will be created (dry run)
php artisan rabbitmq:declare --dry-run

# View topology as tree
php artisan rabbitmq:topology

# Export topology as JSON
php artisan rabbitmq:topology --format=json
```

### Queue Operations

```bash
# List all queues with stats
php artisan rabbitmq:queues

# Include DLQ queues in list
php artisan rabbitmq:queues --include-dlq

# Watch mode (updates every 2s)
php artisan rabbitmq:queues --watch

# Purge a queue (delete all messages)
php artisan rabbitmq:purge my-queue
```

### Diagnostics

```bash
# Publish a diagnostic event to a queue
php artisan rabbitmq:test-event diagnostics

# Publish custom JSON-friendly content
php artisan rabbitmq:test-event diagnostics --message="hello"

# Publish to a temporary queue and consume it back to verify round-trip flow
php artisan rabbitmq:test-event --roundtrip

# Machine-readable output for CI or deployment checks
php artisan rabbitmq:test-event --roundtrip --json
```

### Consumer

```bash
# Start consuming from a queue
php artisan rabbitmq:consume my-queue

# With custom settings
php artisan rabbitmq:consume my-queue \
    --prefetch=25 \
    --timeout=120 \
    --max-jobs=500 \
    --max-memory=256

# Stop when empty (useful for testing)
php artisan rabbitmq:consume my-queue --stop-when-empty
```

**Consumer Options:**
- `--prefetch`: Messages to prefetch (default: 10)
- `--timeout`: Job timeout in seconds (default: 60)
- `--max-jobs`: Exit after N jobs (0 = unlimited)
- `--max-time`: Exit after N seconds (0 = unlimited)
- `--max-memory`: Exit if memory exceeds N MB (default: 128)
- `--stop-when-empty`: Exit when queue is empty

### Dead Letter Queue Operations

```bash
# Replay DLQ messages back to original queue
php artisan rabbitmq:replay-dlq my-queue

# Preview replay without moving messages
php artisan rabbitmq:replay-dlq my-queue --dry-run

# Limit number of messages to replay
php artisan rabbitmq:replay-dlq my-queue --limit=100

# Inspect DLQ messages without removing them
php artisan rabbitmq:dlq-inspect my-queue

# Inspect specific message
php artisan rabbitmq:dlq-inspect my-queue --id=message-uuid

# Limit number of messages shown
php artisan rabbitmq:dlq-inspect my-queue --limit=20

# JSON output
php artisan rabbitmq:dlq-inspect my-queue --format=json

# Purge DLQ messages (permanently delete)
php artisan rabbitmq:dlq-purge my-queue

# Purge specific message
php artisan rabbitmq:dlq-purge my-queue --id=message-uuid

# Purge old messages only
php artisan rabbitmq:dlq-purge my-queue --older-than=7d

# Preview without deleting
php artisan rabbitmq:dlq-purge my-queue --dry-run

# Skip confirmation
php artisan rabbitmq:dlq-purge my-queue --force
```

### Laravel Failed Job Commands

If you want Laravel's built-in failed-job commands to read from RabbitMQ DLQs,
configure `config/queue.php` to use the RabbitMQ DLQ failed-job provider:

```php
'failed' => [
    'driver' => 'rabbitmq-dlq',
],
```

After that, these standard commands operate on RabbitMQ DLQ messages:

```bash
php artisan queue:failed
php artisan queue:retry message-uuid
php artisan queue:retry all --queue=my-queue
php artisan queue:forget message-uuid
php artisan queue:flush
```

Listing and finding failed jobs temporarily reads messages from DLQs and
immediately requeues them. `queue:retry` republishes the payload to the original
queue, then removes the matching DLQ message via `queue:forget`.

### Optional Filament Page

If your application uses Filament, this package can publish an app-owned page
for inspecting DLQ payloads and retrying or forgetting failed jobs:

```bash
php artisan rabbitmq:install-filament
```

Then register the generated page in your Filament panel provider:

```php
use App\Filament\Pages\RabbitMQFailedJobs;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->pages([
            RabbitMQFailedJobs::class,
        ]);
}
```

The generated page requires the Laravel failed-job provider integration above:

```php
'failed' => [
    'driver' => 'rabbitmq-dlq',
],
```

The installer detects Filament before writing files. Use `--dry-run` to preview
paths and `--force` to overwrite existing generated files.

### Monitoring

```bash
# Health check
php artisan rabbitmq:health

# JSON output (for monitoring tools)
php artisan rabbitmq:health --json
```

## 🔄 Dead Letter Queues

DLQs are automatically created for every queue to handle failed messages.

### How It Works

```
┌──────────────┐
│ Original Queue│  Job fails or times out
│  "emails"    │─────────────┐
└──────────────┘             │
                             ▼
                     ┌──────────────┐
                     │  DLQ Exchange│
                     │ "notifications.dlq"
                     └───────┬──────┘
                             │
                             ▼
                     ┌──────────────┐
                     │   DLQ Queue  │  Retry after delay
                     │ "dlq:emails" │─────────────┐
                     └──────────────┘             │
                                                  │
         ┌────────────────────────────────────────┘
         │
         ▼
┌──────────────┐
│ Original Queue│  If retries exhausted → stays in DLQ
│  "emails"    │
└──────────────┘
```

### Retry Strategies

**Exponential** (recommended for API calls):
```php
retryStrategy: RetryStrategy::Exponential,
retryDelays: [60, 300, 900],  // 1m, 5m, 15m, then 15m for remaining
```

**Fixed** (same delay every time):
```php
retryStrategy: RetryStrategy::Fixed,
retryDelays: [300],  // Always 5 minutes
```

**Linear** (increasing delay):
```php
retryStrategy: RetryStrategy::Linear,
retryDelays: [60],  // 1m, 2m, 3m, 4m...
```

### Messages Go to DLQ When:

1. Job throws unhandled exception (after retries)
2. Job exceeds timeout
3. Consumer rejects without requeue
4. Queue message TTL expires
5. Queue max-length exceeded (with `overflow: RejectPublishDlx`)

## ☸️ Kubernetes Deployment

Deploy workers as Kubernetes Deployments for automatic scaling and restarts.

### Basic Worker Deployment

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: queue-worker-emails
spec:
  replicas: 3
  selector:
    matchLabels:
      app: queue-worker
      queue: emails
  template:
    metadata:
      labels:
        app: queue-worker
        queue: emails
    spec:
      containers:
        - name: worker
          image: your-app:latest
          command: ["php", "artisan", "rabbitmq:consume", "emails"]
          args:
            - "--prefetch=25"
            - "--max-jobs=500"
            - "--max-memory=256"
          env:
            - name: RABBITMQ_HOST
              value: "rabbitmq.default.svc.cluster.local"
            - name: RABBITMQ_USER
              valueFrom:
                secretKeyRef:
                  name: rabbitmq-credentials
                  key: username
            - name: RABBITMQ_PASSWORD
              valueFrom:
                secretKeyRef:
                  name: rabbitmq-credentials
                  key: password
          resources:
            requests:
              memory: "128Mi"
              cpu: "100m"
            limits:
              memory: "512Mi"
              cpu: "500m"
```

### KEDA Scaling

Scale worker Deployments directly from RabbitMQ queue depth with KEDA:

```yaml
apiVersion: keda.sh/v1alpha1
kind: TriggerAuthentication
metadata:
  name: rabbitmq-trigger-auth
spec:
  secretTargetRef:
    - parameter: host
      name: rabbitmq-credentials
      key: amqp-uri
---
apiVersion: keda.sh/v1alpha1
kind: ScaledObject
metadata:
  name: queue-worker-emails
spec:
  scaleTargetRef:
    name: queue-worker-emails
  pollingInterval: 10
  cooldownPeriod: 60
  minReplicaCount: 0
  maxReplicaCount: 20
  triggers:
    - type: rabbitmq
      metadata:
        protocol: amqp
        queueName: emails
        mode: QueueLength
        value: "100"
      authenticationRef:
        name: rabbitmq-trigger-auth
```

### Best Practices

- Set `--max-jobs` to restart workers periodically (prevents memory leaks)
- Set `--max-memory` slightly below container limits
- Use `livenessProbe` and `readinessProbe` for health checks
- Run `rabbitmq:declare` in init container or CI/CD pipeline
- Use `PodDisruptionBudget` to maintain availability during updates
- Use `--stop-when-empty` for scale-to-zero worker Jobs, or leave it off for
  long-running Deployments managed by KEDA

## 🔧 Advanced Topics

### Fallback Routing for Third-Party Jobs

Jobs without `#[ConsumesQueue]` (e.g., from packages) use fallback routing:

**Routing:** `config('rabbitmq.queue.exchange')` with key `'fallback.{queue_name}'`

Create a catch-all queue for these:

```php
#[ConsumesQueue(
    queue: 'fallback',
    bindings: ['your-exchange' => 'fallback.#'],
)]
class FallbackJob implements ShouldQueue {}
```

### Quorum vs Classic Queues

**Use Quorum Queues (default) when:**
- You need high availability (HA)
- Data durability is critical
- Running in clustered RabbitMQ

**Use Classic Queues when:**
- You need message priorities
- You need very low latency (single-node)
- Legacy compatibility required

**Cannot combine:** `quorum: true` and `maxPriority` are mutually exclusive.

### Publisher Confirms

Publisher confirms ensure messages reach RabbitMQ successfully. Enabled by default:

```php
'publisher' => [
    'confirm' => true,  // Wait for RabbitMQ acknowledgment
],
```

If confirm fails, Laravel throws an exception and the job can be retried by your queue worker.

### php-fpm and Octane Lifecycle

The package caches AMQP channels and connections for normal request performance,
then closes resolved RabbitMQ resources during Laravel application termination.
That covers php-fpm request shutdown and CLI command shutdown.

When Laravel Octane is installed, the service provider also listens for Octane
request/task termination and worker-stopping events. By default it flushes AMQP
connections after each Octane request to avoid leaking channel state across
long-lived FrankenPHP, RoadRunner, or Swoole workers:

```env
RABBITMQ_OCTANE_FLUSH_CONNECTIONS=true
```

Set this to `false` only if you intentionally want to keep AMQP connections open
across Octane requests and are prepared to handle broker/network restarts at the
application level.

### Heartbeats & Long-Running Jobs

The package sends heartbeats automatically during job execution to prevent connection timeouts.

**For jobs longer than 2× heartbeat interval:**
- Heartbeats work automatically with `ext-pcntl`
- If job has `$timeout` property, heartbeats are disabled during execution (both use `SIGALRM`)
- For long jobs needing heartbeat: set `public $timeout = 0;` on the job class

## 🐛 Troubleshooting

### Connection Issues

**Problem:** `AMQPConnectionException: Connection refused`

**Solutions:**
- Verify RabbitMQ is running: `docker ps` or `systemctl status rabbitmq-server`
- Check connection details in `.env` match your RabbitMQ instance
- Ensure firewall allows port 5672
- Test connection: `telnet rabbitmq-host 5672`

### Messages Not Routing

**Problem:** Messages published but not appearing in queue

**Solutions:**
- Run `php artisan rabbitmq:topology` to verify bindings
- Check routing key matches binding pattern:
  - `email.*` matches `email.welcome` but not `email.welcome.urgent`
  - `email.#` matches `email.welcome.urgent`
- Verify exchange and queue were declared: `php artisan rabbitmq:declare`
- Check RabbitMQ management UI (port 15672) for unrouted messages

### Consumer Stops Unexpectedly

**Problem:** `rabbitmq:consume` exits without error

**Solutions:**
- Check memory limit: `--max-memory=256` (increase if needed)
- Check job limit: `--max-jobs=500` (consumer exits after N jobs by design)
- Check time limit: `--max-time=3600` (consumer exits after N seconds)
- Review logs for connection errors or exceptions
- Verify heartbeat settings if jobs run longer than 2× heartbeat interval

### Jobs Fail Silently

**Problem:** Jobs marked as processed but work not completed

**Solutions:**
- Check your job's `handle()` method for unhandled exceptions
- Enable Laravel failed job integration with `queue.failed.driver = rabbitmq-dlq`,
  then run `php artisan queue:failed`
- Review RabbitMQ DLQ: `php artisan rabbitmq:dlq-inspect your-queue`
- Add logging to job: `Log::info('Job started', ['id' => $this->id]);`

### Priority Not Working

**Problem:** High priority messages not processed first

**Solutions:**
- Verify `quorum: false` (quorum queues don't support priority)
- Verify `maxPriority` is set on queue attribute
- Ensure job implements `HasPriority` interface
- Check messages have priority set before prefetched messages processed

### Delayed Messages Not Working

**Problem:** `->delay()` doesn't delay message

**Solutions:**
- Install plugin: `rabbitmq-plugins enable rabbitmq_delayed_message_exchange`
- Verify enabled in config: `'delayed.enabled' => true`
- Run `php artisan rabbitmq:declare` to create delayed exchange
- Check delay is within max: default 24 hours (`delayed.max_delay`)

### High Memory Usage

**Problem:** Worker memory grows over time

**Solutions:**
- Set `--max-memory=256` to restart worker before OOM
- Set `--max-jobs=500` to periodically restart workers
- Check for memory leaks in job code
- Ensure job releases large objects: `unset($largeVariable);`
- Use `--max-time=3600` for time-based restarts

## 🤝 Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## 📄 License

MIT License. See [LICENSE](LICENSE) for details.
