<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Consumers\Consumer;
use Lettermint\RabbitMQ\Events\MessagePublished;
use Lettermint\RabbitMQ\Tests\TestCase;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$test = new class('bootWorker') extends TestCase
{
    public function bootWorker(): void
    {
        $this->setUp();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $settings = json_decode((string) getenv('RABBITMQ_TEST_CONFIG'), true, flags: JSON_THROW_ON_ERROR);
        $app['config']->set('rabbitmq', $settings);
        $app['config']->set('queue.connections.rabbitmq-integration', ['driver' => 'rabbitmq', 'connection' => 'default', 'queue' => 'default']);
    }
};

$test->bootWorker();

if (getenv('RABBITMQ_TEST_EXIT_AFTER_PUBLISH') === '1') {
    app('events')->listen(MessagePublished::class, function (): never {
        exit(17);
    });
}

try {
    app(Consumer::class)
        ->setConnection('rabbitmq-integration')
        ->setQueue('default')
        ->setPrefetch(5)
        ->setMaxJobs((int) (getenv('RABBITMQ_TEST_MAX_JOBS') ?: 1))
        ->setMaxTime(20)
        ->setTimeout((int) (getenv('RABBITMQ_TEST_TIMEOUT') ?: 10))
        ->setTries(0)
        ->consume();
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage()."\n");
    exit(1);
}
