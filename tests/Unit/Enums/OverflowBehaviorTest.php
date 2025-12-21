<?php

declare(strict_types=1);

use Lettermint\RabbitMQ\Enums\OverflowBehavior;

describe('OverflowBehavior enum', function () {
    it('has drop-head behavior', function () {
        expect(OverflowBehavior::DropHead->value)->toBe('drop-head');
    });

    it('has reject-publish behavior', function () {
        expect(OverflowBehavior::RejectPublish->value)->toBe('reject-publish');
    });

    it('has reject-publish-dlx behavior', function () {
        expect(OverflowBehavior::RejectPublishDlx->value)->toBe('reject-publish-dlx');
    });

    it('can be created from string values', function () {
        expect(OverflowBehavior::from('drop-head'))->toBe(OverflowBehavior::DropHead);
        expect(OverflowBehavior::from('reject-publish'))->toBe(OverflowBehavior::RejectPublish);
        expect(OverflowBehavior::from('reject-publish-dlx'))->toBe(OverflowBehavior::RejectPublishDlx);
    });

    it('has exactly three cases', function () {
        expect(OverflowBehavior::cases())->toHaveCount(3);
    });
});
