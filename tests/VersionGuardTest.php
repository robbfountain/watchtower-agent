<?php

declare(strict_types=1);

use Watchtower\Agent\AgentServiceProvider;

function runVersionGuard(string $tag): array
{
    $script = escapeshellarg(dirname(__DIR__).'/bin/assert-version.php');
    $output = [];
    $status = 0;

    exec('php '.$script.' '.escapeshellarg($tag).' 2>&1', $output, $status);

    return [$status, implode("\n", $output)];
}

it('passes when the tag matches the declared version', function () {
    [$status] = runVersionGuard('v'.AgentServiceProvider::VERSION);

    expect($status)->toBe(0);
});

it('fails when the tag does not match the declared version', function () {
    [$status, $output] = runVersionGuard('v99.99.99');

    expect($status)->toBe(1)
        ->and($output)->toContain(AgentServiceProvider::VERSION);
});

it('fails when no tag is given', function () {
    [$status] = runVersionGuard('');

    expect($status)->toBe(1);
});
