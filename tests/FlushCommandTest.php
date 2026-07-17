<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Watchtower\Agent\Buffer;

function seedBuffer(): void
{
    $buffer = app(Buffer::class);
    $buffer->push('job_event', ['queue' => 'default', 'job_class' => 'A', 'status' => 'completed', 'runtime_ms' => 5, 'exception' => null, 'occurred_at' => '2026-07-17T12:00:00+00:00']);
    $buffer->push('exception', ['hash' => str_repeat('a', 64), 'class' => 'RuntimeException', 'message' => 'boom', 'file' => '/x.php', 'line' => 1, 'trace' => ['#0'], 'occurred_at' => '2026-07-17T12:00:00+00:00']);
    $buffer->push('exception', ['hash' => str_repeat('a', 64), 'class' => 'RuntimeException', 'message' => 'boom', 'file' => '/x.php', 'line' => 1, 'trace' => ['#0'], 'occurred_at' => '2026-07-17T12:01:00+00:00']);
    $buffer->push('log_entry', ['level' => 'warning', 'message' => 'w', 'context' => null, 'logged_at' => '2026-07-17T12:00:00+00:00']);
    $buffer->push('task_run', ['command' => 'inspire', 'status' => 'finished', 'started_at' => '2026-07-17T12:00:00+00:00', 'finished_at' => '2026-07-17T12:00:01+00:00', 'exit_code' => 0]);
}

it('flushes the buffer to the hub and clears it on success', function () {
    Http::fake(['hub.test/*' => Http::response(['accepted' => true], 202)]);
    seedBuffer();

    $this->artisan('watchtower:flush')->assertSuccessful();

    Http::assertSent(function ($request) {
        $payload = json_decode(gzdecode($request->body()), true);

        return $request->url() === 'https://hub.test/api/agent/ingest'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->hasHeader('Content-Encoding', 'gzip')
            && $payload['heartbeat']['agent_version'] === '0.1.0'
            && count($payload['job_events']) === 1
            && count($payload['exceptions']) === 1
            && $payload['exceptions'][0]['count'] === 2
            && $payload['exceptions'][0]['first_seen_at'] === '2026-07-17T12:00:00+00:00'
            && $payload['exceptions'][0]['last_seen_at'] === '2026-07-17T12:01:00+00:00'
            && count($payload['log_entries']) === 1
            && count($payload['task_runs']) === 1
            && isset($payload['queue_snapshots'][0]['pending_count'])
            && isset($payload['schedule']);
    });

    expect(app(Buffer::class)->count())->toBe(0);
});

it('keeps the buffer when the hub rejects or is unreachable', function () {
    Http::fake(['hub.test/*' => Http::response('error', 500)]);
    seedBuffer();

    $this->artisan('watchtower:flush')->assertSuccessful();

    expect(app(Buffer::class)->count())->toBe(5);
});

it('exits cleanly when hub_url or token is missing', function () {
    config()->set('watchtower.hub_url', null);
    seedBuffer();

    $this->artisan('watchtower:flush')->assertSuccessful();

    expect(app(Buffer::class)->count())->toBe(5);
});
