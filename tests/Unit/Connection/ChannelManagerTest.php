<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Connection\ConnectionManager;
use Lettermint\RabbitMQ\Exceptions\ConnectionException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPIOException;

beforeEach(function () {
    $this->mockConnection = mockAMQPConnection(true);
    $this->mockChannel = mockAMQPChannel($this->mockConnection);

    $this->connectionManager = Mockery::mock(ConnectionManager::class);
    $this->connectionManager->shouldReceive('getDefaultConnection')
        ->andReturn('default')
        ->byDefault();
    $this->connectionManager->shouldReceive('connection')
        ->andReturn($this->mockConnection)
        ->byDefault();
});

test('creates channel for given purpose', function () {
    // Use a subclass to inject mock channel
    $channelManager = new class($this->connectionManager, $this->mockChannel) extends ChannelManager
    {
        public function __construct(ConnectionManager $connectionManager, private $mockChannel)
        {
            parent::__construct($connectionManager);
        }

        protected function createChannel(?string $connection = null): AMQPChannel
        {
            return $this->mockChannel;
        }
    };

    $channel = $channelManager->channel('publish');

    expect($channel)->toBe($this->mockChannel);
});

test('reuses channel for same purpose', function () {
    $channelManager = new class($this->connectionManager, $this->mockChannel) extends ChannelManager
    {
        public function __construct(ConnectionManager $connectionManager, private $mockChannel)
        {
            parent::__construct($connectionManager);
        }

        protected function createChannel(?string $connection = null): AMQPChannel
        {
            return $this->mockChannel;
        }
    };

    $channel1 = $channelManager->channel('publish');
    $channel2 = $channelManager->channel('publish');

    expect($channel1)->toBe($channel2);
});

test('creates separate channels for different purposes', function () {
    $publishChannel = mockAMQPChannel();
    $consumeChannel = mockAMQPChannel();
    $callCount = 0;

    $channelManager = new class($this->connectionManager, $publishChannel, $consumeChannel, $callCount) extends ChannelManager
    {
        public function __construct(
            ConnectionManager $connectionManager,
            private $publishChannel,
            private $consumeChannel,
            private &$callCount
        ) {
            parent::__construct($connectionManager);
        }

        protected function createChannel(?string $connection = null): AMQPChannel
        {
            $this->callCount++;

            return $this->callCount === 1 ? $this->publishChannel : $this->consumeChannel;
        }
    };

    $publish = $channelManager->channel('publish');
    $consume = $channelManager->channel('consume');

    expect($publish)->not->toBe($consume);
});

test('recreates channel when disconnected', function () {
    $disconnectedChannel = mockAMQPChannel();
    $disconnectedChannel->shouldReceive('is_open')->andReturn(false);

    $newChannel = mockAMQPChannel();
    $newChannel->shouldReceive('is_open')->andReturn(true);

    $callCount = 0;
    $channelManager = new class($this->connectionManager, $disconnectedChannel, $newChannel, $callCount) extends ChannelManager
    {
        public function __construct(
            ConnectionManager $connectionManager,
            private $disconnectedChannel,
            private $newChannel,
            private &$callCount
        ) {
            parent::__construct($connectionManager);
        }

        protected function createChannel(?string $connection = null): AMQPChannel
        {
            $this->callCount++;

            return $this->callCount === 1 ? $this->disconnectedChannel : $this->newChannel;
        }
    };

    $channel1 = $channelManager->channel('publish');
    expect($channel1)->toBe($disconnectedChannel);

    $channel2 = $channelManager->channel('publish');
    expect($channel2)->toBe($newChannel);
});

test('provides publish channel', function () {
    $channelManager = new class($this->connectionManager, $this->mockChannel) extends ChannelManager
    {
        public function __construct(ConnectionManager $connectionManager, private $mockChannel)
        {
            parent::__construct($connectionManager);
        }

        protected function createChannel(?string $connection = null): AMQPChannel
        {
            return $this->mockChannel;
        }
    };

    $channel = $channelManager->publishChannel();

    expect($channel)->toBe($this->mockChannel);
});

test('enables publisher confirmations once for a shared publish channel', function () {
    $this->mockChannel->shouldReceive('confirm_select')->once();
    $channelManager = new class($this->connectionManager, $this->mockChannel) extends ChannelManager
    {
        public function __construct(ConnectionManager $connectionManager, private $mockChannel)
        {
            parent::__construct($connectionManager);
        }

        protected function createChannel(?string $connection = null): AMQPChannel
        {
            return $this->mockChannel;
        }
    };

    expect($channelManager->publishChannel())->toBe($this->mockChannel)
        ->and($channelManager->publishChannel())->toBe($this->mockChannel);
});

test('enables publisher confirmations on a replacement publish channel', function () {
    $closedChannel = mockAMQPChannel();
    $closedChannel->shouldReceive('is_open')->andReturn(false);
    $closedChannel->shouldReceive('confirm_select')->once();
    $replacementChannel = mockAMQPChannel();
    $replacementChannel->shouldReceive('confirm_select')->once();
    $channels = [$closedChannel, $replacementChannel];

    $channelManager = new class($this->connectionManager, $channels) extends ChannelManager
    {
        public function __construct(ConnectionManager $connectionManager, private array $mockChannels)
        {
            parent::__construct($connectionManager);
        }

        protected function createChannel(?string $connection = null): AMQPChannel
        {
            return array_shift($this->mockChannels);
        }
    };

    expect($channelManager->publishChannel())->toBe($closedChannel)
        ->and($channelManager->publishChannel())->toBe($replacementChannel);
});

