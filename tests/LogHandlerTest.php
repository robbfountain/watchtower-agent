<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Watchtower\Agent\Buffer;

it('captures warning and above on the default channel', function () {
    Log::warning('disk almost full', ['free_mb' => 120]);
    Log::error('boom');

    $rows = app(Buffer::class)->pull(10);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['type'])->toBe('log_entry')
        ->and($rows[0]['payload']['level'])->toBe('warning')
        ->and($rows[0]['payload']['message'])->toBe('disk almost full')
        ->and($rows[0]['payload']['context'])->toBe(['free_mb' => 120]);
});

it('ignores levels below the configured minimum', function () {
    Log::info('routine noise');

    expect(app(Buffer::class)->count())->toBe(0);
});
