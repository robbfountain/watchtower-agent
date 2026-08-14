<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Watchtower\Agent\AgentServiceProvider;
use Watchtower\Agent\Buffer;
use Watchtower\Agent\DatabaseSealer;

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
            && $payload['heartbeat']['agent_version'] === AgentServiceProvider::version()
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

it('forgets the batch when the hub returns 422', function () {
    Http::fake(['hub.test/*' => Http::response(['message' => 'invalid'], 422)]);
    seedBuffer();

    $this->artisan('watchtower:flush')->assertSuccessful();

    expect(app(Buffer::class)->count())->toBe(0);
});

it('drains more than one batch per flush', function () {
    Http::fake(['hub.test/*' => Http::response(['accepted' => true], 202)]);
    $buffer = app(Buffer::class);
    foreach (range(1, 1500) as $i) {
        $buffer->push('log_entry', ['level' => 'warning', 'message' => "m{$i}", 'context' => null, 'logged_at' => date('c')]);
    }

    $this->artisan('watchtower:flush')->assertSuccessful();

    Http::assertSentCount(2);
    expect($buffer->count())->toBe(0);
});

it('includes sealed database blobs in the flush when configured', function () {
    Http::fake(['hub.test/*' => Http::response(['accepted' => true], 202)]);
    $this->app->instance(DatabaseSealer::class, new DatabaseSealer(fn (string $name): bool => true));
    config()->set('watchtower.sealing_public_key', base64_encode(sodium_crypto_box_publickey(sodium_crypto_box_keypair())));
    config()->set('database.connections.mysql', ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306, 'database' => 'shop', 'username' => 'u', 'password' => 'p']);
    config()->set('watchtower.database_connections', ['mysql']);
    app(Buffer::class)->push('log_entry', ['level' => 'warning', 'message' => 'x', 'context' => null, 'logged_at' => date('c')]);

    $this->artisan('watchtower:flush')->assertSuccessful();

    Http::assertSent(function ($request) {
        $payload = json_decode(gzdecode($request->body()), true);

        return $payload['heartbeat']['agent_version'] === AgentServiceProvider::version()
            && isset($payload['databases'])
            && count($payload['databases']) === 1;
    });
});

it('flushes request, cache, and notification sections in the wire format', function () {
    Http::fake(['hub.test/*' => Http::response(['accepted' => true], 202)]);
    $buffer = app(Buffer::class);
    $buffer->push('request', ['route' => 'GET /a', 'url' => '/a', 'method' => 'GET', 'status' => 500, 'duration_ms' => 2000, 'memory_kb' => 1024, 'occurred_at' => '2026-07-17T12:00:01+00:00']);
    $buffer->push('cache_op', ['store' => 'redis', 'op' => 'hit', 'occurred_at' => '2026-07-17T12:00:01+00:00']);
    $buffer->push('notification', ['channel' => 'mail', 'notification' => 'App\\Notifications\\X', 'notifiable_type' => 'App\\Models\\User', 'status' => 'failed', 'error' => 'smtp down', 'occurred_at' => '2026-07-17T12:00:01+00:00']);

    $this->artisan('watchtower:flush')->assertSuccessful();

    Http::assertSent(function ($request) {
        $payload = json_decode(gzdecode($request->body()), true);

        return $payload['heartbeat']['agent_version'] === AgentServiceProvider::version()
            && $payload['request_metrics'][0]['count'] === 1
            && $payload['request_samples'][0]['type'] === 'error'
            && $payload['cache_metrics'][0]['hits'] === 1
            && $payload['notification_events'][0]['status'] === 'failed';
    });

    expect(app(Buffer::class)->count())->toBe(0);
});
