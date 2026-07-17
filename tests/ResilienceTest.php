<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Watchtower\Agent\Buffer;

it('flush never throws even with an unreachable hub', function () {
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('unreachable'));
    app(Buffer::class)->push('log_entry', ['level' => 'warning', 'message' => 'x', 'context' => null, 'logged_at' => date('c')]);

    $this->artisan('watchtower:flush')->assertSuccessful();

    expect(app(Buffer::class)->count())->toBe(1);
});

it('buffer cap holds under event floods', function () {
    config()->set('watchtower.buffer.max_rows', 50);
    $buffer = new Watchtower\Agent\Buffer(__DIR__.'/tmp/flood.sqlite', 50);

    foreach (range(1, 500) as $n) {
        $buffer->push('log_entry', ['n' => $n]);
    }

    expect($buffer->count())->toBe(50);
});
