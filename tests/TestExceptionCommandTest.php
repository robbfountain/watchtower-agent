<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Watchtower\Agent\Buffer;

it('reports a synthetic exception and flushes it to the hub', function () {
    Http::fake(['hub.test/*' => Http::response(['accepted' => true], 202)]);

    $this->artisan('watchtower:test-exception')->assertSuccessful();

    Http::assertSent(function ($request) {
        $payload = json_decode(gzdecode($request->body()), true);

        return isset($payload['exceptions'])
            && collect($payload['exceptions'])->contains(fn ($e) => str_contains($e['message'], 'Watchtower test exception'));
    });
});

it('buffers the exception without flushing when --no-flush is passed', function () {
    Http::fake();

    $this->artisan('watchtower:test-exception', ['--no-flush' => true])->assertSuccessful();

    Http::assertNothingSent();
    expect(app(Buffer::class)->count())->toBeGreaterThan(0);
});
