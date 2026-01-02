<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Tests\Mocks;

use Mockery;
use Mockery\MockInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Factory for creating mock php-amqplib classes.
 *
 * Use these mocks to test RabbitMQ functionality without a real connection.
 */
class AMQPMocks
{
    /**
     * Create a mock AMQPStreamConnection.
     */
    public static function connection(bool $connected = true, int $heartbeat = 60): MockInterface
    {
        $mock = Mockery::mock(AMQPStreamConnection::class);

        $mock->shouldReceive('isConnected')->andReturn($connected)->byDefault();
        $mock->shouldReceive('reconnect')->andReturn(true)->byDefault();
        $mock->shouldReceive('close')->andReturn(null)->byDefault();

        $mock->shouldReceive('getHeartbeat')->andReturn($heartbeat)->byDefault();
        $mock->shouldReceive('channel')->andReturn(self::channel($mock))->byDefault();

        return $mock;
    }

    /**
     * Create a mock AMQPChannel.
     */
    public static function channel(?MockInterface $connection = null): MockInterface
    {
        $mock = Mockery::mock(AMQPChannel::class);

        $mock->shouldReceive('is_open')->andReturn(true)->byDefault();
        $mock->shouldReceive('is_consuming')->andReturn(true)->byDefault();
        $mock->shouldReceive('close')->andReturn(null)->byDefault();
        $mock->shouldReceive('getConnection')->andReturn($connection ?? self::connection())->byDefault();

        // QoS
        $mock->shouldReceive('basic_qos')->andReturn(null)->byDefault();

        // Publisher confirms
        $mock->shouldReceive('confirm_select')->andReturn(null)->byDefault();
        $mock->shouldReceive('set_ack_handler')->andReturn(null)->byDefault();
        $mock->shouldReceive('set_nack_handler')->andReturn(null)->byDefault();
        $mock->shouldReceive('wait_for_pending_acks_returns')->andReturn(null)->byDefault();

        // Transactions
        $mock->shouldReceive('tx_select')->andReturn(null)->byDefault();
        $mock->shouldReceive('tx_commit')->andReturn(null)->byDefault();
        $mock->shouldReceive('tx_rollback')->andReturn(null)->byDefault();

        // Publishing
        $mock->shouldReceive('basic_publish')->andReturn(null)->byDefault();

        // Consuming
        $mock->shouldReceive('basic_get')->andReturn(null)->byDefault();
        $mock->shouldReceive('basic_consume')->andReturn('consumer-tag')->byDefault();
        $mock->shouldReceive('basic_cancel')->andReturn(null)->byDefault();
        $mock->shouldReceive('wait')->andReturn(null)->byDefault();

        // Acknowledgement
        $mock->shouldReceive('basic_ack')->andReturn(null)->byDefault();
        $mock->shouldReceive('basic_nack')->andReturn(null)->byDefault();
        $mock->shouldReceive('basic_reject')->andReturn(null)->byDefault();

        // Exchange operations
        $mock->shouldReceive('exchange_declare')->andReturn(null)->byDefault();
        $mock->shouldReceive('exchange_delete')->andReturn(null)->byDefault();
        $mock->shouldReceive('exchange_bind')->andReturn(null)->byDefault();
        $mock->shouldReceive('exchange_unbind')->andReturn(null)->byDefault();

        // Queue operations
        $mock->shouldReceive('queue_declare')->andReturn(['test-queue', 0, 0])->byDefault();
        $mock->shouldReceive('queue_delete')->andReturn(0)->byDefault();
        $mock->shouldReceive('queue_bind')->andReturn(null)->byDefault();
        $mock->shouldReceive('queue_unbind')->andReturn(null)->byDefault();
        $mock->shouldReceive('queue_purge')->andReturn(0)->byDefault();

        return $mock;
    }

