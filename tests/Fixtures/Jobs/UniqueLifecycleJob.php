<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Tests\Fixtures\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;

final class UniqueLifecycleJob extends LifecycleJob implements ShouldBeUnique
{
    public function uniqueId(): string
    {
        return $this->id;
    }
}
