<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Exceptions\PublishException;

describe('PublishException', function () {
    it('creates with default message', function () {
        $exception = new PublishException;

        expect($exception->getMessage())->toBe('Failed to publish message to RabbitMQ');
        expect($exception->exchange)->toBeNull();
        expect($exception->routingKey)->toBeNull();
    });

    it('creates with custom message', function () {
        $exception = new PublishException('Custom publish error');

        expect($exception->getMessage())->toBe('Custom publish error');
    });

    it('stores exchange property', function () {
        $exception = new PublishException(
            message: 'Publish failed',
            exchange: 'emails.outbound'
        );

        expect($exception->exchange)->toBe('emails.outbound');
    });

    it('stores routing key property', function () {
        $exception = new PublishException(
            message: 'Publish failed',
            routingKey: 'transactional.notification'
        );

        expect($exception->routingKey)->toBe('transactional.notification');
    });

    it('stores both exchange and routing key', function () {
        $exception = new PublishException(
            message: 'Publish failed',
            exchange: 'notifications',
            routingKey: 'push.urgent'
        );

        expect($exception->exchange)->toBe('notifications');
        expect($exception->routingKey)->toBe('push.urgent');
    });

    it('chains previous exception', function () {
        $previous = new RuntimeException('AMQP error');
        $exception = new PublishException(
            message: 'Publish failed',
            exchange: 'test',
            routingKey: 'key',
            previous: $previous
        );

        expect($exception->getPrevious())->toBe($previous);
    });

    it('extends RuntimeException', function () {
        $exception = new PublishException;

        expect($exception)->toBeInstanceOf(RuntimeException::class);
    });
});
