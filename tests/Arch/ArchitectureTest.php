<?php

declare(strict_types=1);

arch('Source files use strict types')
    ->expect('Lettermint\RabbitMQ')
    ->toUseStrictTypes();

arch('Attributes are final')
    ->expect('Lettermint\RabbitMQ\Attributes')
    ->classes()
    ->toBeFinal();

arch('Exceptions extend RuntimeException')
    ->expect('Lettermint\RabbitMQ\Exceptions')
    ->classes()
    ->toExtend(RuntimeException::class);

arch('Enums are backed by strings')
    ->expect('Lettermint\RabbitMQ\Enums')
    ->toBeEnums();

arch('Console commands extend Illuminate Command')
    ->expect('Lettermint\RabbitMQ\Console\Commands')
    ->classes()
    ->toExtend(Illuminate\Console\Command::class);

arch('No debugging statements in source')
    ->expect('Lettermint\RabbitMQ')
    ->not->toUse(['dd', 'dump', 'ray', 'var_dump', 'print_r']);

arch('Service Provider is not final')
    ->expect('Lettermint\RabbitMQ\RabbitMQServiceProvider')
    ->not->toBeFinal();

arch('Queue implementation implements QueueContract')
    ->expect('Lettermint\RabbitMQ\Queue\RabbitMQQueue')
    ->toImplement(Illuminate\Contracts\Queue\Queue::class);

arch('Job implementation implements JobContract')
    ->expect('Lettermint\RabbitMQ\Queue\RabbitMQJob')
    ->toImplement(Illuminate\Contracts\Queue\Job::class);

arch('Connector implements ConnectorInterface')
    ->expect('Lettermint\RabbitMQ\Queue\RabbitMQConnector')
    ->toImplement(Illuminate\Queue\Connectors\ConnectorInterface::class);
