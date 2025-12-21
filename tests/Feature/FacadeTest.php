<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Facades\RabbitMQ;

describe('RabbitMQ Facade', function () {
    it('resolves facade accessor', function () {
        expect(RabbitMQ::getFacadeRoot())->not->toBeNull();
    });
});
