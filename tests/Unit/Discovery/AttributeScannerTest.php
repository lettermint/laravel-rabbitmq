<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Attributes\ConsumesQueue;
use Lettermint\RabbitMQ\Attributes\Exchange;
use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Enums\ExchangeType;
use Lettermint\RabbitMQ\Tests\Fixtures\Exchanges\ChildExchange;
use Lettermint\RabbitMQ\Tests\Fixtures\Exchanges\MainExchange;
use Lettermint\RabbitMQ\Tests\Fixtures\Jobs\ComplexJob;
use Lettermint\RabbitMQ\Tests\Fixtures\Jobs\MultiQueueJob;
use Lettermint\RabbitMQ\Tests\Fixtures\Jobs\NoAttributeJob;
use Lettermint\RabbitMQ\Tests\Fixtures\Jobs\PriorityJob;
use Lettermint\RabbitMQ\Tests\Fixtures\Jobs\SimpleJob;

beforeEach(function () {
    $this->scanner = new AttributeScanner;
});

test('scans directory for Exchange attributes', function () {
    $this->scanner->scan([__DIR__.'/../../Fixtures/Exchanges']);

    $exchanges = $this->scanner->getExchanges();

    expect($exchanges)->toHaveCount(3);
    expect($exchanges->pluck('class')->toArray())->toContain(MainExchange::class);
    expect($exchanges->pluck('class')->toArray())->toContain(ChildExchange::class);
});

test('scans directory for ConsumesQueue attributes', function () {
    $this->scanner->scan([__DIR__.'/../../Fixtures/Jobs']);

    $queues = $this->scanner->getQueues();

    // SimpleJob, PriorityJob, ComplexJob have 1 attribute each
    // MultiQueueJob has 2 attributes (repeatable)
    // NoAttributeJob has 0
    expect($queues->count())->toBeGreaterThanOrEqual(5);
});

test('handles multiple attributes on same class', function () {
    $this->scanner->scan([__DIR__.'/../../Fixtures/Jobs']);

    $multiQueueAttributes = $this->scanner->getQueues()
        ->filter(fn ($item) => $item['class'] === MultiQueueJob::class);

    expect($multiQueueAttributes)->toHaveCount(2);
});

test('ignores non-existent directories', function () {
    $this->scanner->scan(['/non/existent/path']);

    expect($this->scanner->getExchanges())->toBeEmpty();
    expect($this->scanner->getQueues())->toBeEmpty();
});

test('ignores classes without attributes', function () {
    $this->scanner->scan([__DIR__.'/../../Fixtures/Jobs']);

    $noAttrJobs = $this->scanner->getQueues()
        ->filter(fn ($item) => $item['class'] === NoAttributeJob::class);

    expect($noAttrJobs)->toBeEmpty();
});

test('resets state between scans', function () {
    $this->scanner->scan([__DIR__.'/../../Fixtures/Exchanges']);
    expect($this->scanner->getExchanges()->count())->toBeGreaterThan(0);

    $this->scanner->scan([]);
    expect($this->scanner->getExchanges())->toBeEmpty();
});

test('getQueueForJob returns queue attribute for job class', function () {
    $this->scanner->scan([__DIR__.'/../../Fixtures/Jobs']);

    $attribute = $this->scanner->getQueueForJob(SimpleJob::class);

    expect($attribute)->toBeInstanceOf(ConsumesQueue::class);
    expect($attribute->queue)->toBe('emails:outbound');
});

test('getQueueForJob returns null for job without attribute', function () {
    $this->scanner->scan([__DIR__.'/../../Fixtures/Jobs']);

    $attribute = $this->scanner->getQueueForJob(NoAttributeJob::class);

    expect($attribute)->toBeNull();
});

test('getQueueForJob returns null for non-existent class', function () {
    $this->scanner->scan([__DIR__.'/../../Fixtures/Jobs']);

    $attribute = $this->scanner->getQueueForJob('NonExistent\\Class');

    expect($attribute)->toBeNull();
});

test('getQueueForJob matches queue attribute by queue name for multi-queue jobs', function () {
    $this->scanner->scan([__DIR__.'/../../Fixtures/Jobs']);

    $transactional = $this->scanner->getQueueForJob(
        MultiQueueJob::class,
        'notifications:transactional'
    );

    expect($transactional)->toBeInstanceOf(ConsumesQueue::class);
    expect($transactional->queue)->toBe('notifications:transactional');

    $broadcast = $this->scanner->getQueueForJob(
        MultiQueueJob::class,
        'notifications:broadcast'
    );

    expect($broadcast)->toBeInstanceOf(ConsumesQueue::class);
    expect($broadcast->queue)->toBe('notifications:broadcast');
});

