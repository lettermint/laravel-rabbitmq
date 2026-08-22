<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Discovery\AttributeScanner;
use Lettermint\RabbitMQ\Discovery\AttributeTopologyCache;

function attributeTopologyCache(string $cachePath, array $paths): AttributeTopologyCache
{
    return new AttributeTopologyCache(new AttributeScanner, [
        'discovery' => [
            'cache' => true,
            'cache_path' => $cachePath,
            'paths' => $paths,
        ],
    ]);
}

test('compiles attributes into logical explicit topology', function () {
    $cache = attributeTopologyCache(
        sys_get_temp_dir().'/rabbitmq-topology-unused.php',
        [
            __DIR__.'/../../Fixtures/Jobs',
            __DIR__.'/../../Fixtures/Exchanges',
        ],
    );

    $topology = $cache->compile();

    expect($topology['exchanges']['emails'])->toMatchArray([
        'type' => 'topic',
        'durable' => true,
        'auto_delete' => false,
    ])->and($topology['queues']['emails:outbound'])->toMatchArray([
        'bindings' => ['emails' => ['outbound.*']],
        'default_exchange' => false,
        'quorum' => true,
        'dead_letter' => true,
        'dead_letter_exchange' => 'emails.dlq',
        'dead_letter_queue' => 'dlq:emails:outbound',
        'dead_letter_routing_key' => 'emails.outbound',
    ]);
});

test('writes and loads a current cache without a physical prefix', function () {
    $directory = sys_get_temp_dir().'/rabbitmq-cache-'.bin2hex(random_bytes(8));
    $cachePath = $directory.'/topology.php';
    $cache = attributeTopologyCache($cachePath, [__DIR__.'/../../Fixtures/Jobs']);

    try {
        $topology = $cache->compile();
        $cache->write($topology);

        expect($cache->load())->toBe($topology)
            ->and($cache->isCurrent())->toBeTrue()
            ->and(fileperms($cachePath) & 0777)->toBe(0644)
            ->and(file_get_contents($cachePath))->not->toContain('staging.');
    } finally {
        if (is_file($cachePath)) {
            unlink($cachePath);
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});

test('reports an out of date cache when a discovery file changes', function () {
    $directory = sys_get_temp_dir().'/rabbitmq-cache-'.bin2hex(random_bytes(8));
    mkdir($directory, 0755, true);
    $sourcePath = $directory.'/Marker.php';
    $cachePath = $directory.'/topology.php';
    file_put_contents($sourcePath, "<?php\n\nfinal class CacheMarker {}\n");
    $cache = attributeTopologyCache($cachePath, [$directory]);

    try {
        $cache->write($cache->compile());
        expect($cache->isCurrent())->toBeTrue();

        file_put_contents($sourcePath, "<?php\n\nfinal class ChangedCacheMarker {}\n");

        expect($cache->isCurrent())->toBeFalse();
    } finally {
        foreach ([$cachePath, $sourcePath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});