    /**
     * Create a mock AMQPMessage.
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
    public static function message(array $options = []): MockInterface
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
        $hasHeaders = ! empty($options['headers']);

        // Create AMQPTable with deprecation warnings suppressed
        // This is necessary because older dependencies may trigger PHP 8.4
        // deprecation warnings during autoload
        $headersTable = self::createHeadersTableSafely($options['headers']);

        $mock = Mockery::mock(AMQPMessage::class);

        $mock->shouldReceive('getBody')->andReturn($options['body']);
        $mock->shouldReceive('getDeliveryTag')->andReturn($options['deliveryTag']);
        $mock->shouldReceive('getChannel')->andReturn(self::channel());
        $mock->shouldReceive('isRedelivered')->andReturn($options['redelivered']);
        $mock->shouldReceive('getRoutingKey')->andReturn($options['routingKey']);
        $mock->shouldReceive('getExchange')->andReturn($options['exchange']);

        // Properties are accessed via has() and get() in php-amqplib
        // Using explicit setup to avoid closure capture issues
        $mock->shouldReceive('has')->with('message_id')->andReturn(! empty($options['messageId']));
        $mock->shouldReceive('has')->with('timestamp')->andReturn(! empty($options['timestamp']));
        $mock->shouldReceive('has')->with('priority')->andReturn(isset($options['priority']));
        $mock->shouldReceive('has')->with('application_headers')->andReturn($hasHeaders);
        $mock->shouldReceive('has')->with('content_type')->andReturn(! empty($options['contentType']));
        $mock->shouldReceive('has')->with('content_encoding')->andReturn(! empty($options['contentEncoding']));
        $mock->shouldReceive('has')->with('correlation_id')->andReturn(! empty($options['correlationId']));
        $mock->shouldReceive('has')->with('reply_to')->andReturn(! empty($options['replyTo']));
        $mock->shouldReceive('has')->with('expiration')->andReturn(! empty($options['expiration']));
        $mock->shouldReceive('has')->with('type')->andReturn(! empty($options['type']));
        $mock->shouldReceive('has')->with('user_id')->andReturn(! empty($options['userId']));
        $mock->shouldReceive('has')->with('app_id')->andReturn(! empty($options['appId']));
        $mock->shouldReceive('has')->andReturn(false)->byDefault();

        $mock->shouldReceive('get')->with('message_id')->andReturn($options['messageId']);
        $mock->shouldReceive('get')->with('timestamp')->andReturn($options['timestamp']);
        $mock->shouldReceive('get')->with('priority')->andReturn($options['priority']);
        $mock->shouldReceive('get')->with('application_headers')->andReturn($headersTable);
        $mock->shouldReceive('get')->with('content_type')->andReturn($options['contentType']);
        $mock->shouldReceive('get')->with('content_encoding')->andReturn($options['contentEncoding']);
        $mock->shouldReceive('get')->with('correlation_id')->andReturn($options['correlationId']);
        $mock->shouldReceive('get')->with('reply_to')->andReturn($options['replyTo']);
        $mock->shouldReceive('get')->with('expiration')->andReturn($options['expiration']);
        $mock->shouldReceive('get')->with('type')->andReturn($options['type']);
        $mock->shouldReceive('get')->with('user_id')->andReturn($options['userId']);
        $mock->shouldReceive('get')->with('app_id')->andReturn($options['appId']);
        $mock->shouldReceive('get')->andReturn(null)->byDefault();

        return $mock;
    }

    /**
     * Create a message with a specific job payload.
     */
    public static function messageWithJob(string $jobClass, array $jobData = [], array $options = []): MockInterface
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

        return self::message(array_merge($options, [
            'body' => json_encode($payload),
        ]));
    }

    /**
     * Create an AMQPTable for headers.
     *
     * @param  array<string, mixed>  $headers
     */
    public static function headersTable(array $headers = []): AMQPTable
    {
        return new AMQPTable($headers);
    }

    /**
     * Create an AMQPTable safely, suppressing deprecation warnings.
     *
     * This is necessary because older dependencies (Guzzle, Symfony, PsySH)
     * may trigger PHP 8.4 deprecation warnings during autoload. When this
     * happens, Laravel's HandleExceptions tries to log the deprecation but
     * the logger may not be configured, causing "Call to a member function
     * warning() on null" errors.
     *
     * @param  array<string, mixed>  $headers
     */
    public static function createHeadersTableSafely(array $headers = []): AMQPTable
    {
        // Temporarily suppress deprecation warnings during AMQPTable creation
        // This prevents Laravel's HandleExceptions from trying to log deprecations
        // before the logger is properly configured
        set_error_handler(function ($level) {
            // Suppress deprecation warnings, let other errors through
            return in_array($level, [E_DEPRECATED, E_USER_DEPRECATED]);
        });

        try {
            return new AMQPTable($headers);
        } finally {
            restore_error_handler();
        }
    }
}