test('provides consume channel', function () {
    $channelManager = new class($this->connectionManager, $this->mockChannel) extends ChannelManager
    {
        public function __construct(ConnectionManager $connectionManager, private $mockChannel)
        {
            parent::__construct($connectionManager);
        }

        protected function createChannel(?string $connection = null): AMQPChannel
        {
            return $this->mockChannel;
        }
    };

    $channel = $channelManager->consumeChannel();

    expect($channel)->toBe($this->mockChannel);
});

test('provides topology channel', function () {
    $channelManager = new class($this->connectionManager, $this->mockChannel) extends ChannelManager
    {
        public function __construct(ConnectionManager $connectionManager, private $mockChannel)
        {
            parent::__construct($connectionManager);
        }

        protected function createChannel(?string $connection = null): AMQPChannel
        {
            return $this->mockChannel;
        }
    };

    $channel = $channelManager->topologyChannel();

    expect($channel)->toBe($this->mockChannel);
});

test('closes specific channel', function () {
    $channelManager = new class($this->connectionManager, $this->mockChannel) extends ChannelManager
    {
        public function __construct(ConnectionManager $connectionManager, private $mockChannel)
        {
            parent::__construct($connectionManager);
        }

        protected function createChannel(?string $connection = null): AMQPChannel
        {
            return $this->mockChannel;
        }
    };

    $channelManager->channel('publish');
    $channelManager->closeChannel('publish');

    // Accessing after close should create new channel
    // (this just verifies no exception is thrown)
    expect(true)->toBeTrue();
});

test('closes all channels', function () {
    $channelManager = new class($this->connectionManager, $this->mockChannel) extends ChannelManager
    {
        public function __construct(ConnectionManager $connectionManager, private $mockChannel)
        {
            parent::__construct($connectionManager);
        }

        protected function createChannel(?string $connection = null): AMQPChannel
        {
            return $this->mockChannel;
        }
    };

    $channelManager->channel('publish');
    $channelManager->channel('consume');
    $channelManager->channel('topology');

    $channelManager->closeAll();

    // Just verify no exception
    expect(true)->toBeTrue();
});

test('closes channels when connection and purpose names contain colons', function () {
    $firstChannel = mockAMQPChannel();
    $secondChannel = mockAMQPChannel();
    $firstChannel->shouldReceive('close')->once();
    $secondChannel->shouldReceive('close')->once();
    $channels = [$firstChannel, $secondChannel];

    $channelManager = new class($this->connectionManager, $channels) extends ChannelManager
    {
        public function __construct(ConnectionManager $connectionManager, private array $mockChannels)
        {
            parent::__construct($connectionManager);
        }

        protected function createChannel(?string $connection = null): AMQPChannel
        {
            return array_shift($this->mockChannels);
        }
    };

    $channelManager->channel('audit:queue:one', 'broker:primary');
    $channelManager->channel('consume:two', 'broker:primary');
    $channelManager->closeConnectionChannels('broker:primary');
});

test('only closes channels for the selected connection', function () {
    $primaryChannel = mockAMQPChannel();
    $secondaryChannel = mockAMQPChannel();
    $primaryChannel->shouldReceive('close')->once();
    $secondaryChannel->shouldNotReceive('close');
    $channels = [$primaryChannel, $secondaryChannel];

    $channelManager = new class($this->connectionManager, $channels) extends ChannelManager
    {
        public function __construct(ConnectionManager $connectionManager, private array $mockChannels)
        {
            parent::__construct($connectionManager);
        }

        protected function createChannel(?string $connection = null): AMQPChannel
        {
            return array_shift($this->mockChannels);
        }
    };

    $channelManager->channel('publish', 'broker:primary');
    $channelManager->channel('publish', 'broker:secondary');
    $channelManager->closeConnectionChannels('broker:primary');

    expect($channelManager->channel('publish', 'broker:secondary'))->toBe($secondaryChannel);
});

test('does not reuse a channel for ambiguous colon-separated names', function () {
    $firstChannel = mockAMQPChannel();
    $secondChannel = mockAMQPChannel();
    $channels = [$firstChannel, $secondChannel];

    $channelManager = new class($this->connectionManager, $channels) extends ChannelManager
    {
        public function __construct(ConnectionManager $connectionManager, private array $mockChannels)
        {
            parent::__construct($connectionManager);
        }

        protected function createChannel(?string $connection = null): AMQPChannel
        {
            return array_shift($this->mockChannels);
        }
    };

    expect($channelManager->channel('c', 'a:b'))->toBe($firstChannel)
        ->and($channelManager->channel('b:c', 'a'))->toBe($secondChannel);
});

test('provides access to underlying connection', function () {
    $channelManager = new ChannelManager($this->connectionManager);

    $connection = $channelManager->getConnection();

    expect($connection)->toBe($this->mockConnection);
});

test('gets connection by name', function () {
    $this->connectionManager->shouldReceive('connection')
        ->with('custom')
        ->andReturn($this->mockConnection);

    $channelManager = new ChannelManager($this->connectionManager);

    $connection = $channelManager->getConnection('custom');

    expect($connection)->toBe($this->mockConnection);
});

test('wraps connection exception when channel creation fails', function () {
    $this->connectionManager->shouldReceive('connection')
        ->andThrow(new AMQPIOException('Connection failed'));

    $channelManager = new ChannelManager($this->connectionManager);

    expect(fn () => $channelManager->channel())
        ->toThrow(ConnectionException::class, 'Failed to create RabbitMQ channel');
});
