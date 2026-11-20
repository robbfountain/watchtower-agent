<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
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

it('schedules the flush every minute in the background', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'watchtower:flush'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->runInBackground)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeFalse();
});

it('declares the 0.7.1 version', function () {
    expect(AgentServiceProvider::VERSION)->toBe('0.7.1');
});
