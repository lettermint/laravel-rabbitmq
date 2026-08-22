<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Discovery\AttributeTopologyCache;

it('compiles and checks an attribute topology cache', function () {
    $directory = sys_get_temp_dir().'/rabbitmq-command-cache-'.bin2hex(random_bytes(8));
    $cachePath = $directory.'/topology.php';
    config()->set('rabbitmq.discovery', [
        'cache' => true,
        'cache_path' => $cachePath,
        'paths' => [__DIR__.'/../Fixtures/CachedTopology'],
    ]);
    app()->forgetInstance(AttributeTopologyCache::class);

    try {
        $this->artisan('rabbitmq:cache')
            ->expectsOutputToContain('Cached 2 exchanges and 1 queues')
            ->assertSuccessful();

        $this->artisan('rabbitmq:cache', ['--check' => true])
            ->expectsOutputToContain('cache is current')
            ->assertSuccessful();
    } finally {
        if (is_file($cachePath)) {
            unlink($cachePath);
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});
