<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Batch\BatchOptions;
use Lettermint\RabbitMQ\Consumers\Consumer;
use Lettermint\RabbitMQ\Tests\Fixtures\Batch\BatchStorageHandler;
use Lettermint\RabbitMQ\Tests\TestCase;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$test = new class('bootBatchWorker') extends TestCase
{
    public function bootBatchWorker(): void
    {
        $this->setUp();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $settings = json_decode((string) getenv('RABBITMQ_TEST_CONFIG'), true, flags: JSON_THROW_ON_ERROR);
        $app['config']->set('rabbitmq', $settings);
        $app['config']->set('queue.connections.rabbitmq-integration', [
            'driver' => 'rabbitmq',
            'connection' => 'default',
            'queue' => 'default',
        ]);
    }
};

$test->bootBatchWorker();

try {
    app(Consumer::class)
        ->setConnection('rabbitmq-integration')
        ->setQueue('default')
        ->setMaxJobs((int) (getenv('RABBITMQ_TEST_MAX_JOBS') ?: 1))
        ->setMaxTime((int) (getenv('RABBITMQ_TEST_MAX_TIME') ?: 20))
        ->setMaxMemory((int) (getenv('RABBITMQ_TEST_MAX_MEMORY') ?: 128))
        ->setTimeout((int) (getenv('RABBITMQ_TEST_TIMEOUT') ?: 10))
        ->setWaitTimeout(0.1)
        ->setTries((int) (getenv('RABBITMQ_TEST_TRIES') ?: 3))
        ->consumeBatch(BatchStorageHandler::class, new BatchOptions(
            maxCount: (int) (getenv('RABBITMQ_TEST_MAX_COUNT') ?: 10),
            maxBytes: (int) (getenv('RABBITMQ_TEST_MAX_BYTES') ?: 1048576),
            maxWaitSeconds: (float) (getenv('RABBITMQ_TEST_MAX_WAIT') ?: 1),
        ));
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