test('getQueueForJob returns first attribute when no queue specified for multi-queue job', function () {
    $this->scanner->scan([__DIR__.'/../../Fixtures/Jobs']);

    $attribute = $this->scanner->getQueueForJob(MultiQueueJob::class);

    expect($attribute)->toBeInstanceOf(ConsumesQueue::class);
    // Returns first attribute (order not guaranteed, but one of them)
    expect(['notifications:transactional', 'notifications:broadcast'])
        ->toContain($attribute->queue);
});

test('getQueueForJob falls back to first attribute when queue not matched', function () {
    $this->scanner->scan([__DIR__.'/../../Fixtures/Jobs']);

    $attribute = $this->scanner->getQueueForJob(
        MultiQueueJob::class,
        'non:matching:queue'
    );

    expect($attribute)->toBeInstanceOf(ConsumesQueue::class);
});

test('getTopology builds complete topology representation', function () {
    $this->scanner->scan([
        __DIR__.'/../../Fixtures/Exchanges',
        __DIR__.'/../../Fixtures/Jobs',
    ]);

    $topology = $this->scanner->getTopology();

    expect($topology)->toHaveKeys(['exchanges', 'queues']);
    expect($topology['exchanges'])->not->toBeEmpty();
    expect($topology['queues'])->not->toBeEmpty();
});

test('getTopology returns exchanges indexed by name', function () {
    $this->scanner->scan([__DIR__.'/../../Fixtures/Exchanges']);

    $topology = $this->scanner->getTopology();

    expect($topology['exchanges'])->toHaveKey('emails');
    expect($topology['exchanges']['emails'])->toBeInstanceOf(Exchange::class);
});

test('getTopology returns queues indexed by queue name', function () {
    $this->scanner->scan([__DIR__.'/../../Fixtures/Jobs']);

    $topology = $this->scanner->getTopology();

    expect($topology['queues'])->toHaveKey('emails:outbound');
    expect($topology['queues']['emails:outbound'])->toHaveKeys(['attribute', 'class']);
    expect($topology['queues']['emails:outbound']['attribute'])
        ->toBeInstanceOf(ConsumesQueue::class);
});

test('correctly instantiates Exchange attribute', function () {
    $this->scanner->scan([
        __DIR__.'/../../Fixtures/Exchanges',
        __DIR__.'/../../Fixtures/Jobs',
    ]);

    $topology = $this->scanner->getTopology();

    $mainExchange = $topology['exchanges']['emails'];

    expect($mainExchange->name)->toBe('emails');
    expect($mainExchange->typeEnum)->toBe(ExchangeType::Topic);
});

test('correctly instantiates Exchange with bindTo', function () {
    $this->scanner->scan([
        __DIR__.'/../../Fixtures/Exchanges',
        __DIR__.'/../../Fixtures/Jobs',
    ]);

    $topology = $this->scanner->getTopology();

    $childExchange = $topology['exchanges']['emails.outbound'];

    expect($childExchange->bindTo)->toBe('emails');
    expect($childExchange->bindRoutingKey)->toBe('outbound.#');
});

test('correctly instantiates ConsumesQueue with complex options', function () {
    $this->scanner->scan([
        __DIR__.'/../../Fixtures/Exchanges',
        __DIR__.'/../../Fixtures/Jobs',
    ]);

    $attribute = $this->scanner->getQueueForJob(ComplexJob::class);

    expect($attribute->queue)->toBe('complex:processing');
    expect($attribute->quorum)->toBeTrue();
    expect($attribute->messageTtl)->toBe(86400000);
    expect($attribute->maxLength)->toBe(10000);
    expect($attribute->retryAttempts)->toBe(5);
    expect($attribute->prefetch)->toBe(20);
    expect($attribute->timeout)->toBe(120);
});

test('correctly instantiates PriorityJob queue', function () {
    $this->scanner->scan([
        __DIR__.'/../../Fixtures/Exchanges',
        __DIR__.'/../../Fixtures/Jobs',
    ]);

    $attribute = $this->scanner->getQueueForJob(PriorityJob::class);

    expect($attribute->quorum)->toBeFalse();
    expect($attribute->maxPriority)->toBe(10);
});
