<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Watchtower\Agent\Buffer;

it('emits a synthetic notification event and flushes it to the hub', function () {
    Http::fake(['hub.test/*' => Http::response(['accepted' => true], 202)]);

    $this->artisan('watchtower:test-notification')->assertSuccessful();

    Http::assertSent(function ($request) {
        $payload = json_decode(gzdecode($request->body()), true);

        return isset($payload['notification_events'])
            && collect($payload['notification_events'])->contains(fn ($n) => $n['status'] === 'sent'
                && str_contains($n['notification'], 'WatchtowerTestNotification'));
    });
});

it('buffers the notification event without flushing when --no-flush is passed', function () {
    Http::fake();

    $this->artisan('watchtower:test-notification', ['--no-flush' => true])->assertSuccessful();

    Http::assertNothingSent();
    expect(app(Buffer::class)->count())->toBeGreaterThan(0);
});
