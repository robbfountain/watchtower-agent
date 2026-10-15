<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Watchtower\Agent\PackageCollector;

function packageFixtureDir(?array $installed, ?array $composer = null): string
{
    $dir = __DIR__.'/tmp/packages-'.uniqid();
    @mkdir("{$dir}/vendor/composer", 0777, true);

    if ($installed !== null) {
        file_put_contents("{$dir}/vendor/composer/installed.json", json_encode($installed));
    }

    if ($composer !== null) {
        file_put_contents("{$dir}/composer.json", json_encode($composer));
    }

    return $dir;
}

function packageFixtureInstalled(): array
{
    return [
        'packages' => [
            ['name' => 'laravel/framework', 'pretty_version' => 'v13.1.0', 'version' => '13.1.0.0'],
            ['name' => 'symfony/http-foundation', 'pretty_version' => 'v7.4.1', 'version' => '7.4.1.0'],
            ['name' => 'pestphp/pest', 'pretty_version' => 'v4.0.0', 'version' => '4.0.0.0'],
            ['name' => 'acme/no-pretty', 'version' => '1.0.0.0'],
        ],
        'dev' => true,
        'dev-package-names' => ['pestphp/pest'],
    ];
}

function packageFixtureComposer(): array
{
    return [
        'require' => ['php' => '^8.4', 'ext-json' => '*', 'laravel/framework' => '^13.0'],
        'require-dev' => ['pestphp/pest' => '^4.0'],
    ];
}

beforeEach(fn () => Cache::flush());

it('collects every installed package with direct and dev flags', function () {
    $collector = new PackageCollector(packageFixtureDir(packageFixtureInstalled(), packageFixtureComposer()));

    $packages = collect($collector->pending()['packages'])->keyBy('name');

    expect($packages)->toHaveCount(4)
        ->and($packages['laravel/framework'])->toBe(['name' => 'laravel/framework', 'version' => 'v13.1.0', 'direct' => true, 'dev' => false])
        ->and($packages['symfony/http-foundation']['direct'])->toBeFalse()
        ->and($packages['pestphp/pest'])->toBe(['name' => 'pestphp/pest', 'version' => 'v4.0.0', 'direct' => true, 'dev' => true])
        ->and($packages['acme/no-pretty']['version'])->toBe('1.0.0.0')
        ->and($packages->keys()->all())->not->toContain('php', 'ext-json');
});

it('reads the composer 1 flat installed.json format', function () {
    $collector = new PackageCollector(packageFixtureDir(packageFixtureInstalled()['packages'], packageFixtureComposer()));

    expect($collector->pending()['packages'])->toHaveCount(4);
});

it('marks every package transitive when composer.json is missing', function () {
    $collector = new PackageCollector(packageFixtureDir(packageFixtureInstalled()));

    $packages = $collector->pending()['packages'];

    expect(collect($packages)->pluck('direct')->unique()->all())->toBe([false]);
});

it('returns null when installed.json is missing', function () {
    expect((new PackageCollector(packageFixtureDir(null)))->pending())->toBeNull();
});

it('returns null when installed.json is not valid json', function () {
    $dir = packageFixtureDir(null);
    file_put_contents("{$dir}/vendor/composer/installed.json", '{not json');

    expect((new PackageCollector($dir))->pending())->toBeNull();
});

it('returns null when installed.json lists no packages', function () {
    expect((new PackageCollector(packageFixtureDir(['packages' => []])))->pending())->toBeNull();
});

it('drops entries whose name the hub would reject', function () {
    $installed = ['packages' => [
        ['name' => 'Acme/Upper', 'pretty_version' => '1.0.0'],
        ['name' => 'acme/fine', 'pretty_version' => '1.0.0'],
    ]];

    $packages = (new PackageCollector(packageFixtureDir($installed)))->pending()['packages'];

    expect(collect($packages)->pluck('name')->all())->toBe(['acme/fine']);
});

it('returns null once the current hash has been marked sent', function () {
    $collector = new PackageCollector(packageFixtureDir(packageFixtureInstalled(), packageFixtureComposer()));
    $pending = $collector->pending();

    $collector->markSent($pending['hash']);

    expect($collector->pending())->toBeNull();
});

it('reports again when installed.json changes', function () {
    $dir = packageFixtureDir(packageFixtureInstalled(), packageFixtureComposer());
    $collector = new PackageCollector($dir);
    $collector->markSent($collector->pending()['hash']);

    $changed = packageFixtureInstalled();
    $changed['packages'][0]['pretty_version'] = 'v13.2.0';
    file_put_contents("{$dir}/vendor/composer/installed.json", json_encode($changed));

    expect($collector->pending())->not->toBeNull();
});

it('emits a package once when installed.json lists it twice', function () {
    $installed = ['packages' => [
        ['name' => 'acme/dupe', 'pretty_version' => '1.0.0'],
        ['name' => 'acme/other', 'pretty_version' => '2.0.0'],
        ['name' => 'acme/dupe', 'pretty_version' => '9.9.9'],
    ]];

    $packages = (new PackageCollector(packageFixtureDir($installed)))->pending()['packages'];

    expect(collect($packages)->pluck('name')->all())->toBe(['acme/dupe', 'acme/other'])
        ->and($packages[0]['version'])->toBe('1.0.0');
});
