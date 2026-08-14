<?php

declare(strict_types=1);

use Watchtower\Agent\AgentServiceProvider;

it('merges package config', function () {
    expect(config('watchtower.log_level'))->toBe('warning')
        ->and(config('watchtower.hub_url'))->toBe('https://hub.test');
});

it('resolves a non-empty agent version without a leading v', function () {
    $version = AgentServiceProvider::version();

    expect($version)->toBeString()->not->toBe('')
        ->and($version)->not->toStartWith('v');
});
