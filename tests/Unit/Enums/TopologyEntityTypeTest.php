<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Enums\TopologyEntityType;

describe('TopologyEntityType enum', function () {
    it('has exchange type', function () {
        expect(TopologyEntityType::Exchange->value)->toBe('exchange');
    });

    it('has queue type', function () {
        expect(TopologyEntityType::Queue->value)->toBe('queue');
    });

    it('has binding type', function () {
        expect(TopologyEntityType::Binding->value)->toBe('binding');
    });

    it('can be created from string values', function () {
        expect(TopologyEntityType::from('exchange'))->toBe(TopologyEntityType::Exchange);
        expect(TopologyEntityType::from('queue'))->toBe(TopologyEntityType::Queue);
        expect(TopologyEntityType::from('binding'))->toBe(TopologyEntityType::Binding);
    });

    it('has exactly three cases', function () {
        expect(TopologyEntityType::cases())->toHaveCount(3);
    });
});
