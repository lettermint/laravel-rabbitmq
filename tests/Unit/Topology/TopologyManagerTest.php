<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Attributes\ConsumesQueue;
use Lettermint\RabbitMQ\Attributes\Exchange;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Enums\ExchangeType;
use Lettermint\RabbitMQ\Topology\TopologyManager;
use PhpAmqpLib\Wire\AMQPTable;

beforeEach(function () {
    $this->mockChannel = mockAMQPChannel();

    $this->channelManager = Mockery::mock(ChannelManager::class);
    $this->channelManager->shouldReceive('topologyChannel')
        ->andReturn($this->mockChannel)
        ->byDefault();

    $this->scanner = Mockery::mock(AttributeScanner::class);
    $this->scanner->shouldReceive('getTopology')
        ->andReturn(['exchanges' => [], 'queues' => []])
        ->byDefault();

    $this->config = [
        'delayed' => [
            'enabled' => false,
        ],
        'dead_letter' => [
            'default_ttl' => 604800000,
        ],
    ];
});

test('performs dry run without making changes', function () {
    $exchangeAttr = new Exchange(name: 'emails', type: ExchangeType::Topic);
    $queueAttr = new ConsumesQueue(
        queue: 'emails:outbound',
        bindings: ['emails' => 'outbound.*']
    );

    $this->scanner->shouldReceive('getTopology')
        ->andReturn([
            'exchanges' => ['emails' => $exchangeAttr],
            'queues' => ['emails:outbound' => ['attribute' => $queueAttr, 'class' => 'TestJob', 'allBindings' => $queueAttr->bindings]],
        ]);

    $manager = new TopologyManager(
        $this->channelManager,
        $this->scanner,
        $this->config
    );

    // Channel should NOT be accessed in dry run
    $this->channelManager->shouldNotReceive('topologyChannel');

    $result = $manager->declare(dryRun: true);

    expect($result['exchanges'])->toContain('emails');
    expect($result['exchanges'])->toContain('emails.dlq');
    expect($result['queues'])->toContain('emails:outbound');
    expect($result['bindings'])->not->toBeEmpty();
});

test('declares delayed exchange when enabled', function () {
    $config = [
        'delayed' => [
            'enabled' => true,
            'exchange' => 'custom-delayed',
        ],
    ];

    $this->scanner->shouldReceive('getTopology')
        ->andReturn(['exchanges' => [], 'queues' => []]);

    $manager = new TopologyManager(
        $this->channelManager,
        $this->scanner,
        $config
    );

    $result = $manager->declare(dryRun: true);

    expect($result['exchanges'])->toContain('custom-delayed (x-delayed-message)');
});

test('skips delayed exchange when disabled', function () {
    $manager = new TopologyManager(
        $this->channelManager,
        $this->scanner,
        $this->config
    );

    $result = $manager->declare(dryRun: true);

    $hasDelayed = collect($result['exchanges'])
        ->contains(fn ($e) => str_contains($e, 'delayed'));

    expect($hasDelayed)->toBeFalse();
});

test('includes exchange-to-exchange binding in result', function () {
    $parentExchange = new Exchange(name: 'parent', type: ExchangeType::Topic);
    $childExchange = new Exchange(
        name: 'child',
        type: ExchangeType::Topic,
        bindTo: 'parent',
        bindRoutingKey: 'child.#'
    );

    $this->scanner->shouldReceive('getTopology')
        ->andReturn([
            'exchanges' => [
                'parent' => $parentExchange,
                'child' => $childExchange,
            ],
            'queues' => [],
        ]);

    $manager = new TopologyManager(
        $this->channelManager,
        $this->scanner,
        $this->config
    );

    $result = $manager->declare(dryRun: true);

    expect($result['bindings'])->toContain('parent -> child [child.#]');
});

test('includes queue bindings in result', function () {
    $queueAttr = new ConsumesQueue(
        queue: 'notifications:push',
        bindings: ['notifications' => ['push.high', 'push.low']]
    );

    $this->scanner->shouldReceive('getTopology')
        ->andReturn([
            'exchanges' => [],
            'queues' => ['notifications:push' => ['attribute' => $queueAttr, 'class' => 'TestJob', 'allBindings' => $queueAttr->bindings]],
        ]);

    $manager = new TopologyManager(
        $this->channelManager,
        $this->scanner,
        $this->config
    );

    $result = $manager->declare(dryRun: true);

    expect($result['bindings'])->toContain('notifications -> notifications:push [push.high]');
    expect($result['bindings'])->toContain('notifications -> notifications:push [push.low]');
});

