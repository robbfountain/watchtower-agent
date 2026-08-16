<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Watchtower\Agent\PayloadBuilder;

it('reports the timezone configured on a scheduled event', function () {
    $schedule = app(Schedule::class);
    $schedule->command('inspire')->dailyAt('02:00')->timezone('America/New_York');

    $payload = (new PayloadBuilder)->build([]);

    $entry = collect($payload['schedule'])->firstWhere('command', 'inspire');

    expect($entry)->not->toBeNull()
        ->and($entry['timezone'])->toBe('America/New_York');
});

it('falls back to the application timezone when an event has none', function () {
    config()->set('app.timezone', 'Europe/London');

    $schedule = app(Schedule::class);
    $schedule->command('inspire')->dailyAt('02:00');

    $payload = (new PayloadBuilder)->build([]);

    $entry = collect($payload['schedule'])->firstWhere('command', 'inspire');

    expect($entry['timezone'])->toBe('Europe/London');
});
