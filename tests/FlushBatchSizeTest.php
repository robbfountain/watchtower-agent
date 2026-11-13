<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Watchtower\Agent\AgentServiceProvider;
use Watchtower\Agent\Buffer;
use Watchtower\Agent\PackageCollector;

function batchSizePushLog(string $message): void
{
    app(Buffer::class)->push('log_entry', ['level' => 'warning', 'message' => $message, 'context' => null, 'logged_at' => '2026-07-17T12:00:00+00:00']);
}

function batchSizeBodies(): array
{
    $bodies = [];

    foreach (Http::recorded() as [$request]) {
        $bodies[] = gzdecode($request->body());
    }

    return $bodies;
}

beforeEach(function () {
    config(['watchtower.flush.max_batch_bytes' => 4000]);
});

it('sends the agent version header', function () {
    Http::fake(['hub.test/*' => Http::response(['accepted' => true], 202)]);
    seedBuffer();

    $this->artisan('watchtower:flush')->assertSuccessful();

    Http::assertSent(fn ($request) => $request->hasHeader('X-Watchtower-Agent-Version', AgentServiceProvider::version()));
});

it('splits a batch that exceeds the byte limit', function () {
    Http::fake(['hub.test/*' => Http::response(['accepted' => true], 202)]);

    for ($i = 0; $i < 20; $i++) {
        batchSizePushLog(str_repeat('x', 500));
    }

    $this->artisan('watchtower:flush')->assertSuccessful();

    $bodies = batchSizeBodies();

    expect(count($bodies))->toBeGreaterThan(1);

    foreach ($bodies as $body) {
        expect(strlen($body))->toBeLessThanOrEqual(4000);
    }

    expect(app(Buffer::class)->count())->toBe(0);
});

it('drops a single row larger than the byte limit and keeps the rest', function () {
    Http::fake(['hub.test/*' => Http::response(['accepted' => true], 202)]);
    batchSizePushLog(str_repeat('y', 6000));
    batchSizePushLog('small');

    $this->artisan('watchtower:flush')->assertSuccessful();

    $bodies = batchSizeBodies();

    expect($bodies)->not->toBeEmpty();

    foreach ($bodies as $body) {
        expect(strlen($body))->toBeLessThanOrEqual(4000);
    }

    expect(implode('', $bodies))->toContain('small');
    expect(app(Buffer::class)->count())->toBe(0);
});

it('halves the batch when the hub answers 413', function () {
    Http::fake(['hub.test/*' => Http::sequence()
        ->push([], 413)
        ->push(['accepted' => true], 202)
        ->push(['accepted' => true], 202),
    ]);

    for ($i = 0; $i < 4; $i++) {
        batchSizePushLog("row {$i}");
    }

    $this->artisan('watchtower:flush')->assertSuccessful();

    $counts = array_map(fn (string $body) => count(json_decode($body, true)['log_entries']), batchSizeBodies());

    expect($counts)->toBe([4, 2, 2]);

    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => $request->hasHeader('X-Watchtower-Agent-Version', AgentServiceProvider::version()));
    foreach (Http::recorded() as [$request]) {
        expect($request->hasHeader('X-Watchtower-Agent-Version', AgentServiceProvider::version()))->toBeTrue();
    }
    expect(app(Buffer::class)->count())->toBe(0);
});

it('drops a single row the hub still rejects with 413', function () {
    Http::fake(['hub.test/*' => Http::response([], 413)]);
    batchSizePushLog('lonely');

    $this->artisan('watchtower:flush')->assertSuccessful();

    Http::assertSentCount(1);
    expect(app(Buffer::class)->count())->toBe(0);
});

it('keeps the buffer on a 500', function () {
    Http::fake(['hub.test/*' => Http::response([], 500)]);
    seedBuffer();
    $before = app(Buffer::class)->count();

    $this->artisan('watchtower:flush')->assertSuccessful();

    expect(app(Buffer::class)->count())->toBe($before);
});

