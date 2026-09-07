<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Support;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

final class FailedJobDetails
{
    public function record(JobFailed $event): void
    {
        $this->attempt(function (FailedJobProviderInterface $provider) use ($event): void {
            $id = $event->job->getJobId();

            if ($id !== '' && $provider->find($id) !== null) {
                $provider->forget($id);
            }

            $provider->log($event->connectionName, $event->job->getQueue(), $event->job->getRawBody(), $event->exception);
        });
    }

    public function find(string $id): mixed
    {
        return $this->attempt(fn (FailedJobProviderInterface $provider): mixed => $provider->find($id));
    }

    public function forget(string $id): void
    {
        $this->attempt(fn (FailedJobProviderInterface $provider): mixed => $provider->forget($id));
    }

    private function attempt(callable $callback): mixed
    {
        try {
            $provider = app()->bound('queue.failer') ? app('queue.failer') : null;

            return $provider instanceof FailedJobProviderInterface ? $callback($provider) : null;
        } catch (Throwable $exception) {
            ExceptionReporter::report($exception);

            try {
                Log::warning('RabbitMQ optional failed-job details are unavailable', [
                    'event' => 'rabbitmq.failure_details.error',
                    'exception_class' => $exception::class,
                ]);
            } catch (Throwable) {
                // A log transport must not change a message settlement result.
            }

            return null;
        }
    }
}
