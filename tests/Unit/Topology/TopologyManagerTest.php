<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Attributes\ConsumesQueue;
use Lettermint\RabbitMQ\Attributes\Exchange;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Topology\TopologyManager;
use Lettermint\RabbitMQ\Topology\TopologyRegistry;
use PhpAmqpLib\Wire\AMQPTable;

/** @param array<string, mixed> $overrides */
function topologyManagerConfig(array $overrides = []): array
{
    return array_replace_recursive([
        'connection' => 'broker',
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
                'default' => [
                    'bindings' => ['jobs' => ['default']],
                ],
                'events' => [
                    'bindings' => ['jobs' => ['events.*']],
                    'single_active_consumer' => true,
                    'delivery_limit' => 5,
                ],
            ],
        ],
    ], $overrides);
}

beforeEach(function () {
    $this->channel = mockAMQPChannel();
    $this->channelManager = Mockery::mock(ChannelManager::class);
    $this->channelManager->shouldReceive('topologyChannel')->with('broker')->andReturn($this->channel)->byDefault();
    $this->config = topologyManagerConfig();
    $this->registry = testTopologyRegistry($this->config);
    $this->manager = new TopologyManager($this->channelManager, $this->registry, $this->config);
});

test('reports the complete prefixed topology during a dry run', function () {
    $this->channelManager->shouldNotReceive('topologyChannel');

    $result = $this->manager->declare(dryRun: true);

    expect($result['exchanges'])->toBe(['staging.jobs', 'staging.dlx'])
        ->and($result['queues'])->toBe([
            'staging.default',
            'staging.dlq:default',
            'staging.events',
            'staging.dlq:events',
        ])
        ->and($result['bindings'])->toContain('staging.jobs -> staging.default [default]')
        ->and($result['bindings'])->toContain('staging.dlx -> staging.dlq:events [events]');
});

test('declares durable quorum queues and durable quorum dead-letter queues', function () {
    $mainArguments = null;
    $dlqArguments = null;

    $this->channel->shouldReceive('queue_declare')
        ->times(4)
        ->withArgs(function (string $queue, bool $passive, bool $durable, bool $exclusive, bool $autoDelete, bool $nowait, AMQPTable $arguments) use (&$dlqArguments, &$mainArguments): bool {
            if ($queue === 'staging.events') {
                $mainArguments = $arguments->getNativeData();
            }

            if ($queue === 'staging.dlq:events') {
                $dlqArguments = $arguments->getNativeData();
            }

            return ! $passive && $durable && ! $exclusive && ! $autoDelete && ! $nowait;
        });

    $this->manager->declare();

    expect($mainArguments)->toMatchArray([
        'x-queue-type' => 'quorum',
        'x-overflow' => 'reject-publish',
        'x-single-active-consumer' => true,
        'x-delivery-limit' => 5,
        'x-dead-letter-exchange' => 'staging.dlx',
        'x-dead-letter-routing-key' => 'events',
        'x-dead-letter-strategy' => 'at-least-once',
    ])->not->toHaveKey('x-message-ttl')
        ->and($dlqArguments)->toBe([
            'x-queue-type' => 'quorum',
            'x-overflow' => 'reject-publish',
        ]);
});

test('declares exchanges before queue bindings', function () {
    $this->channel->shouldReceive('exchange_declare')
        ->withArgs(fn (string $name): bool => $name === 'staging.jobs')
        ->once()
        ->ordered();
    $this->channel->shouldReceive('queue_bind')
        ->withArgs(fn (string $queue, string $exchange): bool => $queue === 'staging.default' && $exchange === 'staging.jobs')
        ->once()
        ->ordered();

    $this->manager->declare();
});

