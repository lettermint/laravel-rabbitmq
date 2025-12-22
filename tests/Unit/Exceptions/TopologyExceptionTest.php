<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Enums\TopologyEntityType;
use Lettermint\RabbitMQ\Exceptions\TopologyException;

describe('TopologyException', function () {
    it('creates with default message', function () {
        $exception = new TopologyException;

        expect($exception->getMessage())->toBe('RabbitMQ topology operation failed');
        expect($exception->entityTypeEnum)->toBeNull();
        expect($exception->entityName)->toBeNull();
    });

    it('creates with custom message', function () {
        $exception = new TopologyException('Custom topology error');

        expect($exception->getMessage())->toBe('Custom topology error');
    });

    it('stores entity type as enum', function () {
        $exception = new TopologyException(
            message: 'Declaration failed',
            entityType: TopologyEntityType::Exchange
        );

        expect($exception->entityTypeEnum)->toBe(TopologyEntityType::Exchange);
        expect($exception->getEntityType())->toBe('exchange');
    });

    it('stores entity type from string', function () {
        $exception = new TopologyException(
            message: 'Declaration failed',
            entityType: 'queue'
        );

        expect($exception->entityTypeEnum)->toBe(TopologyEntityType::Queue);
        expect($exception->getEntityType())->toBe('queue');
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
            entityType: TopologyEntityType::Queue,
            entityName: 'notifications:transactional'
        );

        expect($exception->entityTypeEnum)->toBe(TopologyEntityType::Queue);
        expect($exception->entityName)->toBe('notifications:transactional');
    });

    it('chains previous exception', function () {
        $previous = new RuntimeException('AMQP channel error');
        $exception = new TopologyException(
            message: 'Topology failed',
            entityType: TopologyEntityType::Binding,
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
