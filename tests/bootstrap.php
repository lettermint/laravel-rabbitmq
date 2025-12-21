<?php

declare(strict_types=1);

/**
 * Test bootstrap file.
 *
 * Defines stub AMQP classes when ext-amqp is not loaded.
 * This allows the test suite to run without the extension installed.
 */

// Define AMQP constants if not defined
if (! defined('AMQP_DURABLE')) {
    define('AMQP_DURABLE', 2);
}
if (! defined('AMQP_PASSIVE')) {
    define('AMQP_PASSIVE', 1);
}
if (! defined('AMQP_EXCLUSIVE')) {
    define('AMQP_EXCLUSIVE', 4);
}
if (! defined('AMQP_AUTODELETE')) {
    define('AMQP_AUTODELETE', 8);
}
if (! defined('AMQP_INTERNAL')) {
    define('AMQP_INTERNAL', 16);
}
if (! defined('AMQP_NOWAIT')) {
    define('AMQP_NOWAIT', 32);
}
if (! defined('AMQP_AUTOACK')) {
    define('AMQP_AUTOACK', 64);
}
if (! defined('AMQP_IFEMPTY')) {
    define('AMQP_IFEMPTY', 128);
}
if (! defined('AMQP_IFUNUSED')) {
    define('AMQP_IFUNUSED', 256);
}
if (! defined('AMQP_MANDATORY')) {
    define('AMQP_MANDATORY', 512);
}
if (! defined('AMQP_IMMEDIATE')) {
    define('AMQP_IMMEDIATE', 1024);
}
if (! defined('AMQP_NOPARAM')) {
    define('AMQP_NOPARAM', 0);
}
if (! defined('AMQP_REQUEUE')) {
    define('AMQP_REQUEUE', 1);
}

// Exchange type constants
if (! defined('AMQP_EX_TYPE_DIRECT')) {
    define('AMQP_EX_TYPE_DIRECT', 'direct');
}
if (! defined('AMQP_EX_TYPE_FANOUT')) {
    define('AMQP_EX_TYPE_FANOUT', 'fanout');
}
if (! defined('AMQP_EX_TYPE_TOPIC')) {
    define('AMQP_EX_TYPE_TOPIC', 'topic');
}
if (! defined('AMQP_EX_TYPE_HEADERS')) {
    define('AMQP_EX_TYPE_HEADERS', 'headers');
}

// Define stub AMQP exception classes if ext-amqp is not loaded
if (! class_exists('AMQPException')) {
    class AMQPException extends Exception {}
}

if (! class_exists('AMQPConnectionException')) {
    class AMQPConnectionException extends AMQPException {}
}

if (! class_exists('AMQPChannelException')) {
    class AMQPChannelException extends AMQPException {}
}

if (! class_exists('AMQPQueueException')) {
    class AMQPQueueException extends AMQPException {}
}

if (! class_exists('AMQPExchangeException')) {
    class AMQPExchangeException extends AMQPException {}
}

// Define stub AMQP classes if ext-amqp is not loaded
if (! class_exists('AMQPConnection')) {
    class AMQPConnection
    {
        protected array $credentials = [];

        protected bool $connected = false;

        public function __construct(?array $credentials = null)
        {
            $this->credentials = $credentials ?? [];
        }

        public function connect(): bool
        {
            $this->connected = true;

            return true;
        }

        public function pconnect(): bool
        {
            $this->connected = true;

            return true;
        }

        public function reconnect(): bool
        {
            $this->connected = true;

            return true;
        }

        public function preconnect(): bool
        {
            $this->connected = true;

            return true;
        }

        public function disconnect(): bool
        {
            $this->connected = false;

            return true;
        }

        public function pdisconnect(): bool
        {
            $this->connected = false;

            return true;
        }

        public function isConnected(): bool
        {
            return $this->connected;
        }

        public function getHost(): string
        {
            return $this->credentials['host'] ?? 'localhost';
        }

        public function getPort(): int
        {
            return $this->credentials['port'] ?? 5672;
        }

        public function getLogin(): string
        {
            return $this->credentials['login'] ?? 'guest';
        }

        public function getVhost(): string
        {
            return $this->credentials['vhost'] ?? '/';
        }

        public function setHost(string $host): AMQPConnection
        {
            $this->credentials['host'] = $host;

            return $this;
        }

        public function setPort(int $port): AMQPConnection
        {
            $this->credentials['port'] = $port;

            return $this;
        }

        public function setLogin(string $login): AMQPConnection
        {
            $this->credentials['login'] = $login;

            return $this;
        }

        public function setPassword(string $password): AMQPConnection
        {
            $this->credentials['password'] = $password;

            return $this;
        }

        public function setVhost(string $vhost): AMQPConnection
        {
            $this->credentials['vhost'] = $vhost;

            return $this;
        }

        public function setReadTimeout(float $timeout): AMQPConnection
        {
            return $this;
        }

        public function setWriteTimeout(float $timeout): AMQPConnection
        {
            return $this;
        }

        public function setConnectionTimeout(float $timeout): AMQPConnection
        {
            return $this;
        }

        public function setHeartbeat(int $heartbeat): AMQPConnection
        {
            return $this;
        }

        public function setRpcTimeout(float $timeout): AMQPConnection
        {
            return $this;
        }
    }
}

