<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Discovery;

use Illuminate\Support\Collection;
use Lettermint\RabbitMQ\Attributes\ConsumesQueue;
use Lettermint\RabbitMQ\Attributes\Exchange;
use ReflectionAttribute;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * Scans directories for RabbitMQ attribute declarations.
 *
 * This class discovers Exchange and ConsumesQueue attributes from PHP classes
 * and builds a complete picture of the RabbitMQ topology that needs to be declared.
 */
class AttributeScanner
{
    /**
     * Discovered exchanges.
     *
     * @var Collection<int, array{class: class-string, attribute: Exchange}>
     */
    protected Collection $exchanges;

    /**
     * Discovered queue configurations.
     *
     * @var Collection<int, array{class: class-string, attribute: ConsumesQueue}>
     */
    protected Collection $queues;

    public function __construct()
    {
        $this->exchanges = collect();
        $this->queues = collect();
    }

    /**
     * Scan directories for RabbitMQ attributes.
     *
     * @param  array<string>  $paths  Directories to scan
     * @return $this
     */
    public function scan(array $paths): self
    {
        $this->exchanges = collect();
        $this->queues = collect();

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            $this->scanDirectory($path);
        }

        return $this;
    }

    /**
     * Scan a single directory for PHP files with attributes.
     */
    protected function scanDirectory(string $path): void
    {
        $finder = new Finder;
        $finder->files()->in($path)->name('*.php');

        foreach ($finder as $file) {
            $this->scanFile($file->getRealPath());
        }
    }

    /**
     * Scan a single PHP file for RabbitMQ attributes.
     */
    protected function scanFile(string $filePath): void
    {
        $className = $this->getClassNameFromFile($filePath);

        if ($className === null || ! class_exists($className)) {
            return;
        }

        $reflection = new ReflectionClass($className);

        // Scan for Exchange attribute
        $exchangeAttributes = $reflection->getAttributes(Exchange::class, ReflectionAttribute::IS_INSTANCEOF);
        foreach ($exchangeAttributes as $attribute) {
            $this->exchanges->push([
                'class' => $className,
                'attribute' => $attribute->newInstance(),
            ]);
        }

        // Scan for ConsumesQueue attribute
        $queueAttributes = $reflection->getAttributes(ConsumesQueue::class, ReflectionAttribute::IS_INSTANCEOF);
        foreach ($queueAttributes as $attribute) {
            $this->queues->push([
                'class' => $className,
                'attribute' => $attribute->newInstance(),
            ]);
        }
    }

    /**
     * Extract the fully qualified class name from a PHP file.
     *
     * @return class-string|null
     */
    protected function getClassNameFromFile(string $filePath): ?string
    {
        $contents = file_get_contents($filePath);

        if ($contents === false) {
            return null;
        }

        $namespace = null;
        $class = null;

        // Extract namespace
        if (preg_match('/namespace\s+([^;]+);/', $contents, $matches)) {
            $namespace = $matches[1];
        }

        // Extract class name
        if (preg_match('/class\s+(\w+)/', $contents, $matches)) {
            $class = $matches[1];
        }

        if ($class === null) {
            return null;
        }

        /** @var class-string */
        $fqcn = $namespace !== null ? "{$namespace}\\{$class}" : $class;

        return $fqcn;
    }

    /**
     * Get all discovered exchanges.
     *
     * @return Collection<int, array{class: class-string, attribute: Exchange}>
     */
    public function getExchanges(): Collection
    {
        return $this->exchanges;
    }

    /**
     * Get all discovered queue configurations.
     *
     * @return Collection<int, array{class: class-string, attribute: ConsumesQueue}>
     */
    public function getQueues(): Collection
    {
        return $this->queues;
    }

    /**
     * Get a queue attribute for a specific job class.
     *
     * When a job class has multiple ConsumesQueue attributes (repeatable),
     * the optional $queue parameter allows matching the specific attribute
     * for the target queue. This is essential for correct routing when a
     * job like ProcessMessage can be dispatched to different queues.
     *
     * @param  string  $jobClass  The fully qualified job class name
     * @param  string|null  $queue  Optional queue name to match specific attribute
     */
    public function getQueueForJob(string $jobClass, ?string $queue = null): ?ConsumesQueue
    {
        // Filter to attributes for this job class
        $classAttributes = $this->queues->filter(fn ($item) => $item['class'] === $jobClass);

        if ($classAttributes->isEmpty()) {
            return null;
        }

        // If queue specified, try to find matching attribute
        if ($queue !== null) {
            $matched = $classAttributes->first(fn ($item) => $item['attribute']->queue === $queue);

            if ($matched !== null) {
                return $matched['attribute'];
            }
        }

        // Fall back to first attribute for this class (collection is not empty at this point)
        return $classAttributes->first()['attribute'];
    }

    /**
     * Build a complete topology representation.
     *
     * @return array{exchanges: array<string, Exchange>, queues: array<string, array{attribute: ConsumesQueue, class: class-string}>}
     */
    public function getTopology(): array
    {
        $exchanges = [];
        foreach ($this->exchanges as $item) {
            $exchanges[$item['attribute']->name] = $item['attribute'];
        }

        $queues = [];
        foreach ($this->queues as $item) {
            $queues[$item['attribute']->queue] = [
                'attribute' => $item['attribute'],
                'class' => $item['class'],
            ];
        }

        return [
            'exchanges' => $exchanges,
            'queues' => $queues,
        ];
    }
}
