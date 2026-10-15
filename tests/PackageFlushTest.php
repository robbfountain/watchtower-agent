<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Watchtower\Agent\PackageCollector;

function packageFlushCollector(): PackageCollector
{
    $dir = __DIR__.'/tmp/flush-packages-'.uniqid();
    @mkdir("{$dir}/vendor/composer", 0777, true);
    file_put_contents("{$dir}/vendor/composer/installed.json", json_encode(['packages' => [
        ['name' => 'laravel/framework', 'pretty_version' => 'v13.1.0'],
    ], 'dev-package-names' => []]));
    file_put_contents("{$dir}/composer.json", json_encode(['require' => ['laravel/framework' => '^13.0']]));

    return new PackageCollector($dir);
}

function packageFlushRecordedPayloads(): array
{
    return collect(Http::recorded())
        ->map(fn ($pair) => json_decode(gzdecode($pair[0]->body()), true))
        ->values()
        ->all();
}

function packageFlushSentPackages(): array
{
    return collect(packageFlushRecordedPayloads())
        ->filter(fn (array $payload) => array_key_exists('packages', $payload))
        ->values()
        ->all();
}

beforeEach(function () {
    Cache::flush();
    app()->instance(PackageCollector::class, packageFlushCollector());
});

it('sends packages on the first flush and not again while unchanged', function () {
    Http::fake(['hub.test/*' => Http::response(['accepted' => true], 202)]);

    $this->artisan('watchtower:flush')->assertSuccessful();
    $this->artisan('watchtower:flush')->assertSuccessful();

    $sent = packageFlushSentPackages();
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['packages'])->toBe([
            ['name' => 'laravel/framework', 'version' => 'v13.1.0', 'direct' => true, 'dev' => false],
        ]);
});

it('never sends the internal packages hash to the hub', function () {
    Http::fake(['hub.test/*' => Http::response(['accepted' => true], 202)]);

    $this->artisan('watchtower:flush')->assertSuccessful();

    $payloads = packageFlushRecordedPayloads();
    expect($payloads)->toHaveCount(1)
        ->and($payloads[0])->toHaveKey('packages')
        ->and($payloads[0])->not->toHaveKey('packages_hash');
});

it('resends packages after a failed flush', function () {
    Http::fakeSequence('hub.test/*')
        ->push('error', 500)
        ->push(['accepted' => true], 202);

    $this->artisan('watchtower:flush')->assertSuccessful();
    $this->artisan('watchtower:flush')->assertSuccessful();

    expect(packageFlushSentPackages())->toHaveCount(2)
        ->and(Cache::get(PackageCollector::CACHE_KEY))->not->toBeNull();
});

it('does not resend a package list the hub rejected', function () {
    Http::fake(['hub.test/*' => Http::response(['message' => 'invalid'], 422)]);

    $this->artisan('watchtower:flush')->assertSuccessful();
    $this->artisan('watchtower:flush')->assertSuccessful();

    expect(packageFlushSentPackages())->toHaveCount(1);
});

it('sends packages only in the first payload of a multi batch flush', function () {
    Http::fake(['hub.test/*' => Http::response(['accepted' => true], 202)]);
    $buffer = app(Watchtower\Agent\Buffer::class);
    foreach (range(1, 1500) as $i) {
        $buffer->push('log_entry', ['level' => 'info', 'message' => "m{$i}", 'context' => null, 'logged_at' => '2026-10-15T12:00:00+00:00']);
    }

    $this->artisan('watchtower:flush')->assertSuccessful();

    Http::assertSentCount(2);
    expect(packageFlushSentPackages())->toHaveCount(1);
});

it('still flushes without packages when the collector throws', function () {
    Http::fake(['hub.test/*' => Http::response(['accepted' => true], 202)]);
    app()->instance(PackageCollector::class, new class extends PackageCollector
    {
        public function pending(): ?array
        {
            throw new ErrorException('installed.json is malformed');
        }
    });

    $this->artisan('watchtower:flush')->assertSuccessful();

    $payloads = packageFlushRecordedPayloads();
    expect($payloads)->toHaveCount(1)
        ->and($payloads[0])->toHaveKey('heartbeat')
        ->and($payloads[0])->not->toHaveKey('packages');
});
