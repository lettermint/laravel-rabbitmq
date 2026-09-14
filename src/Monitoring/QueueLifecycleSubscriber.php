<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Monitoring;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Support\Facades\Log;
use Lettermint\RabbitMQ\Events\BatchInterrupted;
use Lettermint\RabbitMQ\Events\BatchItemSettled;
use Lettermint\RabbitMQ\Events\BatchProcessed;
use Lettermint\RabbitMQ\Events\BatchProcessing;
use Lettermint\RabbitMQ\Events\ConnectionRecovered;
use Lettermint\RabbitMQ\Events\DlqMessageReplayed;
use Lettermint\RabbitMQ\Events\DlqOperationFinished;
use Lettermint\RabbitMQ\Events\JobDeadLettered;
use Lettermint\RabbitMQ\Events\JobReleased;
use Lettermint\RabbitMQ\Events\JobRetried;
use Lettermint\RabbitMQ\Events\JobSettlementFailed;
use Lettermint\RabbitMQ\Events\MessagePublished;
use Lettermint\RabbitMQ\Events\MessagePublishFailed;
use Lettermint\RabbitMQ\Events\UnknownQueueFallbackUsed;
use Lettermint\RabbitMQ\Exceptions\PublishException;
use Lettermint\RabbitMQ\Queue\RabbitMQJob;

