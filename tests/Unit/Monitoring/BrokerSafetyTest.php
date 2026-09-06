<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Lettermint\RabbitMQ\Monitoring\BrokerTopologyAudit;
use Lettermint\RabbitMQ\Monitoring\ManagementClient;
use Lettermint\RabbitMQ\Monitoring\WorkerStatus;

test('DLQ access rejects a finite delivery limit even when a policy says unlimited', function () {
    config(['rabbitmq.management.url' => 'https://broker.example.test']);
    Http::fake(['*' => Http::response([
        'type' => 'quorum',
        'arguments' => ['x-delivery-limit' => 5],
        'effective_policy_definition' => ['delivery-limit' => -1],
    ])]);

    expect(fn () => app(ManagementClient::class)->assertSafeDeadLetterQueue('dlq:default'))
        ->toThrow(RuntimeException::class, 'effective delivery limit');
});

test('DLQ access accepts a verified policy on an existing queue without an argument', function () {
    config(['rabbitmq.management.url' => 'https://broker.example.test']);
    Http::fake(['*' => Http::response([
        'type' => 'quorum',
        'delivery_limit' => 'unlimited',
        'arguments' => ['x-overflow' => 'reject-publish'],
        'effective_policy_definition' => ['delivery-limit' => -1],
    ])]);

    app(ManagementClient::class)->assertSafeDeadLetterQueue('dlq:default');
    Http::assertSent(fn ($request) => $request->url() === 'https://broker.example.test/api/queues/%2F/dlq%3Adefault');
});

test('DLQ access rejects an operator policy that limits an unlimited queue', function () {
    config(['rabbitmq.management.url' => 'https://broker.example.test']);
    Http::fake(['*' => Http::response([
        'type' => 'quorum',
        'arguments' => ['x-delivery-limit' => -1],
        'operator_policy' => 'restricted',
        'effective_policy_definition' => ['delivery-limit' => 3],
    ])]);

    expect(fn () => app(ManagementClient::class)->assertSafeDeadLetterQueue('dlq:default'))
        ->toThrow(RuntimeException::class);
});

test('quorum safety checks apply the broker policy precedence', function () {
    $client = app(ManagementClient::class);
    $queue = ['type' => 'quorum', 'arguments' => ['x-overflow' => 'reject-publish', 'x-delivery-limit' => -1, 'x-message-ttl' => 1000],
        'effective_policy_definition' => ['overflow' => 'drop-head', 'delivery-limit' => 5, 'message-ttl' => 100]];
    expect($client->effectiveArgument($queue, 'x-overflow'))->toBe('drop-head')
        ->and($client->effectiveArgument($queue, 'x-delivery-limit'))->toBe(5)
        ->and($client->effectiveArgument($queue, 'x-message-ttl'))->toBe(100);
});

test('management errors do not expose credentials or response bodies', function () {
    config(['rabbitmq.management.url' => 'https://broker.example.test']);
    Http::fake(['*' => Http::response('private diagnostic text', 403)]);

    expect(fn () => app(ManagementClient::class)->queue('default'))
        ->toThrow(RuntimeException::class, 'RabbitMQ management read failed (HTTP 403).');
});

test('strict audit rejects a policy that can expire retained jobs', function () {
    $registry = testTopologyRegistry([
        'dead_letter' => ['enabled' => false],
        'topology' => [
            'exchanges' => ['jobs' => ['type' => 'direct']],
            'queues' => ['default' => ['bindings' => ['jobs' => ['default']]]],
        ],
    ]);
    $definition = $registry->queue('default');
    config(['rabbitmq.management.url' => 'https://broker.example.test']);
    Http::fake(['*' => Http::response([
        'type' => 'quorum', 'durable' => true, 'auto_delete' => false,
        'arguments' => $definition->queueArguments(),
        'effective_policy_definition' => ['message-ttl' => 1000],
    ])]);

    $failures = app(BrokerTopologyAudit::class)->check($registry, 'default');
    expect($failures)->toContain(['entity' => 'queue', 'name' => $definition->physicalName, 'error' => 'Unexpected queue argument or policy: x-message-ttl']);
});

test('worker health accepts a busy worker only within its job deadline', function () {
    $path = tempnam(sys_get_temp_dir(), 'rabbitmq-status-');
    try {
        file_put_contents($path, json_encode([
            'phase' => 'processing', 'registered' => true,
            'updated_at' => time() - 100, 'job_deadline' => time() + 30,
        ]));
        expect(app(WorkerStatus::class)->healthy($path, true))->toBeTrue();
        file_put_contents($path, json_encode([
            'phase' => 'processing', 'registered' => true,
            'updated_at' => time() - 100, 'job_deadline' => time() - 30,
        ]));
        expect(app(WorkerStatus::class)->healthy($path, false))->toBeFalse();
    } finally {
        unlink($path);
    }
});

test('worker readiness requires registration and rejects stopped or corrupt state', function () {
    $path = tempnam(sys_get_temp_dir(), 'rabbitmq-status-');
    config(['rabbitmq.consumer.status_file' => $path]);
    try {
        $status = app(WorkerStatus::class);
        $status->write('starting', false);
        expect($status->healthy($path, false))->toBeTrue()->and($status->healthy($path, true))->toBeFalse();
        $status->write('idle', true);
        expect($status->healthy($path, true))->toBeTrue();
        $status->write('stopping', false);
        expect($status->healthy($path, false))->toBeFalse();
        file_put_contents($path, 'broken');
        expect($status->healthy($path, false))->toBeFalse();
    } finally {
        unlink($path);
    }
});

test('strict audit checks an implicit dead-letter exchange', function () {
    $registry = testTopologyRegistry([
        'dead_letter' => ['enabled' => true, 'exchange' => 'dlx', 'queue_prefix' => 'dlq:'],
        'topology' => ['exchanges' => [], 'queues' => ['default' => []]],
    ]);
    config(['rabbitmq.management.url' => 'https://broker.example.test']);
    Http::fake(['*' => Http::response(['type' => 'fanout', 'durable' => true, 'auto_delete' => false, 'internal' => false])]);

    expect(app(BrokerTopologyAudit::class)->check($registry, 'default'))
        ->toContain(['entity' => 'exchange', 'name' => 'dlx', 'error' => 'Exchange property differs: type']);
});

test('worker status does not invent a deadline when a Laravel job disables its timeout', function () {
    $path = tempnam(sys_get_temp_dir(), 'rabbitmq-status-');
    try {
        file_put_contents($path, json_encode(['phase' => 'processing', 'registered' => true, 'updated_at' => time() - 1000, 'job_deadline' => null]));
        expect(app(WorkerStatus::class)->healthy($path, true))->toBeTrue();
    } finally {
        unlink($path);
    }
});
