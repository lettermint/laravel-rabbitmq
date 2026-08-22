<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Console\Commands;

use Illuminate\Console\Command;
use Lettermint\RabbitMQ\Discovery\AttributeTopologyCache;

final class CacheTopologyCommand extends Command
{
    protected $signature = 'rabbitmq:cache
        {--check : Fail when the attribute topology cache is missing or out of date}';

    protected $description = 'Compile RabbitMQ topology attributes for use without runtime scanning';

    public function handle(AttributeTopologyCache $cache): int
    {
        if (! $cache->enabled()) {
            $this->components->error('RabbitMQ attribute topology caching is disabled.');

            return self::FAILURE;
        }

        if ($this->option('check')) {
            if (! $cache->isCurrent()) {
                $this->components->error('The RabbitMQ attribute topology cache is missing or out of date.');

                return self::FAILURE;
            }

            $this->components->info('The RabbitMQ attribute topology cache is current.');

            return self::SUCCESS;
        }

        $topology = $cache->compile();
        $cache->write($topology);

        $this->components->info(sprintf(
            'Cached %d exchanges and %d queues in %s.',
            count($topology['exchanges']),
            count($topology['queues']),
            $cache->path(),
        ));

        return self::SUCCESS;
    }
}
