<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Batch;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Encryption\Encrypter;
use Lettermint\RabbitMQ\Exceptions\MalformedBatchMessageException;
use Lettermint\RabbitMQ\Exceptions\UnsupportedBatchJobException;
use Lettermint\RabbitMQ\Queue\RabbitMQJob;
use Throwable;

/** @internal */
final readonly class BatchItemFactory
{
    public function __construct(private Container $container) {}

    /** @param list<class-string> $supportedJobClasses */
    public function make(RabbitMQJob $delivery, array $supportedJobClasses = []): BatchItem
    {
        try {
            $payload = $delivery->payload();
            $handler = $payload['job'] ?? null;
            $data = $payload['data'] ?? null;

            if ($handler !== 'Illuminate\\Queue\\CallQueuedHandler@call' || ! is_array($data)) {
                throw new MalformedBatchMessageException('Batch consumers accept only Laravel object job payloads.');
            }

            $commandName = $data['commandName'] ?? null;
            $command = $data['command'] ?? null;

            if (! is_string($commandName) || $commandName === '' || ! is_string($command) || $command === '') {
                throw new MalformedBatchMessageException('The batch job payload has no valid command data.');
            }

            if ($supportedJobClasses !== [] && ! in_array($commandName, $supportedJobClasses, true)) {
                throw new UnsupportedBatchJobException('The configured batch handler does not support this job class.');
            }

            $serialized = str_starts_with($command, 'O:')
                ? $command
                : $this->decrypt($command);

            $job = @unserialize($serialized);

            if (! is_object($job) || $job instanceof \__PHP_Incomplete_Class || $job::class !== $commandName) {
                throw new MalformedBatchMessageException('The batch job payload does not contain the declared job class.');
            }

            return new BatchItem(
                job: $job,
                jobId: $delivery->getJobId(),
                queue: $delivery->getQueue(),
                attempt: $delivery->attempts(),
                brokerDeliveryCount: $delivery->brokerDeliveryCount(),
                redelivered: $delivery->getMessage()->isRedelivered(),
                messageTimestamp: $delivery->getTimestamp(),
                payloadBytes: strlen($delivery->getRawBody()),
            );
        } catch (MalformedBatchMessageException|UnsupportedBatchJobException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new MalformedBatchMessageException('The batch job payload cannot be restored.', previous: $exception);
        }
    }

    private function decrypt(string $command): string
    {
        if (! $this->container->bound(Encrypter::class)) {
            throw new MalformedBatchMessageException('The encrypted batch job payload cannot be restored.');
        }

        $decrypted = $this->container->make(Encrypter::class)->decrypt($command);

        if (! is_string($decrypted)) {
            throw new MalformedBatchMessageException('The decrypted batch job payload is not valid.');
        }

        return $decrypted;
    }
}
