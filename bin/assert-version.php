<?php

declare(strict_types=1);

$tag = $argv[1] ?? '';
$expected = ltrim($tag, 'v');

if ($expected === '') {
    fwrite(STDERR, "Usage: php bin/assert-version.php <tag>\n");

    exit(1);
}

$providerPath = dirname(__DIR__).'/src/AgentServiceProvider.php';
$source = file_get_contents($providerPath);

if ($source === false) {
    fwrite(STDERR, "Unable to read {$providerPath}.\n");

    exit(1);
}

if (! preg_match("/public const VERSION = '([^']+)';/", $source, $matches)) {
    fwrite(STDERR, "Could not find AgentServiceProvider::VERSION in {$providerPath}.\n");

    exit(1);
}

$declared = $matches[1];

if ($declared !== $expected) {
    fwrite(STDERR, "Version mismatch: tag {$tag} but AgentServiceProvider::VERSION is '{$declared}'. Bump the constant before tagging.\n");

    exit(1);
}

echo "AgentServiceProvider::VERSION '{$declared}' matches tag {$tag}.\n";

exit(0);
