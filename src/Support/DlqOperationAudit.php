<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Support;

use Lettermint\RabbitMQ\Actions\Dlq\Results\DlqInspectResult;
use Lettermint\RabbitMQ\Actions\Dlq\Results\DlqPurgeResult;
use Lettermint\RabbitMQ\Actions\Dlq\Results\DlqReplayResult;
use Lettermint\RabbitMQ\Events\DlqOperationFinished;
use Throwable;

final class DlqOperationAudit
{
    /**
     * @template T of DlqInspectResult|DlqPurgeResult|DlqReplayResult
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public static function run(string $action, string $queue, ?string $messageId, bool $dryRun, callable $operation): mixed
    {
        $result = null;
        $error = null;

        try {
            return $result = $operation();
        } catch (Throwable $exception) {
            $error = $exception::class;
            throw $exception;
        } finally {
            try {
                $operator = app()->bound('auth') ? app('auth')->id() : null;
                $count = match (true) {
                    $result instanceof DlqReplayResult => $result->replayedCount,
                    $result instanceof DlqPurgeResult => $result->purgedCount,
                    $result instanceof DlqInspectResult => $result->totalFound,
                    default => 0,
                };
                event(new DlqOperationFinished([
                    'event' => 'rabbitmq.dlq.operation',
                    'action' => $action,
                    'queue' => $queue,
                    'job_id' => $messageId,
                    'operator_id' => $operator === null ? null : (string) $operator,
                    'source' => app()->runningInConsole() ? 'console' : 'http',
                    'dry_run' => $dryRun,
                    'count' => $count,
                    'failed_count' => $result instanceof DlqReplayResult ? $result->failedCount : 0,
                    'incomplete' => $result->incomplete ?? true,
                    'not_found' => $result?->wasMessageNotFound() ?? false,
                    'uncertain' => $result instanceof DlqReplayResult || $result instanceof DlqPurgeResult ? $result->uncertain : false,
                    'error_class' => $error,
                    'operation_error' => $error !== null || ($result instanceof DlqPurgeResult && $result->error !== null),
                ]));
            } catch (Throwable $exception) {
                ExceptionReporter::report($exception);
            }
        }
    }
}