function batchSizeWithPackages(): void
{
    Cache::flush();
    $dir = __DIR__.'/tmp/batch-size-packages-'.uniqid();
    @mkdir("{$dir}/vendor/composer", 0777, true);
    file_put_contents("{$dir}/vendor/composer/installed.json", json_encode(['packages' => [
        ['name' => 'laravel/framework', 'pretty_version' => 'v13.1.0'],
    ], 'dev-package-names' => []]));
    file_put_contents("{$dir}/composer.json", json_encode(['require' => ['laravel/framework' => '^13.0']]));
    app()->instance(PackageCollector::class, new PackageCollector($dir));
}

function batchSizeLastPayload(): array
{
    return json_decode(gzdecode(Http::recorded()->last()[0]->body()), true);
}

it('stops after one request on a heartbeat-only 413 and marks packages sent', function () {
    batchSizeWithPackages();
    Http::fake(['hub.test/*' => Http::response([], 413)]);

    $this->artisan('watchtower:flush')->assertSuccessful();

    Http::assertSentCount(1);
    expect(batchSizeLastPayload())->toHaveKey('packages');

    Http::fake(['hub.test/*' => Http::response(['accepted' => true], 202)]);
    $this->artisan('watchtower:flush')->assertSuccessful();

    expect(batchSizeLastPayload())->not->toHaveKey('packages');
});

it('marks packages sent when a row batch is rejected with 413', function () {
    batchSizeWithPackages();
    Http::fake(['hub.test/*' => Http::response([], 413)]);
    batchSizePushLog('one');
    batchSizePushLog('two');

    $this->artisan('watchtower:flush')->assertSuccessful();

    Http::assertSentCount(2);
    expect(app(Buffer::class)->count())->toBe(1);

    Http::fake(['hub.test/*' => Http::response(['accepted' => true], 202)]);
    $this->artisan('watchtower:flush')->assertSuccessful();

    expect(batchSizeLastPayload())->not->toHaveKey('packages');
});

it('sends small rows when the fixed sections alone exceed the byte limit', function () {
    batchSizeWithPackages();
    Http::fake(['hub.test/*' => Http::response(['accepted' => true], 202)]);

    $this->artisan('watchtower:flush')->assertSuccessful();
    $fixedBytes = strlen(gzdecode(Http::recorded()[0][0]->body()));

    Cache::flush();
    batchSizeWithPackages();
    config(['watchtower.flush.max_batch_bytes' => (int) ($fixedBytes / 2)]);
    batchSizePushLog('small one');
    batchSizePushLog('small two');

    $this->artisan('watchtower:flush')->assertSuccessful();

    $messages = [];
    foreach (batchSizeBodies() as $body) {
        foreach (json_decode($body, true)['log_entries'] ?? [] as $entry) {
            $messages[] = $entry['message'];
        }
    }

    expect($messages)->toBe(['small one', 'small two']);
    expect(app(Buffer::class)->count())->toBe(0);
});

it('forgets only the half that was sent when a 413 is followed by a 422', function () {
    Http::fake(['hub.test/*' => Http::sequence()
        ->push([], 413)
        ->push(['errors' => ['payload' => ['bad']]], 422),
    ]);

    for ($i = 0; $i < 4; $i++) {
        batchSizePushLog("row {$i}");
    }

    $this->artisan('watchtower:flush')->assertSuccessful();

    Http::assertSentCount(2);
    $remaining = app(Buffer::class)->pull(10);
    expect(array_column(array_column($remaining, 'payload'), 'message'))->toBe(['row 2', 'row 3']);
});

it('still sends a heartbeat when the only buffered row was dropped', function () {
    Http::fake(['hub.test/*' => Http::response(['accepted' => true], 202)]);
    batchSizePushLog(str_repeat('z', 6000));

    $this->artisan('watchtower:flush')->assertSuccessful();

    Http::assertSentCount(1);
    expect(batchSizeLastPayload())->not->toHaveKey('log_entries');
    expect(app(Buffer::class)->count())->toBe(0);
});