final class QueueLifecycleSubscriber
{
    /** @var array<string, float> */
    private array $startedAt = [];

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(BatchProcessing::class, function (BatchProcessing $event): void {
            Log::info('RabbitMQ batch processing', [
                'event' => 'rabbitmq.batch.processing',
                'batch_id' => $event->batchId,
                'connection' => $event->connection,
                'queue' => $event->queue,
                'handler_class' => $event->handler,
                'batch_size' => $event->size,
                'payload_bytes' => $event->payloadBytes,
                'collection_ms' => round($event->collectionMilliseconds, 2),
            ]);
        });
        $events->listen(BatchProcessed::class, function (BatchProcessed $event): void {
            Log::info('RabbitMQ batch processed', [
                'event' => 'rabbitmq.batch.processed',
                'batch_id' => $event->batchId,
                'connection' => $event->connection,
                'queue' => $event->queue,
                'handler_class' => $event->handler,
                'batch_size' => $event->size,
                'payload_bytes' => $event->payloadBytes,
                'collection_ms' => round($event->collectionMilliseconds, 2),
                'processing_ms' => round($event->processingMilliseconds, 2),
                'settlement_ms' => round($event->settlementMilliseconds, 2),
                'successes' => $event->successes,
                'retries' => $event->retries,
                'failures' => $event->failures,
                'invalid_results' => $event->invalidResults,
                'handler_exception_class' => $event->handlerExceptionClass,
            ]);
        });
        $events->listen(BatchInterrupted::class, function (BatchInterrupted $event): void {
            Log::warning('RabbitMQ batch interrupted', [
                'event' => 'rabbitmq.batch.interrupted',
                'batch_id' => $event->batchId,
                'connection' => $event->connection,
                'queue' => $event->queue,
                'batch_size' => $event->size,
                'payload_bytes' => $event->payloadBytes,
                'settled' => $event->settled,
                'unacknowledged' => $event->unacknowledged,
                'reason' => $event->reason,
                'collection_ms' => round($event->collectionMilliseconds, 2),
                'processing_ms' => round($event->processingMilliseconds, 2),
                'settlement_ms' => round($event->settlementMilliseconds, 2),
                'exception_class' => $event->exceptionClass,
            ]);
        });
        $events->listen(BatchItemSettled::class, function (BatchItemSettled $event): void {
            Log::info('RabbitMQ batch item settled', [
                'event' => 'rabbitmq.batch.item_settled',
                'batch_id' => $event->batchId,
                'connection' => $event->connection,
                'queue' => $event->queue,
                'job_class' => $event->jobClass,
                'job_id' => $event->jobId,
                'attempt' => $event->attempt,
                'broker_delivery_count' => $event->brokerDeliveryCount,
                'redelivered' => $event->redelivered,
                'queue_wait_ms' => $this->queueWaitMilliseconds($event->messageTimestamp),
                'payload_bytes' => $event->payloadBytes,
                'outcome' => $event->outcome->value,
                'reason' => $event->reason,
                'processing_ms' => round($event->processingMilliseconds, 2),
                'settlement_ms' => round($event->settlementMilliseconds, 2),
            ]);
        });
        $events->listen(JobProcessing::class, $this->processing(...));
        $events->listen(JobProcessed::class, $this->processed(...));
        $events->listen(JobExceptionOccurred::class, $this->exceptionOccurred(...));
        $events->listen(JobFailed::class, $this->failed(...));
        $events->listen(JobTimedOut::class, function (JobTimedOut $event): void {
            if ($event->job instanceof RabbitMQJob) {
                Log::error('RabbitMQ job timed out', $this->jobContext($event->job, 'timed_out'));
                unset($this->startedAt[$this->key($event->job)]);
            }
        });
        $events->listen(JobReleased::class, $this->released(...));
        $events->listen(JobRetried::class, $this->retried(...));
        $events->listen(JobSettlementFailed::class, function (JobSettlementFailed $event): void {
            unset($this->startedAt[$event->jobId]);
            Log::error('RabbitMQ delivery settlement failed', [
                'event' => 'rabbitmq.job.settlement_error',
                'queue' => $event->queue,
                'job_id' => $event->jobId,
                'exception_class' => $event->exception::class,
            ]);
        });
        $events->listen(JobDeadLettered::class, $this->deadLettered(...));
        $events->listen(DlqMessageReplayed::class, $this->replayed(...));
        $events->listen(DlqOperationFinished::class, function (DlqOperationFinished $event): void {
            Log::notice('RabbitMQ DLQ operator action', $event->context);
        });
        $events->listen(MessagePublished::class, $this->published(...));
        $events->listen(MessagePublishFailed::class, $this->publishFailed(...));
        $events->listen(ConnectionRecovered::class, $this->connectionRecovered(...));
        $events->listen(UnknownQueueFallbackUsed::class, $this->unknownQueueFallbackUsed(...));
    }

    private function processing(JobProcessing $event): void
    {
        if (! $event->job instanceof RabbitMQJob) {
            return;
        }

        $key = $this->key($event->job);
        $this->startedAt[$key] = microtime(true);

        Log::info('RabbitMQ job processing', $this->jobContext($event->job, 'processing'));
    }

    private function processed(JobProcessed $event): void
    {
        if (! $event->job instanceof RabbitMQJob) {
            return;
        }

        if (! $event->job->isReleased() && ! $event->job->hasFailed()) {
            Log::info('RabbitMQ job processed', $this->jobContext($event->job, 'processed'));
        }
        unset($this->startedAt[$this->key($event->job)]);
    }

    private function exceptionOccurred(JobExceptionOccurred $event): void
    {
        if (! $event->job instanceof RabbitMQJob) {
            return;
        }

        Log::warning('RabbitMQ job raised an exception', array_merge(
            $this->jobContext($event->job, 'exception'),
            ['exception_class' => $event->exception::class],
        ));
    }

    private function failed(JobFailed $event): void
    {
        if (! $event->job instanceof RabbitMQJob) {
            return;
        }

        Log::error('RabbitMQ job failed', array_merge(
            $this->jobContext($event->job, 'failed'),
            ['exception_class' => $event->exception::class],
        ));
        unset($this->startedAt[$this->key($event->job)]);
    }

    private function released(JobReleased $event): void
    {
        Log::notice('RabbitMQ job released', [
            'event' => 'rabbitmq.job.released',
            'queue' => $event->queue,
            'job_class' => $event->jobName,
            'job_id' => $event->jobId,
            'attempt' => $event->attempt,
            'delay_seconds' => $event->delay,
            'result' => 'released',
            'processing_ms' => $this->processingMilliseconds($event->jobId),
            'queue_wait_ms' => $this->queueWaitMilliseconds($event->messageTimestamp),
            'redelivered' => $event->redelivered,
            'broker_delivery_count' => $event->brokerDeliveryCount,
        ]);
    }

    private function retried(JobRetried $event): void
    {
        Log::notice('RabbitMQ job retry scheduled', [
            'event' => 'rabbitmq.job.retried',
            'queue' => $event->queue,
            'job_class' => $event->jobName,
            'job_id' => $event->jobId,
            'attempt' => $event->attempt,
            'delay_seconds' => $event->delay,
            'result' => 'retry',
            'processing_ms' => $this->processingMilliseconds($event->jobId),
            'queue_wait_ms' => $this->queueWaitMilliseconds($event->messageTimestamp),
            'redelivered' => $event->redelivered,
            'broker_delivery_count' => $event->brokerDeliveryCount,
        ]);

        if ($event->jobId !== null) {
            unset($this->startedAt[$event->jobId]);
        }
    }

    private function deadLettered(JobDeadLettered $event): void
    {
        Log::error('RabbitMQ job dead-lettered', [
            'event' => 'rabbitmq.job.dead_lettered',
            'queue' => $event->queue,
            'job_class' => $event->jobName,
            'job_id' => $event->jobId,
            'attempt' => $event->attempt,
            'result' => 'dead_lettered',
            'exception_class' => $event->exception::class,
            'processing_ms' => $this->processingMilliseconds($event->jobId),
            'queue_wait_ms' => $this->queueWaitMilliseconds($event->messageTimestamp),
            'redelivered' => $event->redelivered,
            'broker_delivery_count' => $event->brokerDeliveryCount,
        ]);
    }

    private function replayed(DlqMessageReplayed $event): void
    {
        Log::notice('RabbitMQ dead-letter replayed', [
            'event' => 'rabbitmq.dlq.replayed',
            'queue' => $event->queue,
            'job_class' => $event->jobName,
            'job_id' => $event->jobId,
            'attempt' => $event->attempt,
            'result' => 'replayed',
        ]);
    }

    private function published(MessagePublished $event): void
    {
        Log::debug('RabbitMQ message published', [
            'event' => 'rabbitmq.publish.succeeded',
            'queue' => $event->queue,
            'physical_queue' => $event->physicalQueue,
            'exchange' => $event->exchange,
            'message_id' => $event->messageId,
            'duration_ms' => round($event->durationMilliseconds, 2),
            'result' => 'confirmed',
        ]);
    }

    private function publishFailed(MessagePublishFailed $event): void
    {
        Log::error('RabbitMQ message publish failed', [
            'event' => 'rabbitmq.publish.failed',
            'queue' => $event->queue,
            'physical_queue' => $event->physicalQueue,
            'exchange' => $event->exchange,
            'message_id' => $event->messageId,
            'result' => $event->exception instanceof PublishException && $event->exception->uncertain ? 'uncertain' : 'failed',
            'exception_class' => $event->exception::class,
        ]);
    }

    private function connectionRecovered(ConnectionRecovered $event): void
    {
        Log::notice('RabbitMQ connection recovered', [
            'event' => 'rabbitmq.connection.recovered',
            'connection' => $event->connection,
            'attempt' => $event->attempt,
            'duration_ms' => round($event->durationMilliseconds, 2),
            'result' => 'recovered',
        ]);
    }

    private function unknownQueueFallbackUsed(UnknownQueueFallbackUsed $event): void
    {
        Log::warning('RabbitMQ unknown queue fallback used', [
            'event' => 'rabbitmq.queue.fallback_used',
            'queue' => $event->queue,
            'physical_queue' => $event->physicalQueue,
            'result' => 'fallback',
        ]);
    }

    /** @return array<string, mixed> */
    private function jobContext(RabbitMQJob $job, string $result): array
    {
        $startedAt = $this->startedAt[$this->key($job)] ?? null;
        $timestamp = $job->getTimestamp();

        return [
            'event' => 'rabbitmq.job.'.$result,
            'queue' => $job->getQueue(),
            'job_class' => $job->resolveName(),
            'job_id' => $job->getJobId(),
            'attempt' => $job->attempts(),
            'result' => $result,
            'processing_ms' => $startedAt === null ? null : round((microtime(true) - $startedAt) * 1000, 2),
            'queue_wait_ms' => $timestamp > 0 && $startedAt !== null ? max(0, (int) (($startedAt - $timestamp) * 1000)) : null,
            'redelivered' => $job->getMessage()->isRedelivered(),
            'broker_delivery_count' => $job->brokerDeliveryCount(),
        ];
    }

    private function key(RabbitMQJob $job): string
    {
        return $job->getJobId() ?? (string) spl_object_id($job);
    }

    private function processingMilliseconds(?string $jobId): ?float
    {
        if ($jobId === null || ! isset($this->startedAt[$jobId])) {
            return null;
        }

        return round((microtime(true) - $this->startedAt[$jobId]) * 1000, 2);
    }

    private function queueWaitMilliseconds(int $timestamp): ?int
    {
        return $timestamp > 0 ? max(0, (time() - $timestamp) * 1000) : null;
    }
}
