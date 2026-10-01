# Batch consumption

Batch consumption is optional. It uses a dedicated command and does not change `rabbitmq:consume`.

Use one batch consumer for one queue. The application must supply a batch handler and all three collection limits:

```bash
php artisan rabbitmq:consume-batch events \
    --handler='App\RabbitMQ\EventBatchHandler' \
    --max-count=100 \
    --max-bytes=1048576 \
    --max-wait=1 \
    --timeout=30 \
    --tries=3 \
    --backoff=10,30,60
```

`--max-count` limits the number of messages in a batch. `--max-bytes` limits the sum of the raw AMQP message bodies. PHP object overhead is not part of this byte value. Use `--max-memory` as a separate process memory limit. `--max-wait` starts when the first delivery enters an empty buffer. The driver uses a monotonic clock and changes its broker wait time, so a partial batch can expire when no new message arrives.

The driver sets prefetch to the smaller value of `--max-count` and the remaining `--max-jobs` allowance. This setting bounds outstanding deliveries by message count. `--max-jobs` counts settled messages, not handler calls. Increasing prefetch does not make a normal consumer a batch consumer.

AMQP prefetch has no payload byte limit. Each delivery that enters a batch is no larger than `--max-bytes`, and the buffered payload sum stays within that limit. The client can also hold prefetched deliveries before the driver adds them to a batch. For valid deliveries, the outstanding payload size is bounded by the prefetch count multiplied by `--max-bytes`, plus PHP object overhead. Set `--max-count` and `--max-memory` to values that are safe for the worker.

The client must receive an oversized message before the driver can measure and reject it. Thus, one oversized body can cause temporary memory use above the driver byte limit. Use the RabbitMQ message-size limit when publishers are not trusted.

## Handler contract

A handler implements `Lettermint\RabbitMQ\Contracts\BatchHandler`. `jobClasses()` is an exact allow list. The driver restores each Laravel object job and checks its class before it adds the delivery to a batch.

The `handle()` method receives a non-empty list of `BatchItem` objects. Each item contains the restored job object and safe delivery metadata:

- Job ID and queue name.
- Application attempt number.
- RabbitMQ delivery count and redelivery state.
- Message timestamp.
- Raw payload byte count.

The raw message body is not part of the public item. Return one `BatchItemResult` for each input item. Return the same `BatchItem` object that the driver supplied.

```php
use Lettermint\RabbitMQ\Batch\BatchItem;
use Lettermint\RabbitMQ\Batch\BatchItemResult;
use Lettermint\RabbitMQ\Contracts\BatchHandler;

final class EventBatchHandler implements BatchHandler
{
    public function __construct(private FakeEventStorage $storage) {}

    public static function jobClasses(): array
    {
        return [StoreEvent::class];
    }

    public function handle(array $items): array
    {
        $rows = [];
        $results = [];

        foreach ($items as $item) {
            /** @var StoreEvent $job */
            $job = $item->job;

            if (! isset($job->event['id'], $job->event['type'])) {
                $results[spl_object_id($item)] = BatchItemResult::failure(
                    $item,
                    new InvalidArgumentException('The event is not valid.'),
                );

                continue;
            }

            $rows[spl_object_id($item)] = $job->event;
        }

        try {
            $this->storage->insert(array_values($rows));

            foreach ($items as $item) {
                $id = spl_object_id($item);

                if (isset($rows[$id])) {
                    $results[$id] = BatchItemResult::success($item);
                }
            }
        } catch (Throwable $exception) {
            foreach ($items as $item) {
                $id = spl_object_id($item);

                if (isset($rows[$id])) {
                    $results[$id] = BatchItemResult::retry($item, $exception);
                }
            }
        }

        return array_map(
            static fn (BatchItem $item): BatchItemResult => $results[spl_object_id($item)],
            $items,
        );
    }
}

final class FakeEventStorage
{
    /** @var list<array<string, mixed>> */
    public array $events = [];

    /** @param list<array<string, mixed>> $events */
    public function insert(array $events): void
    {
        array_push($this->events, ...$events);
    }
}
```

The three results have these meanings:

- `success($item)` tells the driver that all required writes for the item are complete. The driver then acknowledges only that delivery.
- `retry($item, $exception)` tells the driver to publish a delayed replacement. The driver confirms that publication before it acknowledges the original delivery.
- `failure($item, $exception)` tells the driver to reject the delivery without requeue. RabbitMQ sends it to the configured dead-letter path.

The handler must return exactly one result for each item. A missing or duplicate result causes a delayed retry for that item. A result for a foreign item is ignored. If the handler throws, all items from the handler call get a delayed retry. The minimum retry delay is one second by default. This rule prevents a rapid requeue loop. You can increase it with `--min-retry-delay`.

The container creates a new handler for each batch. Do not register the handler as a singleton or scoped binding. The worker also resets Laravel scoped state before and after each handler call.

## Invalid deliveries

The batch queue is strict. A malformed payload, an unsupported job class, or one message that is larger than `--max-bytes` gets an individual terminal rejection. It does not enter the batch and it does not call the normal job `handle()` method. Thus, one invalid delivery cannot hold valid deliveries in the buffer.

Use a dedicated queue that contains only allowed batch job classes. Normal Laravel job execution is not part of this path. Job middleware, chains, Laravel bus batches, unique-job locks, and the job `handle()` method do not run. The application batch handler owns all domain validation and writes.

## Delivery guarantees and shutdown

The driver keeps each collected delivery unacknowledged until the application result is available. It uses an individual acknowledge or reject operation for each delivery. It does not use AMQP multiple acknowledgement.

Processing is at least once. If application writes succeed but an acknowledgement fails, RabbitMQ can deliver the same event again. Application writes must be idempotent. Use a stable event or job ID as the idempotency key.

`--timeout` limits the full batch handler call. Per-job timeout fields do not apply to a batch. On timeout, the worker reports an interrupted batch and exits without settlement. RabbitMQ can then redeliver the unacknowledged items.

On SIGTERM, pause, restart, memory limit, max-time limit, or connection loss, the consumer does not start a partial buffered batch. It closes the channel and leaves those deliveries for redelivery. If a handler is active, it can finish within the batch timeout. `--stop-when-empty` is different: it flushes a partial batch and then stops.

The heartbeat helper stays active during a slow handler call. Connection recovery uses the same bounded rules as the normal consumer.

## Reporting

The driver emits `BatchProcessing`, `BatchProcessed`, `BatchInterrupted`, and `BatchItemSettled` events. Structured logs include the batch size, payload bytes, collection time, processing time, settlement time, retry count, and failure count. Logs do not include message payload contents.

The worker status file uses `buffering` while it collects deliveries and `processing` during a handler call. The processing state contains the batch deadline.

## Application adoption

Producer code does not change. To adopt batch consumption in an application:

1. Put the supported event job on a dedicated logical queue.
2. Add a handler that implements `BatchHandler` and lists that event job class.
3. Validate and map each restored job in the handler.
4. Make the required storage writes in bulk.
5. Return a result for every input item.
6. Make the writes idempotent for broker redelivery.
7. Replace the queue process command with `rabbitmq:consume-batch` and set explicit limits.

Do not run a normal consumer and a batch consumer on the same dedicated queue. They have different execution contracts.
