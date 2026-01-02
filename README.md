# Laravel RabbitMQ

[![Latest Version on Packagist](https://img.shields.io/packagist/v/lettermint/laravel-rabbitmq.svg?style=flat-square)](https://packagist.org/packages/lettermint/laravel-rabbitmq)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/lettermint/laravel-rabbitmq/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/lettermint/laravel-rabbitmq/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/lettermint/laravel-rabbitmq/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/lettermint/laravel-rabbitmq/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/lettermint/laravel-rabbitmq.svg?style=flat-square)](https://packagist.org/packages/lettermint/laravel-rabbitmq)

A modern RabbitMQ queue driver for Laravel using `php-amqplib` with attribute-based topology declaration, dead letter queues, and advanced retry strategies.

## Features

- **Attribute-Based Topology**: Define exchanges, queues, and bindings using PHP 8 attributes
- **Dead Letter Queues**: Automatic DLQ creation and retry handling
- **Priority Queues**: Support for message priorities (0-255)
- **Delayed Messages**: Support for delayed/scheduled messages via plugin
- **Laravel Integration**: Works with standard `dispatch()` patterns
- **No Horizon Required**: Custom consumer commands with Kubernetes-ready deployment
- **Monitoring**: Health checks and queue statistics

## Requirements

- PHP 8.2+
- Laravel 11.0+
- RabbitMQ 3.12+
- php-amqplib/php-amqplib ^3.6

### Optional

- `rabbitmq_delayed_message_exchange` plugin for delayed messages
- `rabbitmq_prometheus` plugin for Prometheus metrics

## Installation

```bash
composer require lettermint/laravel-rabbitmq
```

Publish the configuration:

```bash
php artisan vendor:publish --tag=rabbitmq-config
```

## Quick Start

### 1. Define an Exchange

Create exchange classes in `app/RabbitMQ/Exchanges/`:

```php
<?php

namespace App\RabbitMQ\Exchanges;

use Lettermint\RabbitMQ\Attributes\Exchange;

#[Exchange(name: 'messages', type: 'topic')]
class MessagesExchange {}
```

### 2. Define a Job with Queue Configuration

Add the `ConsumesQueue` attribute to your job:

```php
<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Lettermint\RabbitMQ\Attributes\ConsumesQueue;

#[ConsumesQueue(
    queue: 'jobs:high-priority',
    bindings: ['messages' => 'priority.high.*'],
    quorum: true,
    maxPriority: 10,
    retryAttempts: 3,
    retryDelays: [60, 300, 900],
    prefetch: 25,
    timeout: 120,
)]
class ProcessTask implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public function __construct(
        public string $taskId,
    ) {}

    public function handle(): void
    {
        // Process the task
    }
}
```

### 3. Declare Topology

```bash
# Preview what will be created
php artisan rabbitmq:declare --dry-run

# Create exchanges, queues, and bindings
php artisan rabbitmq:declare
```

### 4. Dispatch Jobs

Jobs dispatch exactly like standard Laravel jobs:

```php
ProcessTask::dispatch($taskId);

// With priority (implement HasPriority interface)
ProcessTask::dispatch($taskId, priority: 10);

// Delayed
ProcessTask::dispatch($taskId)->delay(now()->addMinutes(5));
```

### 5. Start Consuming

```bash
php artisan rabbitmq:consume jobs:high-priority
```

## Configuration

### Environment Variables

```env
RABBITMQ_HOST=localhost
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/
```

### Queue Connection

Add to `config/queue.php`:

```php
'connections' => [
    'rabbitmq' => [
        'driver' => 'rabbitmq',
        'queue' => env('RABBITMQ_QUEUE', 'default'),
        'exchange' => env('RABBITMQ_EXCHANGE', ''),
    ],
],
```

## Attributes Reference

### `#[Exchange]`

Defines a RabbitMQ exchange.

```php
#[Exchange(
    name: 'messages',               // Exchange name
    type: 'topic',                  // topic, direct, fanout, headers, x-delayed-message
    durable: true,                  // Survive broker restart
    autoDelete: false,              // Delete when no bindings
    internal: false,                // Only accessible via e2e bindings
    bindTo: 'app',                  // Parent exchange for e2e binding
    bindRoutingKey: 'messages.#',   // Routing key for parent binding
    arguments: [],                  // Additional arguments
)]
```

### `#[ConsumesQueue]`

Defines queue configuration on a job class.

```php
#[ConsumesQueue(
    // Queue identity
    queue: 'jobs:high-priority',

    // Bindings: exchange => routing_key(s)
    bindings: [
        'messages' => ['priority.high.*', 'urgent.*'],
    ],

    // Queue settings
    quorum: true,              // Use quorum queue (recommended)
    maxPriority: 10,           // Enable priority (0-255)
    messageTtl: 86400000,      // Message TTL in ms (24 hours)
    maxLength: 1000000,        // Max queue length
    overflow: 'reject-publish-dlx',  // Overflow behavior

    // Dead letter settings
    dlqExchange: null,         // Auto-derived from binding domain
    retryAttempts: 3,          // Max retries before permanent DLQ
    retryStrategy: 'exponential',  // exponential, fixed, linear
    retryDelays: [60, 300, 900, 3600],  // Delays in seconds

    // Consumer settings
    prefetch: 10,              // Messages to prefetch
    timeout: 30,               // Job timeout in seconds
)]
```

## Artisan Commands

### Topology Management

```bash
# Declare all topology
php artisan rabbitmq:declare

# Preview changes
php artisan rabbitmq:declare --dry-run

# Show topology tree
php artisan rabbitmq:topology

# Export as JSON
php artisan rabbitmq:topology --format=json
```

### Queue Operations

```bash
# List queues with stats
php artisan rabbitmq:queues

# Include DLQ queues
php artisan rabbitmq:queues --include-dlq

# Watch mode
php artisan rabbitmq:queues --watch

# Purge a queue
php artisan rabbitmq:purge jobs:high-priority
```

### Consumer

```bash
# Start consumer
php artisan rabbitmq:consume jobs:high-priority

# With options
php artisan rabbitmq:consume jobs:high-priority \
    --prefetch=25 \
    --timeout=120 \
    --max-jobs=500 \
    --max-memory=256
```

### Dead Letter Queue

```bash
# Replay DLQ messages
php artisan rabbitmq:replay-dlq jobs:high-priority

# Preview without moving
php artisan rabbitmq:replay-dlq jobs:high-priority --dry-run

# Limit replay count
php artisan rabbitmq:replay-dlq jobs:high-priority --limit=100
```

### Health Check

```bash
# Check health
php artisan rabbitmq:health

# JSON output
php artisan rabbitmq:health --json
```

## Priority Queues

Implement the `HasPriority` interface on your job:

```php
use Lettermint\RabbitMQ\Contracts\HasPriority;

#[ConsumesQueue(queue: 'tasks', maxPriority: 10)]
class ProcessTask implements ShouldQueue, HasPriority
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

// High priority
ProcessTask::dispatch($taskId, priority: 10);

// Low priority
ProcessTask::dispatch($taskId, priority: 1);
```

## Delayed Messages

Delayed/scheduled messages are supported via the `rabbitmq_delayed_message_exchange` plugin.

### Setup

1. **Install the plugin** on your RabbitMQ server:
```bash
rabbitmq-plugins enable rabbitmq_delayed_message_exchange
```

2. **Enable in config** (enabled by default):
```php
// config/rabbitmq.php
'delayed' => [
    'enabled' => true,
    'exchange' => 'delayed',
],
```

3. **Declare topology** to create the delayed exchange:
```bash
php artisan rabbitmq:declare
```

### Usage

Standard Laravel delay patterns work out of the box:

```php
// Delay by seconds
ProcessTask::dispatch($taskId)->delay(300); // 5 minutes

// Delay with Carbon
ProcessTask::dispatch($taskId)->delay(now()->addMinutes(30));

// Delay with DateTime
ProcessTask::dispatch($taskId)->delay(now()->addHours(2));

// Schedule for specific time
ProcessTask::dispatch($taskId)->delay(now()->tomorrow()->setHour(9));
```

### How It Works

```
Publisher                    Delayed Exchange              Target Queue
   │                              │                            │
   │ delay: 5 min                 │                            │
   │ routing_key: priority.high   │                            │
   └─────────────────────────────►│                            │
                                  │                            │
                                  │ [holds message 5 min]      │
                                  │                            │
                                  │ ─ ─ [after delay] ─ ─ ─ ─►│
                                                               │
                                                          Consumer
```

The delayed exchange holds the message and releases it after the specified delay, routing it to the appropriate queue based on the routing key.

### Limitations

- Maximum delay: 24 hours (configurable in `config/rabbitmq.php`)
- Requires the `rabbitmq_delayed_message_exchange` plugin
- Messages stored in memory during delay (consider for high volumes)

## Dead Letter Queues

DLQs are automatically created based on your exchange domain:

```
messages exchange → messages.dlq exchange (auto-created)
jobs:high-priority queue → dlq:jobs:high-priority queue (auto-created)
```

Messages go to DLQ when:
1. Consumer rejects without requeue
2. Message TTL expires
3. Queue max-length exceeded
4. Job exceeds max retry attempts

## Kubernetes Deployment

### Worker Deployment

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: queue-worker
spec:
  replicas: 3
  template:
    spec:
      containers:
        - name: worker
          image: your-app:latest
          command: ["php", "artisan", "rabbitmq:consume"]
          args:
            - "jobs:high-priority"
            - "--prefetch=25"
            - "--max-jobs=500"
            - "--max-memory=256"
          resources:
            requests:
              memory: "128Mi"
              cpu: "100m"
            limits:
              memory: "512Mi"
              cpu: "500m"
```

### Horizontal Pod Autoscaler

```yaml
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: queue-worker-hpa
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: queue-worker
  minReplicas: 2
  maxReplicas: 20
  metrics:
    - type: External
      external:
        metric:
          name: rabbitmq_queue_messages_ready
          selector:
            matchLabels:
              queue: jobs:high-priority
        target:
          type: AverageValue
          averageValue: "100"
```

## Fallback Routing for Third-Party Jobs

Jobs without a `#[ConsumesQueue]` attribute (like jobs from third-party packages) are routed using fallback logic:

1. **Exchange**: Uses `config('rabbitmq.queue.exchange')`
2. **Routing Key**: `'fallback.{queue_name}'` (colons replaced with dots)

To catch these messages, create a fallback queue:

```php
#[ConsumesQueue(
    queue: 'fallback',
    bindings: ['your-exchange' => 'fallback.#'],  // Catch all fallback routes
    quorum: true,
)]
class FallbackJob implements ShouldQueue
{
    // This queue catches all non-attributed jobs
}
```

## How Bindings Work

```
Publisher                     Exchange                        Queue
   │                            │                               │
   │ publish to 'messages'      │                               │
   │ routing key: 'priority.high.task'                          │
   │                            │                               │
   └───────────────────────────►│                               │
                                │                               │
                                │ checks bindings:              │
                                │ 'priority.high.*' → jobs:high │
                                │                               │
                                └──────────────────────────────►│
                                                                │
                                                          [Message stored]
                                                                │
                                                                ▼
                                                           Consumer
                                                       (ProcessTask job)
```

- **Exchange**: Post office - routes messages based on routing keys
- **Queue**: PO Box - stores messages until consumed
- **Binding**: Routing rule - "if routing key matches X, deliver to queue Y"
- **Routing Key**: Address label on each message

## Contributing

Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## License

MIT License. See [LICENSE](LICENSE) for details.
