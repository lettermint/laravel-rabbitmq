<?php

declare(strict_types=1);
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Connectors\ConnectorInterface;

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
    ->toExtend(Command::class);

arch('No debugging statements in source')
    ->expect('Lettermint\RabbitMQ')
    ->not->toUse(['dd', 'dump', 'ray', 'var_dump', 'print_r']);

arch('Service Provider is not final')
    ->expect('Lettermint\RabbitMQ\RabbitMQServiceProvider')
    ->not->toBeFinal();

arch('Queue implementation implements QueueContract')
    ->expect('Lettermint\RabbitMQ\Queue\RabbitMQQueue')
    ->toImplement(Queue::class);

arch('Job implementation implements JobContract')
    ->expect('Lettermint\RabbitMQ\Queue\RabbitMQJob')
    ->toImplement(Job::class);

arch('Connector implements ConnectorInterface')
    ->expect('Lettermint\RabbitMQ\Queue\RabbitMQConnector')
    ->toImplement(ConnectorInterface::class);