if (! class_exists('AMQPChannel')) {
    class AMQPChannel
    {
        protected ?AMQPConnection $connection = null;

        protected bool $connected = true;

        protected int $id = 1;

        public function __construct(AMQPConnection $connection)
        {
            $this->connection = $connection;
        }

        public function isConnected(): bool
        {
            return $this->connected && ($this->connection?->isConnected() ?? false);
        }

        public function getConnection(): AMQPConnection
        {
            return $this->connection;
        }

        public function close(): void
        {
            $this->connected = false;
        }

        public function getChannelId(): int
        {
            return $this->id;
        }

        public function setPrefetchCount(int $count): bool
        {
            return true;
        }

        public function setPrefetchSize(int $size): bool
        {
            return true;
        }

        public function qos(int $size, int $count, bool $global = false): bool
        {
            return true;
        }

        public function confirmSelect(): bool
        {
            return true;
        }

        public function waitForConfirm(float $timeout = 0.0): void {}

        public function startTransaction(): bool
        {
            return true;
        }

        public function commitTransaction(): bool
        {
            return true;
        }

        public function rollbackTransaction(): bool
        {
            return true;
        }
    }
}

if (! class_exists('AMQPQueue')) {
    class AMQPQueue
    {
        protected ?AMQPChannel $channel = null;

        protected string $name = '';

        protected int $flags = AMQP_DURABLE;

        protected array $arguments = [];

        public function __construct(AMQPChannel $channel)
        {
            $this->channel = $channel;
        }

        public function setName(string $name): bool
        {
            $this->name = $name;

            return true;
        }

        public function getName(): string
        {
            return $this->name;
        }

        public function setFlags(int $flags): bool
        {
            $this->flags = $flags;

            return true;
        }

        public function getFlags(): int
        {
            return $this->flags;
        }

        public function setArguments(array $arguments): bool
        {
            $this->arguments = $arguments;

            return true;
        }

        public function setArgument(string $key, mixed $value): bool
        {
            $this->arguments[$key] = $value;

            return true;
        }

        public function getArguments(): array
        {
            return $this->arguments;
        }

        public function declareQueue(): int
        {
            return 0;
        }

        public function bind(string $exchange, ?string $routingKey = null, array $arguments = []): bool
        {
            return true;
        }

        public function unbind(string $exchange, ?string $routingKey = null, array $arguments = []): bool
        {
            return true;
        }

        public function delete(int $flags = AMQP_NOPARAM): int
        {
            return 0;
        }

        public function purge(): int
        {
            return 0;
        }

        public function get(int $flags = AMQP_AUTOACK): ?AMQPEnvelope
        {
            return null;
        }

        public function consume(?callable $callback = null, int $flags = AMQP_NOPARAM, ?string $tag = null): void {}

        public function cancel(string $tag = ''): bool
        {
            return true;
        }

        public function ack(int $deliveryTag, int $flags = AMQP_NOPARAM): bool
        {
            return true;
        }

        public function nack(int $deliveryTag, int $flags = AMQP_REQUEUE): bool
        {
            return true;
        }

        public function reject(int $deliveryTag, int $flags = AMQP_REQUEUE): bool
        {
            return true;
        }

        public function getChannel(): AMQPChannel
        {
            return $this->channel;
        }
    }
}

