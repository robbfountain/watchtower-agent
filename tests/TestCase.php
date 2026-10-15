<?php

declare(strict_types=1);

namespace Watchtower\Agent\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Watchtower\Agent\AgentServiceProvider;
use Watchtower\Agent\PackageCollector;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [AgentServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('watchtower.hub_url', 'https://hub.test');
        $app['config']->set('watchtower.token', 'test-token');
        $app['config']->set('watchtower.buffer.path', __DIR__.'/tmp/buffer.sqlite');
    }

    protected function setUp(): void
    {
        parent::setUp();

        @mkdir(__DIR__.'/tmp');
        @unlink(__DIR__.'/tmp/buffer.sqlite');

        $this->app->instance(PackageCollector::class, new PackageCollector($this->packageFixtureDirectory()));
    }

    private function packageFixtureDirectory(): string
    {
        $directory = __DIR__.'/tmp/package-fixture';
        @mkdir("{$directory}/vendor/composer", 0777, true);

        file_put_contents("{$directory}/vendor/composer/installed.json", json_encode([
            'packages' => [['name' => 'laravel/framework', 'pretty_version' => 'v13.0.0']],
            'dev-package-names' => [],
        ]));
        file_put_contents("{$directory}/composer.json", json_encode(['require' => ['laravel/framework' => '^13.0']]));

        return $directory;
    }
}
