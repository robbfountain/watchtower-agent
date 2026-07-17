<?php

declare(strict_types=1);

it('merges package config', function () {
    expect(config('watchtower.log_level'))->toBe('warning')
        ->and(config('watchtower.hub_url'))->toBe('https://hub.test');
});
