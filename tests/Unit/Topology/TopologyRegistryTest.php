<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Attributes\ConsumesQueue;
use Lettermint\RabbitMQ\Attributes\Exchange;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Exceptions\TopologyException;
use Lettermint\RabbitMQ\Exceptions\UnknownBindingException;
use Lettermint\RabbitMQ\Exceptions\UnknownQueueException;
use Lettermint\RabbitMQ\Topology\TopologyRegistry;

/** @param array<string, mixed> $overrides */
function topologyRegistryConfig(array $overrides = []): array
{
    return array_replace_recursive([
        'physical_prefix' => 'staging.',
        'strict_topology' => true,
        'dead_letter' => [
            'enabled' => true,
            'exchange' => 'dlx',
            'queue_prefix' => 'dlq:',
        ],
        'topology' => [
            'exchanges' => [
                'jobs' => ['type' => 'topic'],
                'dlx' => ['type' => 'direct'],
            ],
            'queues' => [
                'events' => ['bindings' => ['jobs' => ['events.#']]],
                'created' => ['bindings' => ['jobs' => ['#.created']]],
                'one-word' => ['bindings' => ['jobs' => ['events.*']]],
            ],
        ],
    ], $overrides);
}

test('strict mode requires an explicit queue registry', function () {
    $registry = testTopologyRegistry([
        'strict_topology' => true,
        'topology' => ['queues' => []],
    ]);

    expect(fn () => $registry->queues())
        ->toThrow(TopologyException::class, 'requires an explicit');
});

test('resolves logical queues to prefixed physical names', function () {
    $registry = testTopologyRegistry(topologyRegistryConfig());

    expect($registry->physicalQueue('events'))->toBe('staging.events')
        ->and($registry->queue('events')->deadLetterQueue)->toBe('staging.dlq:events');
});

test('rejects an unknown logical queue in strict mode', function () {
    $registry = testTopologyRegistry(topologyRegistryConfig());

    expect(fn () => $registry->queue('missing'))
        ->toThrow(UnknownQueueException::class, 'is not registered');
});

test('matches RabbitMQ topic wildcards including zero words for hash', function () {
    $registry = testTopologyRegistry(topologyRegistryConfig());

    expect($registry->validateRoutingKey('events', 'events'))->toBe('events')
        ->and($registry->validateRoutingKey('events', 'events.account.created'))->toBe('events.account.created')
        ->and($registry->validateRoutingKey('created', 'created'))->toBe('created')
        ->and($registry->validateRoutingKey('created', 'account.created'))->toBe('account.created')
        ->and($registry->validateRoutingKey('one-word', 'events.created'))->toBe('events.created');
});

test('rejects wildcard, empty, and unbound publish routing keys', function (string $routingKey, string $exception) {
    $registry = testTopologyRegistry(topologyRegistryConfig());

    expect(fn () => $registry->validateRoutingKey('events', $routingKey))
        ->toThrow($exception);
})->with([
    'empty' => ['', InvalidArgumentException::class],
    'wildcard' => ['events.*', InvalidArgumentException::class],
    'unbound' => ['other.created', UnknownBindingException::class],
]);

test('rejects physical queue name collisions before declaration', function () {
    $config = topologyRegistryConfig([
        'topology' => [
            'queues' => [
                'events' => ['bindings' => ['jobs' => ['events']]],
                'dlq:events' => ['bindings' => ['jobs' => ['other']]],
            ],
        ],
    ]);

    expect(fn () => testTopologyRegistry($config)->queues())
        ->toThrow(TopologyException::class, 'conflicts');
});

test('rejects physical exchange name collisions before declaration', function () {
    $config = topologyRegistryConfig([
        'topology' => [
            'exchanges' => [
                'jobs' => ['name' => 'shared', 'type' => 'topic'],
                'dlx' => ['name' => 'shared', 'type' => 'direct'],
            ],
        ],
    ]);

    expect(fn () => testTopologyRegistry($config)->exchanges())
        ->toThrow(TopologyException::class, 'same physical name');
});

test('rejects an invalid physical name after the prefix is applied', function () {
    $config = topologyRegistryConfig([
        'physical_prefix' => str_repeat('a', 250),
    ]);

    expect(fn () => testTopologyRegistry($config)->queues())
        ->toThrow(TopologyException::class, 'physical');
});

test('rejects unsupported exchange types before declaration', function (string $type) {
    $config = topologyRegistryConfig([
        'topology' => [
            'exchanges' => [
                'jobs' => ['type' => $type],
                'dlx' => ['type' => 'direct'],
            ],
        ],
    ]);

    expect(fn () => testTopologyRegistry($config)->exchanges())
        ->toThrow(TopologyException::class, 'unsupported type');
})->with(['headers', 'x-delayed-message']);

test('rejects a queue binding to an unknown exchange', function () {
    $config = topologyRegistryConfig([
        'topology' => [
            'queues' => [
                'events' => ['bindings' => ['missing' => ['events']]],
            ],
        ],
    ]);

    expect(fn () => testTopologyRegistry($config)->queues())
        ->toThrow(TopologyException::class, 'unknown exchange');
});

test('resolves exchange-to-exchange bindings to physical names', function () {
    $config = topologyRegistryConfig([
        'topology' => [
            'exchanges' => [
                'jobs' => ['type' => 'topic'],
                'child' => [
                    'type' => 'topic',
                    'bind_to' => 'jobs',
                    'bind_routing_key' => 'child.#',
                ],
                'dlx' => ['type' => 'direct'],
            ],
        ],
    ]);

    $child = testTopologyRegistry($config)->exchanges()['child'];

    expect($child['bind_to'])->toBe('staging.jobs')
        ->and($child['bind_routing_key'])->toBe('child.#');
});

test('rejects a discovered dead-letter exchange that is not direct', function () {
    $scanner = Mockery::mock(AttributeScanner::class);
    $scanner->shouldReceive('getTopology')->andReturn([
        'exchanges' => [
            'jobs' => new Exchange('jobs', 'topic'),
            'jobs.dlq' => new Exchange('jobs.dlq', 'topic'),
        ],
        'queues' => [
            'events' => [
                'attribute' => new ConsumesQueue(
                    queue: 'events',
                    bindings: ['jobs' => ['events']],
                ),
                'allBindings' => ['jobs' => ['events']],
            ],
        ],
    ]);
    $registry = new TopologyRegistry($scanner, [
        'physical_prefix' => '',
        'strict_topology' => false,
        'topology' => ['queues' => []],
    ]);

    expect(fn () => $registry->queues())
        ->toThrow(TopologyException::class, 'requires the direct dead-letter exchange');
});