if (! class_exists('AMQPExchange')) {
    class AMQPExchange
    {
        protected ?AMQPChannel $channel = null;

        protected string $name = '';

        protected string $type = 'topic';

        protected int $flags = AMQP_DURABLE;

        protected array $arguments = [];

        public function __construct(AMQPChannel $channel)
        {
            $this->channel = $channel;
        }

        public function setName(string $name): bool
        {
            $this->name = $name;

            return true;
        }

        public function getName(): string
        {
            return $this->name;
        }

        public function setType(string $type): bool
        {
            $this->type = $type;

            return true;
        }

        public function getType(): string
        {
            return $this->type;
        }

        public function setFlags(int $flags): bool
        {
            $this->flags = $flags;

            return true;
        }

        public function getFlags(): int
        {
            return $this->flags;
        }

        public function setArguments(array $arguments): bool
        {
            $this->arguments = $arguments;

            return true;
        }

        public function setArgument(string $key, mixed $value): bool
        {
            $this->arguments[$key] = $value;

            return true;
        }

        public function getArguments(): array
        {
            return $this->arguments;
        }

        public function declareExchange(): bool
        {
            return true;
        }

        public function delete(?string $name = null, int $flags = AMQP_NOPARAM): bool
        {
            return true;
        }

        public function bind(string $exchange, string $routingKey = '', array $arguments = []): bool
        {
            return true;
        }

        public function unbind(string $exchange, string $routingKey = '', array $arguments = []): bool
        {
            return true;
        }

        public function publish(string $message, ?string $routingKey = null, int $flags = AMQP_NOPARAM, array $attributes = []): bool
        {
            return true;
        }

        public function getChannel(): AMQPChannel
        {
            return $this->channel;
        }
    }
}

if (! class_exists('AMQPEnvelope')) {
    class AMQPEnvelope
    {
        protected string $body = '';

        protected int $deliveryTag = 0;

        protected string $routingKey = '';

        protected string $exchange = '';

        protected bool $redelivered = false;

        protected string $contentType = 'text/plain';

        protected string $contentEncoding = '';

        protected array $headers = [];

        protected int $deliveryMode = 2;

        protected int $priority = 0;

        protected string $correlationId = '';

        protected string $replyTo = '';

        protected string $expiration = '';

        protected string $messageId = '';

        protected int $timestamp = 0;

        protected string $type = '';

        protected string $userId = '';

        protected string $appId = '';

        protected string $clusterId = '';

        public function getBody(): string
        {
            return $this->body;
        }

        public function getDeliveryTag(): int
        {
            return $this->deliveryTag;
        }

        public function getRoutingKey(): string
        {
            return $this->routingKey;
        }

        public function getExchangeName(): string
        {
            return $this->exchange;
        }

        public function isRedelivery(): bool
        {
            return $this->redelivered;
        }

        public function getContentType(): string
        {
            return $this->contentType;
        }

        public function getContentEncoding(): string
        {
            return $this->contentEncoding;
        }

        public function getHeaders(): array
        {
            return $this->headers;
        }

        public function getHeader(string $name): mixed
        {
            return $this->headers[$name] ?? null;
        }

        public function hasHeader(string $name): bool
        {
            return isset($this->headers[$name]);
        }

        public function getDeliveryMode(): int
        {
            return $this->deliveryMode;
        }

        public function getPriority(): int
        {
            return $this->priority;
        }

        public function getCorrelationId(): string
        {
            return $this->correlationId;
        }

        public function getReplyTo(): string
        {
            return $this->replyTo;
        }

        public function getExpiration(): string
        {
            return $this->expiration;
        }

        public function getMessageId(): string
        {
            return $this->messageId;
        }

        public function getTimestamp(): int
        {
            return $this->timestamp;
        }

        public function getType(): string
        {
            return $this->type;
        }

        public function getUserId(): string
        {
            return $this->userId;
        }

        public function getAppId(): string
        {
            return $this->appId;
        }

        public function getClusterId(): string
        {
            return $this->clusterId;
        }
    }
}

// Load Composer autoloader
require dirname(__DIR__).'/vendor/autoload.php';
