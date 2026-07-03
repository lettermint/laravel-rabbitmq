<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Consumers\Consumer;
use Mockery\MockInterface;

function fakeConsumer(): MockInterface
{
    $consumer = Mockery::mock(Consumer::class);

    // Every fluent setter returns the consumer itself.
    $consumer->shouldReceive(
        'setQueues',
        'setConnection',
        'setPrefetch',
        'setTimeout',
        'setMaxJobs',
        'setMaxTime',
        'setMaxMemory',
        'setSleep',
        'setTries',
        'setRest',
        'setStopWhenEmpty',
    )->andReturnSelf()->byDefault();

    $consumer->shouldReceive('consume')->andReturnNull()->byDefault();

    return $consumer;
}

it('forwards multiple queue arguments to the consumer', function () {
    $consumer = fakeConsumer();
    $consumer->shouldReceive('setQueues')->with(['default', 'reporting'])->once()->andReturnSelf();
    $consumer->shouldReceive('consume')->once();

    app()->instance(Consumer::class, $consumer);

    $this->artisan('rabbitmq:consume', [
        'queue' => ['default', 'reporting'],
        '--stop-when-empty' => true,
    ])->assertExitCode(0);
});

it('accepts a single queue argument', function () {
    $consumer = fakeConsumer();
    $consumer->shouldReceive('setQueues')->with(['default'])->once()->andReturnSelf();
    $consumer->shouldReceive('consume')->once();

    app()->instance(Consumer::class, $consumer);

    $this->artisan('rabbitmq:consume', [
        'queue' => ['default'],
        '--stop-when-empty' => true,
    ])->assertExitCode(0);
});
