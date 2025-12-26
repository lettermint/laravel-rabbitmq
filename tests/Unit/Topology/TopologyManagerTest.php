<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Attributes\ConsumesQueue;
use Lettermint\RabbitMQ\Attributes\Exchange;
use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Enums\ExchangeType;
use Lettermint\RabbitMQ\Topology\TopologyManager;

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
            'queues' => ['emails:outbound' => ['attribute' => $queueAttr, 'class' => 'TestJob']],
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
            'queues' => ['notifications:push' => ['attribute' => $queueAttr, 'class' => 'TestJob']],
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
            'queues' => ['emails:outbound' => ['attribute' => $queueAttr, 'class' => 'TestJob']],
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
