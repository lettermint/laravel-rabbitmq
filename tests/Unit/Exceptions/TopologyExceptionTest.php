<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Exceptions\TopologyException;

describe('TopologyException', function () {
    it('creates with default message', function () {
        $exception = new TopologyException;

        expect($exception->getMessage())->toBe('RabbitMQ topology operation failed');
        expect($exception->entityType)->toBeNull();
        expect($exception->entityName)->toBeNull();
    });

    it('creates with custom message', function () {
        $exception = new TopologyException('Custom topology error');

        expect($exception->getMessage())->toBe('Custom topology error');
    });

    it('stores entity type property', function () {
        $exception = new TopologyException(
            message: 'Declaration failed',
            entityType: 'exchange'
        );

        expect($exception->entityType)->toBe('exchange');
    });

    it('stores entity name property', function () {
        $exception = new TopologyException(
            message: 'Declaration failed',
            entityName: 'emails.outbound'
        );

        expect($exception->entityName)->toBe('emails.outbound');
    });

    it('stores both entity type and name', function () {
        $exception = new TopologyException(
            message: 'Failed to declare queue',
            entityType: 'queue',
            entityName: 'notifications:transactional'
        );

        expect($exception->entityType)->toBe('queue');
        expect($exception->entityName)->toBe('notifications:transactional');
    });

    it('chains previous exception', function () {
        $previous = new RuntimeException('AMQP channel error');
        $exception = new TopologyException(
            message: 'Topology failed',
            entityType: 'binding',
            entityName: 'test-binding',
            previous: $previous
        );

        expect($exception->getPrevious())->toBe($previous);
    });

    it('extends RuntimeException', function () {
        $exception = new TopologyException;

        expect($exception)->toBeInstanceOf(RuntimeException::class);
    });
});
