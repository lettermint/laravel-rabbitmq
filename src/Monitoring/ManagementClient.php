<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Monitoring;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ManagementClient
{
    public function configured(): bool
    {
        return is_string(config('rabbitmq.management.url')) && config('rabbitmq.management.url') !== '';
    }

    /** @return array<string, mixed>|null */
    public function queue(string $name, ?string $connection = null): ?array
    {
        return $this->get('queues/'.$this->vhost($connection).'/'.rawurlencode($name), $connection);
    }

    /** @return array<string, mixed>|null */
    public function get(string $path, ?string $connection = null): ?array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Configure rabbitmq.management.url to verify broker topology and safe DLQ access.');
        }

        $host = $this->host($connection);
        $response = Http::withBasicAuth(
            (string) (config('rabbitmq.management.user') ?? $host['user'] ?? 'guest'),
            (string) (config('rabbitmq.management.password') ?? $host['password'] ?? 'guest'),
        )->connectTimeout(3)->timeout(5)->withOptions([
            'allow_redirects' => false,
            'verify' => config('rabbitmq.management.ca_file') ?: true,
        ])->get(rtrim((string) config('rabbitmq.management.url'), '/').'/api/'.$path);

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful() || ! is_array($response->json())) {
            throw new RuntimeException('RabbitMQ management read failed (HTTP '.$response->status().').');
        }

        return $response->json();
    }

    public function assertSafeDeadLetterQueue(string $queue, ?string $connection = null): void
    {
        $deadline = microtime(true) + 10;
        do {
            $details = $this->queue($queue, $connection);

            if ($details === null || ($details['type'] ?? null) !== 'quorum'
                || $this->effectiveArgument($details, 'x-delivery-limit') !== -1
                || $this->effectiveArgument($details, 'x-overflow') !== 'reject-publish'
                || $this->effectiveArgument($details, 'x-message-ttl') !== null
                || $this->effectiveArgument($details, 'x-expires') !== null) {
                break;
            }
            if (in_array($details['delivery_limit'] ?? null, [-1, 'unlimited'], true)) {
                return;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('DLQ inspection requires a quorum queue with an effective delivery limit of -1, reject-publish overflow, and no expiry. Apply and verify the DLQ protection policy first.');
    }

    /** @param array<string, mixed> $queue */
    public function effectiveArgument(array $queue, string $argument): mixed
    {
        $key = str_starts_with($argument, 'x-') ? substr($argument, 2) : $argument;
        $value = $queue['arguments'][$argument] ?? null;
        $policy = $queue['effective_policy_definition'][$key] ?? null;
        if ($value === null || $policy === null) {
            return $value ?? $policy;
        }

        if ($key === 'delivery-limit' && is_int($value) && is_int($policy)) {
            return $value < 0 || $policy < 0 ? max($value, $policy) : min($value, $policy);
        }
        if (in_array($key, ['message-ttl', 'expires', 'max-length', 'max-length-bytes'], true) && is_int($value) && is_int($policy)) {
            return min($value, $policy);
        }
        if ($key === 'overflow' && ($queue['type'] ?? null) === 'quorum') {
            return $policy;
        }

        return $value;
    }

    public function vhost(?string $connection = null): string
    {
        return rawurlencode((string) ($this->host($connection)['vhost'] ?? '/'));
    }

    /** @return array<string, mixed> */
    private function host(?string $connection): array
    {
        $connection ??= config('rabbitmq.default', 'default');

        return config('rabbitmq.connections.'.$connection.'.hosts.0', []);
    }
}
