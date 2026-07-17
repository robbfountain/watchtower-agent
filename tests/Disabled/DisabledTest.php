<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Watchtower\Agent\Buffer;

it('captures nothing when the master switch is off', function () {
    Log::warning('should not be captured');
    Log::error('also not captured');

    expect(app(Buffer::class)->count())->toBe(0);
});
