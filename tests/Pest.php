<?php

declare(strict_types=1);

use Watchtower\Agent\Tests\Support\DisabledTestCase;
use Watchtower\Agent\Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__.'/*.php');
pest()->extend(DisabledTestCase::class)->in('Disabled');
