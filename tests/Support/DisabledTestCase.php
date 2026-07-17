<?php

declare(strict_types=1);

namespace Watchtower\Agent\Tests\Support;

use Illuminate\Foundation\Application;
use Watchtower\Agent\Tests\TestCase;

class DisabledTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('watchtower.enabled', false);
    }
}
