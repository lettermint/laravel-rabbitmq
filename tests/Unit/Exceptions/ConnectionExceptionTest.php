<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Exceptions\ConnectionException;

describe('ConnectionException', function () {
    it('creates with default message', function () {
        $exception = new ConnectionException;

        expect($exception->getMessage())->toBe('RabbitMQ connection failed');
        expect($exception->getCode())->toBe(0);
        expect($exception->getPrevious())->toBeNull();
    });

    it('creates with custom message', function () {
        $exception = new ConnectionException('Custom connection error');

        expect($exception->getMessage())->toBe('Custom connection error');
    });

    it('creates with custom code', function () {
        $exception = new ConnectionException('Error', 42);

        expect($exception->getCode())->toBe(42);
    });

    it('chains previous exception', function () {
        $previous = new RuntimeException('Root cause');
        $exception = new ConnectionException('Connection failed', 0, $previous);

        expect($exception->getPrevious())->toBe($previous);
        expect($exception->getPrevious()->getMessage())->toBe('Root cause');
    });

    it('extends RuntimeException', function () {
        $exception = new ConnectionException;

        expect($exception)->toBeInstanceOf(RuntimeException::class);
    });
});
