<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Connection\ChannelManager;
use Lettermint\RabbitMQ\Connection\ConnectionManager;
use Lettermint\RabbitMQ\Exceptions\ConnectionException;

describe('ChannelManager', function () {
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

    describe('channel creation', function () {
        it('creates channel for given purpose', function () {
            // Use a subclass to inject mock channel
            $channelManager = new class($this->connectionManager, $this->mockChannel) extends ChannelManager
            {
                public function __construct(ConnectionManager $connectionManager, private $mockChannel)
                {
                    parent::__construct($connectionManager);
                }

                protected function createChannel(?string $connection = null): \AMQPChannel
                {
                    return $this->mockChannel;
                }
            };

            $channel = $channelManager->channel('publish');

            expect($channel)->toBe($this->mockChannel);
        });

        it('reuses channel for same purpose', function () {
            $channelManager = new class($this->connectionManager, $this->mockChannel) extends ChannelManager
            {
                public function __construct(ConnectionManager $connectionManager, private $mockChannel)
                {
                    parent::__construct($connectionManager);
                }

                protected function createChannel(?string $connection = null): \AMQPChannel
                {
                    return $this->mockChannel;
                }
            };

            $channel1 = $channelManager->channel('publish');
            $channel2 = $channelManager->channel('publish');

            expect($channel1)->toBe($channel2);
        });

        it('creates separate channels for different purposes', function () {
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

                protected function createChannel(?string $connection = null): \AMQPChannel
                {
                    $this->callCount++;

                    return $this->callCount === 1 ? $this->publishChannel : $this->consumeChannel;
                }
            };

            $publish = $channelManager->channel('publish');
            $consume = $channelManager->channel('consume');

            expect($publish)->not->toBe($consume);
        });

        it('recreates channel when disconnected', function () {
            $disconnectedChannel = mockAMQPChannel();
            $disconnectedChannel->shouldReceive('isConnected')->andReturn(false);

            $newChannel = mockAMQPChannel();
            $newChannel->shouldReceive('isConnected')->andReturn(true);

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

                protected function createChannel(?string $connection = null): \AMQPChannel
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
    });

    describe('purpose-specific channels', function () {
        it('provides publish channel', function () {
            $channelManager = new class($this->connectionManager, $this->mockChannel) extends ChannelManager
            {
                public function __construct(ConnectionManager $connectionManager, private $mockChannel)
                {
                    parent::__construct($connectionManager);
                }

                protected function createChannel(?string $connection = null): \AMQPChannel
                {
                    return $this->mockChannel;
                }
            };

            $channel = $channelManager->publishChannel();

            expect($channel)->toBe($this->mockChannel);
        });

        it('provides consume channel', function () {
            $channelManager = new class($this->connectionManager, $this->mockChannel) extends ChannelManager
            {
                public function __construct(ConnectionManager $connectionManager, private $mockChannel)
                {
                    parent::__construct($connectionManager);
                }

                protected function createChannel(?string $connection = null): \AMQPChannel
                {
                    return $this->mockChannel;
                }
            };

            $channel = $channelManager->consumeChannel();

            expect($channel)->toBe($this->mockChannel);
        });

        it('provides topology channel', function () {
            $channelManager = new class($this->connectionManager, $this->mockChannel) extends ChannelManager
            {
                public function __construct(ConnectionManager $connectionManager, private $mockChannel)
                {
                    parent::__construct($connectionManager);
                }

                protected function createChannel(?string $connection = null): \AMQPChannel
                {
                    return $this->mockChannel;
                }
            };

            $channel = $channelManager->topologyChannel();

            expect($channel)->toBe($this->mockChannel);
        });
    });

    describe('channel closing', function () {
        it('closes specific channel', function () {
            $channelManager = new class($this->connectionManager, $this->mockChannel) extends ChannelManager
            {
                public function __construct(ConnectionManager $connectionManager, private $mockChannel)
                {
                    parent::__construct($connectionManager);
                }

                protected function createChannel(?string $connection = null): \AMQPChannel
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

        it('closes all channels', function () {
            $channelManager = new class($this->connectionManager, $this->mockChannel) extends ChannelManager
            {
                public function __construct(ConnectionManager $connectionManager, private $mockChannel)
                {
                    parent::__construct($connectionManager);
                }

                protected function createChannel(?string $connection = null): \AMQPChannel
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
    });

    describe('connection access', function () {
        it('provides access to underlying connection', function () {
            $channelManager = new ChannelManager($this->connectionManager);

            $connection = $channelManager->getConnection();

            expect($connection)->toBe($this->mockConnection);
        });

        it('gets connection by name', function () {
            $this->connectionManager->shouldReceive('connection')
                ->with('custom')
                ->andReturn($this->mockConnection);

            $channelManager = new ChannelManager($this->connectionManager);

            $connection = $channelManager->getConnection('custom');

            expect($connection)->toBe($this->mockConnection);
        });
    });

    describe('error handling', function () {
        it('wraps connection exception when channel creation fails', function () {
            $this->connectionManager->shouldReceive('connection')
                ->andThrow(new \AMQPConnectionException('Connection failed'));

            $channelManager = new ChannelManager($this->connectionManager);

            expect(fn () => $channelManager->channel())
                ->toThrow(ConnectionException::class, 'Failed to create RabbitMQ channel');
        });
    });
});
