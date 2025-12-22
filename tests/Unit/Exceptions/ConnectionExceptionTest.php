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

    it('creates with host context', function () {
        $exception = new ConnectionException(
            'Connection failed',
            host: 'localhost',
            port: 5672,
            vhost: '/'
        );

        expect($exception->host)->toBe('localhost');
        expect($exception->port)->toBe(5672);
        expect($exception->vhost)->toBe('/');
    });

    it('chains previous exception', function () {
        $previous = new RuntimeException('Root cause');
        $exception = new ConnectionException('Connection failed', previous: $previous);

        expect($exception->getPrevious())->toBe($previous);
        expect($exception->getPrevious()->getMessage())->toBe('Root cause');
    });

    it('generates connection string', function () {
        $exception = new ConnectionException(
            'Connection failed',
            host: 'rabbitmq.local',
            port: 5672,
            vhost: '/myapp'
        );

        expect($exception->getConnectionString())->toBe('rabbitmq.local:5672/myapp');
    });

    it('returns null connection string when no host', function () {
        $exception = new ConnectionException('Connection failed');

        expect($exception->getConnectionString())->toBeNull();
    });

    it('creates exception with context factory method', function () {
        $previous = new RuntimeException('AMQP error');
        $exception = ConnectionException::withContext(
            'Failed to connect',
            host: 'localhost',
            port: 5672,
            vhost: '/',
            previous: $previous
        );

        expect($exception->getMessage())->toBe('Failed to connect');
        expect($exception->host)->toBe('localhost');
        expect($exception->getPrevious())->toBe($previous);
    });

    it('extends RuntimeException', function () {
        $exception = new ConnectionException;

        expect($exception)->toBeInstanceOf(RuntimeException::class);
    });
});
