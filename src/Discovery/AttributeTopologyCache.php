<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Discovery;

use Illuminate\Support\Arr;
use Lettermint\RabbitMQ\Attributes\ConsumesQueue;
use Lettermint\RabbitMQ\Attributes\Exchange;
use RuntimeException;
use Symfony\Component\Finder\Finder;

final class AttributeTopologyCache
{
    private const VERSION = 1;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly AttributeScanner $scanner,
        private readonly array $config,
    ) {}

    public function enabled(): bool
    {
        return (bool) Arr::get($this->config, 'discovery.cache', true);
    }

    public function path(): string
    {
        return (string) Arr::get(
            $this->config,
            'discovery.cache_path',
            storage_path('framework/cache/rabbitmq-topology.php'),
        );
    }

    /** @return array{exchanges: array<string, array<string, mixed>>, queues: array<string, array<string, mixed>>}|null */
    public function load(): ?array
    {
        if (! $this->enabled() || ! is_file($this->path())) {
            return null;
        }

        $cached = require $this->path();

        if (! is_array($cached)
            || ($cached['version'] ?? null) !== self::VERSION
            || ! is_array($cached['topology'] ?? null)
            || ! is_array($cached['topology']['exchanges'] ?? null)
            || ! is_array($cached['topology']['queues'] ?? null)) {
            throw new RuntimeException("RabbitMQ topology cache [{$this->path()}] is invalid.");
        }

        return $cached['topology'];
    }

    /** @return array{exchanges: array<string, array<string, mixed>>, queues: array<string, array<string, mixed>>} */
    public function compile(): array
    {
        $this->scanner->scan($this->paths());
        $discovered = $this->scanner->getTopology();
        $exchanges = [];
        $queues = [];

        foreach ($discovered['exchanges'] as $logicalName => $attribute) {
            /** @var Exchange $attribute */
            $exchanges[$logicalName] = [
                'type' => $attribute->getTypeValue(),
                'durable' => $attribute->durable,
                'auto_delete' => $attribute->autoDelete,
                'internal' => $attribute->internal,
                'arguments' => $attribute->arguments,
                'bind_to' => $attribute->bindTo,
                'bind_routing_key' => $attribute->bindRoutingKey,
            ];
        }

        foreach ($discovered['queues'] as $logicalName => $data) {
            /** @var ConsumesQueue $attribute */
            $attribute = $data['attribute'];
            $bindings = array_map(
                fn (array $routingKeys): array => array_values(array_unique($routingKeys)),
                $data['allBindings'],
            );

            $queues[$logicalName] = [
                'bindings' => $bindings,
                'default_exchange' => $bindings === [],
                'quorum' => $attribute->quorum,
                'single_active_consumer' => $attribute->singleActiveConsumer,
                'delivery_limit' => $attribute->deliveryLimit,
                'max_length' => $attribute->maxLength,
                'max_length_bytes' => null,
                'message_ttl' => $attribute->messageTtl,
                'max_priority' => $attribute->maxPriority,
                'overflow' => $attribute->quorum ? 'reject-publish' : $attribute->overflowEnum->value,
                'dead_letter' => $attribute->getDlqExchangeName() !== null,
                'dead_letter_exchange' => $attribute->getDlqExchangeName(),
                'dead_letter_queue' => $attribute->getDlqQueueName(),
                'dead_letter_routing_key' => $attribute->getDlqRoutingKey(),
            ];
        }

        ksort($exchanges);
        ksort($queues);

        return [
            'exchanges' => $exchanges,
            'queues' => $queues,
        ];
    }

    /**
     * @param  array{exchanges: array<string, array<string, mixed>>, queues: array<string, array<string, mixed>>}  $topology
     */
    public function write(array $topology): void
    {
        $path = $this->path();
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create RabbitMQ topology cache directory [{$directory}].");
        }

        $cache = [
            'version' => self::VERSION,
            'fingerprint' => $this->fingerprint(),
            'topology' => $topology,
        ];
        $contents = "<?php\n\nreturn ".var_export($cache, true).";\n";
        $temporaryPath = tempnam($directory, '.rabbitmq-topology-');

        if ($temporaryPath === false) {
            throw new RuntimeException("Cannot create a temporary RabbitMQ topology cache in [{$directory}].");
        }

        try {
            if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
                throw new RuntimeException("Cannot write RabbitMQ topology cache [{$temporaryPath}].");
            }

            if (! chmod($temporaryPath, 0644)) {
                throw new RuntimeException("Cannot set permissions on RabbitMQ topology cache [{$temporaryPath}].");
            }

            if (! rename($temporaryPath, $path)) {
                throw new RuntimeException("Cannot replace RabbitMQ topology cache [{$path}].");
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    public function isCurrent(): bool
    {
        if (! $this->enabled() || ! is_file($this->path())) {
            return false;
        }

        $cached = require $this->path();

        return is_array($cached)
            && ($cached['version'] ?? null) === self::VERSION
            && is_string($cached['fingerprint'] ?? null)
            && hash_equals($cached['fingerprint'], $this->fingerprint());
    }

    /** @return list<string> */
    private function paths(): array
    {
        $paths = Arr::get($this->config, 'discovery.paths', [
            app_path('Jobs'),
            app_path('RabbitMQ'),
        ]);

        if (! is_array($paths)) {
            throw new RuntimeException('RabbitMQ discovery.paths must be an array.');
        }

        return array_values(array_filter(
            $paths,
            fn (mixed $path): bool => is_string($path) && is_dir($path),
        ));
    }

    private function fingerprint(): string
    {
        $files = [];

        foreach ($this->paths() as $path) {
            $finder = (new Finder)->files()->in($path)->name('*.php')->sortByName();

            foreach ($finder as $file) {
                $realPath = $file->getRealPath();

                if ($realPath === false) {
                    continue;
                }

                if ($realPath === realpath($this->path())) {
                    continue;
                }

                $files[$realPath] = hash_file('sha256', $realPath);
            }
        }

        ksort($files);

        return hash('sha256', serialize($files));
    }
}
