<?php

declare(strict_types=1);

namespace Watchtower\Agent;

use Illuminate\Support\ServiceProvider;

class AgentServiceProvider extends ServiceProvider
{
    public const VERSION = '0.1.0';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/watchtower.php', 'watchtower');

        $this->app->singleton(Buffer::class, function ($app): Buffer {
            $path = $app['config']->get('watchtower.buffer.path')
                ?? $app->storagePath('watchtower/buffer.sqlite');

            return new Buffer($path, (int) $app['config']->get('watchtower.buffer.max_rows', 10000));
        });

        $this->app->singleton(Recorder::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/watchtower.php' => config_path('watchtower.php'),
        ], 'watchtower-config');
    }
}
