<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Lettermint\RabbitMQ\Consumers\RabbitMQWorker;

beforeEach(function () {
    $this->restartCache = new Repository(new ArrayStore);
    $this->worker = app(RabbitMQWorker::class);
    $this->worker->setCache($this->restartCache);
});

test('starts a session and detects a later restart request', function (int|string|null $timestamp) {
    if ($timestamp !== null) {
        $this->restartCache->forever('illuminate:queue:restart', $timestamp);
    }

    $this->worker->startSession();

    expect($this->worker->restartRequested())->toBeFalse();

    $this->restartCache->forever('illuminate:queue:restart', '1700000001');

    expect($this->worker->restartRequested())->toBeTrue();
})->with([
    'missing marker' => [null],
    'integer marker' => [1700000000],
    'string marker' => ['1700000000'],
]);

test('does not request a restart when only the cache value type changes', function (int|string $timestamp, int|string $equivalent) {
    $this->restartCache->forever('illuminate:queue:restart', $timestamp);
    $this->worker->startSession();

    $this->restartCache->forever('illuminate:queue:restart', $equivalent);

    expect($this->worker->restartRequested())->toBeFalse();
})->with([
    'integer to string' => [1700000000, '1700000000'],
    'string to integer' => ['1700000000', 1700000000],
]);

test('a new session resets the quit flag and reads the current restart marker', function () {
    $this->worker->startSession();
    $this->worker->shouldQuit = true;

    expect($this->worker->restartRequested())->toBeTrue();

    $this->restartCache->forever('illuminate:queue:restart', '1700000000');
    $this->worker->startSession();

    expect($this->worker->restartRequested())->toBeFalse();
});
