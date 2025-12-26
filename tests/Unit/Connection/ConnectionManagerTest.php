<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Lettermint\RabbitMQ\Connection\ConnectionManager;
use Lettermint\RabbitMQ\Exceptions\ConnectionException;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Exception\AMQPIOException;

test('returns default connection name from config', function () {
    $manager = new ConnectionManager([
        'default' => 'production',
    ]);

    expect($manager->getDefaultConnection())->toBe('production');
});

test('defaults to "default" when not configured', function () {
    $manager = new ConnectionManager([]);

    expect($manager->getDefaultConnection())->toBe('default');
});

test('throws when connection not configured', function () {
    $manager = new ConnectionManager([
        'connections' => [],
    ]);

    $manager->connection('nonexistent');
})->throws(ConnectionException::class, 'not configured');

test('throws when no hosts configured', function () {
    $manager = new ConnectionManager([
        'connections' => [
            'default' => [
                'hosts' => [],
            ],
        ],
    ]);

    $manager->connection('default');
})->throws(ConnectionException::class, 'no hosts configured');

test('reports not connected initially', function () {
    $manager = new ConnectionManager([
        'connections' => [
            'default' => [
                'hosts' => [['host' => 'localhost']],
            ],
        ],
    ]);

    expect($manager->isConnected())->toBeFalse();
});

test('returns empty active connections initially', function () {
    $manager = new ConnectionManager([]);

    expect($manager->getActiveConnections())->toBeEmpty();
});

test('handles disconnect when not connected', function () {
    $manager = new ConnectionManager([
        'default' => 'test',
    ]);

    // Should not throw
    $manager->disconnect('test');
    expect($manager->isConnected('test'))->toBeFalse();
});

test('handles disconnectAll when no connections', function () {
    $manager = new ConnectionManager([]);

    // Should not throw
    $manager->disconnectAll();
    expect($manager->getActiveConnections())->toBeEmpty();
});

test('reuses existing connected connection', function () {
    $mockConnection = mockAMQPConnection(true);

    $manager = new class(['default' => 'test', 'connections' => ['test' => ['hosts' => [['host' => 'localhost']]]]], $mockConnection) extends ConnectionManager
    {
        public function __construct(array $config, private $mockConnection)
        {
            parent::__construct($config);
        }

        protected function createConnectionToHost(array $host, array $options, array $ssl): AbstractConnection
        {
            return $this->mockConnection;
        }
    };

    $conn1 = $manager->connection('test');
    $conn2 = $manager->connection('test');

    expect($conn1)->toBe($conn2);
});

test('reconnects when connection is disconnected', function () {
    Log::spy();

    $mockConnection = mockAMQPConnection(false);
    $mockConnection->shouldReceive('reconnect')->once()->andReturn(true);
    $mockConnection->shouldReceive('isConnected')->andReturn(false, true);

    $manager = new class(['default' => 'test', 'connections' => ['test' => ['hosts' => [['host' => 'localhost']]]]], $mockConnection) extends ConnectionManager
    {
        public function __construct(array $config, private $mockConnection)
        {
            parent::__construct($config);
        }

        protected function createConnectionToHost(array $host, array $options, array $ssl): AbstractConnection
        {
            return $this->mockConnection;
        }
    };

    $manager->connection('test');

    Log::shouldHaveReceived('info')
        ->withArgs(fn ($msg) => str_contains($msg, 'reconnect'));
});

test('logs warning when using fallback host', function () {
    Log::spy();

    $primaryFailed = false;
    $mockConnection = mockAMQPConnection(true);

    $manager = new class(['default' => 'test', 'connections' => ['test' => ['hosts' => [['host' => 'primary'], ['host' => 'fallback']]]]], $mockConnection, $primaryFailed) extends ConnectionManager
    {
        private bool $firstAttempt = true;

        public function __construct(array $config, private $mockConnection, private &$primaryFailed)
        {
            parent::__construct($config);
        }

        protected function createConnectionToHost(array $host, array $options, array $ssl): AbstractConnection
        {
            if ($this->firstAttempt) {
                $this->firstAttempt = false;
                $this->primaryFailed = true;
                throw new AMQPIOException('Primary host failed');
            }

            return $this->mockConnection;
        }
    };

    $manager->connection('test');

    expect($primaryFailed)->toBeTrue();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($msg) => str_contains($msg, 'fallback host'));
});

test('throws after all hosts fail', function () {
    $manager = new class(['default' => 'test', 'connections' => ['test' => ['hosts' => [['host' => 'host1'], ['host' => 'host2']]]]]) extends ConnectionManager
    {
        protected function createConnectionToHost(array $host, array $options, array $ssl): AbstractConnection
        {
            throw new AMQPIOException('Connection failed');
        }
    };

    expect(fn () => $manager->connection('test'))
        ->toThrow(ConnectionException::class, 'All 2 hosts failed');
});

test('disconnects specific connection', function () {
    $mockConnection = mockAMQPConnection(true);
    $mockConnection->shouldReceive('close')->once();
    // isConnected() called: 1) connection() check, 2) disconnect() check, 3) isConnected('test') assertion
    $mockConnection->shouldReceive('isConnected')->andReturn(true, true, false);

    $manager = new class(['connections' => ['test' => ['hosts' => [['host' => 'localhost']]]]], $mockConnection) extends ConnectionManager
    {
        public function __construct(array $config, private $mockConnection)
        {
            parent::__construct($config);
        }

        protected function createConnectionToHost(array $host, array $options, array $ssl): AbstractConnection
        {
            return $this->mockConnection;
        }
    };

    $manager->connection('test');
    $manager->disconnect('test');

    expect($manager->isConnected('test'))->toBeFalse();
});
