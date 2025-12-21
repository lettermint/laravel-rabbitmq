<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Tests\Mocks;

use AMQPChannel;
use AMQPConnection;
use AMQPEnvelope;
use AMQPExchange;
use AMQPQueue;
use Mockery;
use Mockery\MockInterface;

/**
 * Factory for creating mock AMQP extension classes.
 *
 * Use these mocks to test RabbitMQ functionality without a real connection.
 */
class AMQPMocks
{
    /**
     * Create a mock AMQPConnection.
     */
    public static function connection(bool $connected = true): MockInterface
    {
        $mock = Mockery::mock(AMQPConnection::class);

        $mock->shouldReceive('isConnected')->andReturn($connected)->byDefault();
        $mock->shouldReceive('connect')->andReturn(true)->byDefault();
        $mock->shouldReceive('pconnect')->andReturn(true)->byDefault();
        $mock->shouldReceive('reconnect')->andReturn(true)->byDefault();
        $mock->shouldReceive('preconnect')->andReturn(true)->byDefault();
        $mock->shouldReceive('disconnect')->andReturn(true)->byDefault();
        $mock->shouldReceive('pdisconnect')->andReturn(true)->byDefault();

        $mock->shouldReceive('getHost')->andReturn('localhost')->byDefault();
        $mock->shouldReceive('getPort')->andReturn(5672)->byDefault();
        $mock->shouldReceive('getLogin')->andReturn('guest')->byDefault();
        $mock->shouldReceive('getVhost')->andReturn('/')->byDefault();

        $mock->shouldReceive('setHost')->andReturnSelf()->byDefault();
        $mock->shouldReceive('setPort')->andReturnSelf()->byDefault();
        $mock->shouldReceive('setLogin')->andReturnSelf()->byDefault();
        $mock->shouldReceive('setPassword')->andReturnSelf()->byDefault();
        $mock->shouldReceive('setVhost')->andReturnSelf()->byDefault();
        $mock->shouldReceive('setReadTimeout')->andReturnSelf()->byDefault();
        $mock->shouldReceive('setWriteTimeout')->andReturnSelf()->byDefault();
        $mock->shouldReceive('setConnectionTimeout')->andReturnSelf()->byDefault();
        $mock->shouldReceive('setHeartbeat')->andReturnSelf()->byDefault();
        $mock->shouldReceive('setRpcTimeout')->andReturnSelf()->byDefault();

        return $mock;
    }

    /**
     * Create a mock AMQPChannel.
     */
    public static function channel(?MockInterface $connection = null): MockInterface
    {
        $mock = Mockery::mock(AMQPChannel::class);

        $mock->shouldReceive('isConnected')->andReturn(true)->byDefault();
        $mock->shouldReceive('getConnection')->andReturn($connection ?? self::connection())->byDefault();

        // Prefetch / QoS
        $mock->shouldReceive('setPrefetchCount')->andReturn(true)->byDefault();
        $mock->shouldReceive('setPrefetchSize')->andReturn(true)->byDefault();
        $mock->shouldReceive('qos')->andReturn(true)->byDefault();
        $mock->shouldReceive('getPrefetchCount')->andReturn(10)->byDefault();
        $mock->shouldReceive('getPrefetchSize')->andReturn(0)->byDefault();

        // Publisher confirms
        $mock->shouldReceive('confirmSelect')->andReturn(true)->byDefault();
        $mock->shouldReceive('setConfirmCallback')->andReturn(true)->byDefault();
        $mock->shouldReceive('waitForConfirm')->andReturn(true)->byDefault();
        $mock->shouldReceive('setReturnCallback')->andReturn(true)->byDefault();

        // Transactions
        $mock->shouldReceive('startTransaction')->andReturn(true)->byDefault();
        $mock->shouldReceive('commitTransaction')->andReturn(true)->byDefault();
        $mock->shouldReceive('rollbackTransaction')->andReturn(true)->byDefault();

        // Basic operations
        $mock->shouldReceive('basicRecover')->andReturn(true)->byDefault();
        $mock->shouldReceive('close')->andReturn(true)->byDefault();

        return $mock;
    }

    /**
     * Create a mock AMQPQueue.
     */
    public static function queue(string $name = 'test-queue', ?MockInterface $channel = null): MockInterface
    {
        $mock = Mockery::mock(AMQPQueue::class);

        $mock->shouldReceive('getName')->andReturn($name)->byDefault();
        $mock->shouldReceive('setName')->andReturn(true)->byDefault();
        $mock->shouldReceive('getChannel')->andReturn($channel ?? self::channel())->byDefault();

        // Flags and arguments
        $mock->shouldReceive('getFlags')->andReturn(\AMQP_DURABLE)->byDefault();
        $mock->shouldReceive('setFlags')->andReturn(true)->byDefault();
        $mock->shouldReceive('getArguments')->andReturn([])->byDefault();
        $mock->shouldReceive('setArguments')->andReturn(true)->byDefault();
        $mock->shouldReceive('setArgument')->andReturn(true)->byDefault();

        // Declaration
        $mock->shouldReceive('declareQueue')->andReturn(0)->byDefault();
        $mock->shouldReceive('delete')->andReturn(0)->byDefault();
        $mock->shouldReceive('purge')->andReturn(0)->byDefault();

        // Binding
        $mock->shouldReceive('bind')->andReturn(true)->byDefault();
        $mock->shouldReceive('unbind')->andReturn(true)->byDefault();

        // Consuming
        $mock->shouldReceive('get')->andReturn(null)->byDefault();
        $mock->shouldReceive('consume')->andReturn(true)->byDefault();
        $mock->shouldReceive('cancel')->andReturn(true)->byDefault();

        // Acknowledgement
        $mock->shouldReceive('ack')->andReturn(true)->byDefault();
        $mock->shouldReceive('nack')->andReturn(true)->byDefault();
        $mock->shouldReceive('reject')->andReturn(true)->byDefault();

        return $mock;
    }