test('does not bind a queue to the default exchange', function () {
    $config = topologyManagerConfig();
    $config['topology']['queues'] = [
        'default' => [
            'bindings' => [],
            'default_exchange' => true,
            'dead_letter' => false,
        ],
    ];
    $manager = new TopologyManager(
        $this->channelManager,
        testTopologyRegistry($config),
        $config,
    );
    $this->channel->shouldNotReceive('queue_bind')->with('staging.default', '', 'staging.default');

    $result = $manager->declare();

    expect($result['bindings'])->toContain(' -> staging.default [staging.default]');
});

test('declaration does not delete or unbind broker topology', function () {
    $this->channel->shouldNotReceive('queue_delete');
    $this->channel->shouldNotReceive('exchange_delete');
    $this->channel->shouldNotReceive('queue_unbind');
    $this->channel->shouldNotReceive('exchange_unbind');

    $this->manager->declare();
});

test('declares exchange bindings after both exchanges exist', function () {
    $config = topologyManagerConfig([
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
    $manager = new TopologyManager(
        $this->channelManager,
        testTopologyRegistry($config),
        $config,
    );

    $this->channel->shouldReceive('exchange_bind')
        ->once()
        ->with('staging.child', 'staging.jobs', 'child.#');

    $result = $manager->declare();

    expect($result['bindings'])
        ->toContain('staging.jobs -> staging.child [child.#]');
});

test('purges and deletes the registered physical queue', function () {
    $this->channel->shouldReceive('queue_purge')->once()->with('staging.default')->andReturn(42);
    $this->channel->shouldReceive('queue_delete')->once()->with('staging.default');

    expect($this->manager->purgeQueue('default'))->toBe(42);
    $this->manager->deleteQueue('default');
});

test('gets queue information with a passive broker operation', function () {
    $this->channel->shouldReceive('queue_declare')
        ->once()
        ->with('staging.default', true, false, false, false)
        ->andReturn(['staging.default', 9, 2]);

    expect($this->manager->getQueueInfo('default'))->toBe([
        'name' => 'staging.default',
        'messages' => 9,
        'consumers' => 2,
    ]);
});

test('audits each main queue and dead-letter queue passively', function () {
    $this->channelManager->shouldReceive('channel')
        ->andReturn($this->channel)
        ->byDefault();
    $this->channel->shouldReceive('queue_declare')
        ->times(4)
        ->withArgs(fn (string $queue, bool $passive): bool => str_starts_with($queue, 'staging.') && $passive)
        ->andReturnUsing(fn (string $queue): array => [$queue, 0, str_contains($queue, 'dlq:') ? 0 : 1]);

    $result = $this->manager->audit();

    expect($result['healthy'])->toBeTrue()
        ->and($result['queues'])->toHaveCount(4)
        ->and($result['failures'])->toBe([]);
});

test('audits a dead-letter exchange that is derived from a discovered queue', function () {
    $scanner = Mockery::mock(AttributeScanner::class);
    $scanner->shouldReceive('getTopology')->andReturn([
        'exchanges' => [
            'jobs' => new Exchange('jobs', 'topic'),
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
    $config = [
        'connection' => 'broker',
        'physical_prefix' => 'staging.',
        'strict_topology' => false,
        'topology' => ['queues' => []],
    ];
    $registry = new TopologyRegistry($scanner, $config);
    $manager = new TopologyManager($this->channelManager, $registry, $config);

    $this->channelManager->shouldReceive('channel')->andReturn($this->channel)->byDefault();
    $this->channel->shouldReceive('exchange_declare')
        ->once()
        ->withArgs(fn (string $name, string $type, bool $passive): bool => $name === 'staging.jobs.dlq'
            && $type === 'direct'
            && $passive);
    $this->channel->shouldReceive('queue_declare')
        ->twice()
        ->andReturnUsing(fn (string $queue): array => [$queue, 0, 0]);

    expect($manager->audit()['healthy'])->toBeTrue();
});

test('reset permits a second declaration on the same manager', function () {
    $this->channel->shouldReceive('exchange_declare')->times(4);

    $this->manager->declare();
    $this->manager->reset();
    $this->manager->declare();
});
