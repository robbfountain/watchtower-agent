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
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/watchtower.php' => config_path('watchtower.php'),
        ], 'watchtower-config');
    }
}