    /**
     * Create a mock AMQPExchange.
     */
    public static function exchange(string $name = 'test-exchange', ?MockInterface $channel = null): MockInterface
    {
        $mock = Mockery::mock(AMQPExchange::class);

        $mock->shouldReceive('getName')->andReturn($name)->byDefault();
        $mock->shouldReceive('setName')->andReturn(true)->byDefault();
        $mock->shouldReceive('getChannel')->andReturn($channel ?? self::channel())->byDefault();

        // Type
        $mock->shouldReceive('getType')->andReturn('topic')->byDefault();
        $mock->shouldReceive('setType')->andReturn(true)->byDefault();

        // Flags and arguments
        $mock->shouldReceive('getFlags')->andReturn(\AMQP_DURABLE)->byDefault();
        $mock->shouldReceive('setFlags')->andReturn(true)->byDefault();
        $mock->shouldReceive('getArguments')->andReturn([])->byDefault();
        $mock->shouldReceive('setArguments')->andReturn(true)->byDefault();
        $mock->shouldReceive('setArgument')->andReturn(true)->byDefault();

        // Declaration
        $mock->shouldReceive('declareExchange')->andReturn(true)->byDefault();
        $mock->shouldReceive('delete')->andReturn(true)->byDefault();

        // Binding (exchange-to-exchange)
        $mock->shouldReceive('bind')->andReturn(true)->byDefault();
        $mock->shouldReceive('unbind')->andReturn(true)->byDefault();

        // Publishing
        $mock->shouldReceive('publish')->andReturn(true)->byDefault();

        return $mock;
    }

    /**
     * Create a mock AMQPEnvelope.
     *
     * @param  array{
     *     body?: string,
     *     deliveryTag?: int,
     *     messageId?: string,
     *     timestamp?: int,
     *     priority?: int,
     *     headers?: array<string, mixed>,
     *     contentType?: string,
     *     contentEncoding?: string,
     *     correlationId?: string,
     *     replyTo?: string,
     *     expiration?: string,
     *     type?: string,
     *     userId?: string,
     *     appId?: string,
     *     routingKey?: string,
     *     exchange?: string,
     *     redelivered?: bool,
     * }  $options
     */
    public static function envelope(array $options = []): MockInterface
    {
        $defaults = [
            'body' => '{"uuid":"test-uuid","displayName":"TestJob","job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","data":{}}',
            'deliveryTag' => 1,
            'messageId' => 'msg-'.uniqid(),
            'timestamp' => time(),
            'priority' => 0,
            'headers' => [],
            'contentType' => 'application/json',
            'contentEncoding' => 'utf-8',
            'correlationId' => '',
            'replyTo' => '',
            'expiration' => '',
            'type' => '',
            'userId' => '',
            'appId' => '',
            'routingKey' => 'test.routing.key',
            'exchange' => 'test-exchange',
            'redelivered' => false,
        ];

        $options = array_merge($defaults, $options);

        $mock = Mockery::mock(AMQPEnvelope::class);

        $mock->shouldReceive('getBody')->andReturn($options['body']);
        $mock->shouldReceive('getDeliveryTag')->andReturn($options['deliveryTag']);
        $mock->shouldReceive('getMessageId')->andReturn($options['messageId']);
        $mock->shouldReceive('getTimestamp')->andReturn($options['timestamp']);
        $mock->shouldReceive('getPriority')->andReturn($options['priority']);
        $mock->shouldReceive('getHeaders')->andReturn($options['headers']);
        $mock->shouldReceive('getHeader')->andReturnUsing(function ($key) use ($options) {
            return $options['headers'][$key] ?? null;
        });
        $mock->shouldReceive('getContentType')->andReturn($options['contentType']);
        $mock->shouldReceive('getContentEncoding')->andReturn($options['contentEncoding']);
        $mock->shouldReceive('getCorrelationId')->andReturn($options['correlationId']);
        $mock->shouldReceive('getReplyTo')->andReturn($options['replyTo']);
        $mock->shouldReceive('getExpiration')->andReturn($options['expiration']);
        $mock->shouldReceive('getType')->andReturn($options['type']);
        $mock->shouldReceive('getUserId')->andReturn($options['userId']);
        $mock->shouldReceive('getAppId')->andReturn($options['appId']);
        $mock->shouldReceive('getRoutingKey')->andReturn($options['routingKey']);
        $mock->shouldReceive('getExchangeName')->andReturn($options['exchange']);
        $mock->shouldReceive('isRedelivery')->andReturn($options['redelivered']);

        return $mock;
    }

    /**
     * Create an envelope with a specific job payload.
     */
    public static function envelopeWithJob(string $jobClass, array $jobData = [], array $options = []): MockInterface
    {
        $payload = [
            'uuid' => $options['uuid'] ?? 'test-uuid-'.uniqid(),
            'displayName' => class_basename($jobClass),
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [
                'commandName' => $jobClass,
                'command' => 'serialized-command-data',
            ],
            'maxTries' => $options['maxTries'] ?? null,
            'maxExceptions' => $options['maxExceptions'] ?? null,
            'timeout' => $options['timeout'] ?? null,
            'backoff' => $options['backoff'] ?? null,
            'retryUntil' => $options['retryUntil'] ?? null,
        ];

        if (! empty($jobData)) {
            $payload['data'] = array_merge($payload['data'], $jobData);
        }

        return self::envelope(array_merge($options, [
            'body' => json_encode($payload),
        ]));
    }
}