test('auto-creates DLQ exchange', function () {
    $exchangeAttr = new Exchange(name: 'emails', type: ExchangeType::Topic);

    $this->scanner->shouldReceive('getTopology')
        ->andReturn([
            'exchanges' => ['emails' => $exchangeAttr],
            'queues' => [],
        ]);

    $manager = new TopologyManager(
        $this->channelManager,
        $this->scanner,
        $this->config
    );

    $result = $manager->declare(dryRun: true);

    expect($result['exchanges'])->toContain('emails.dlq');
});

test('auto-creates DLQ queue', function () {
    $queueAttr = new ConsumesQueue(
        queue: 'emails:outbound',
        bindings: ['emails' => 'outbound.*']
    );

    $this->scanner->shouldReceive('getTopology')
        ->andReturn([
            'exchanges' => [],
            'queues' => ['emails:outbound' => ['attribute' => $queueAttr, 'class' => 'TestJob', 'allBindings' => $queueAttr->bindings]],
        ]);

    $manager = new TopologyManager(
        $this->channelManager,
        $this->scanner,
        $this->config
    );

    $result = $manager->declare(dryRun: true);

    expect($result['queues'])->toContain('dlq:emails:outbound');
    expect($result['bindings'])->toContain('emails.dlq -> dlq:emails:outbound [emails.outbound]');
});

test('declares the DLX exchange for a queue whose DLX derives from its bindings', function () {
    // No matching Exchange attribute, so the exchange loop does not create the
    // DLX. declareDlqQueue must declare 'orders.dlq' itself, otherwise the
    // queue's x-dead-letter-exchange (and x-delivery-limit parking) would target
    // an undeclared exchange and messages would be dropped.
    $queueAttr = new ConsumesQueue(
        queue: 'orders:process',
        bindings: ['orders' => 'process.*'],
        quorum: true,
        deliveryLimit: 3,
    );

    $this->scanner->shouldReceive('getTopology')->andReturn([
        'exchanges' => [],
        'queues' => ['orders:process' => ['attribute' => $queueAttr, 'class' => 'TestJob', 'allBindings' => $queueAttr->bindings]],
    ]);

    // The DLX exchange must be declared BEFORE the DLQ queue is declared and
    // bound to it — ordered() guards that sequence, not just its presence.
    $this->mockChannel->shouldReceive('exchange_declare')
        ->withArgs(fn ($name, $type) => $name === 'orders.dlq' && $type === 'direct')
        ->once()
        ->ordered();

    $this->mockChannel->shouldReceive('queue_declare')
        ->withArgs(function ($name, $passive, $durable, $exclusive, $autoDelete, $nowait, $arguments): bool {
            expect($arguments)->toBeInstanceOf(AMQPTable::class)
                ->and($arguments->getNativeData())->not->toHaveKey('x-message-ttl');

            return $name === 'dlq:orders:process';
        })
        ->once()
        ->ordered()
        ->andReturn(['dlq:orders:process', 0, 0]);

    $this->mockChannel->shouldReceive('queue_bind')
        ->withArgs(fn ($queue, $exchange) => $queue === 'dlq:orders:process' && $exchange === 'orders.dlq')
        ->once()
        ->ordered();

    $manager = new TopologyManager(
        $this->channelManager,
        $this->scanner,
        $this->config
    );

    $manager->declare(dryRun: false);

    // Expectations verified on teardown by Mockery.
    expect(true)->toBeTrue();
});

test('purges queue', function () {
    // Mock channel to return purge count
    $this->mockChannel->shouldReceive('queue_purge')
        ->with('test-queue')
        ->once()
        ->andReturn(42);

    $manager = new TopologyManager(
        $this->channelManager,
        $this->scanner,
        $this->config
    );

    $count = $manager->purgeQueue('test-queue');

    expect($count)->toBe(42);
});

test('deletes queue', function () {
    // Mock channel queue_delete
    $this->mockChannel->shouldReceive('queue_delete')
        ->with('test-queue')
        ->once();

    $manager = new TopologyManager(
        $this->channelManager,
        $this->scanner,
        $this->config
    );

    $manager->deleteQueue('test-queue');

    expect(true)->toBeTrue();
});

test('clears declared exchanges and queues tracking on reset', function () {
    $manager = new TopologyManager(
        $this->channelManager,
        $this->scanner,
        $this->config
    );

    // Use reflection to verify internal state
    $reflection = new ReflectionClass($manager);

    $exchangesProp = $reflection->getProperty('declaredExchanges');
    $exchangesProp->setAccessible(true);
    $exchangesProp->setValue($manager, ['test-exchange' => true]);

    $queuesProp = $reflection->getProperty('declaredQueues');
    $queuesProp->setAccessible(true);
    $queuesProp->setValue($manager, ['test-queue' => true]);

    $manager->reset();

    expect($exchangesProp->getValue($manager))->toBeEmpty();
    expect($queuesProp->getValue($manager))->toBeEmpty();
});
