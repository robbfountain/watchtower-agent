<?php

declare(strict_types=1);

namespace Watchtower\Agent\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Watchtower\Agent\AgentServiceProvider;

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
    }
}
