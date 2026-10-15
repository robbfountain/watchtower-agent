<?php

declare(strict_types=1);

namespace Watchtower\Agent;

use Illuminate\Support\Facades\Cache;
use Throwable;

class PackageCollector
{
    public const CACHE_KEY = 'watchtower:packages_hash';

    private const RESYNC_SECONDS = 86400;

    private const NAME_PATTERN = '/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/';

    private const MAX_VERSION_LENGTH = 100;

    public function __construct(private readonly ?string $basePath = null) {}

    /** @return array{hash: string, packages: array<int, array{name: string, version: string, direct: bool, dev: bool}>}|null */
    public function pending(): ?array
    {
        $installedPath = $this->path('vendor/composer/installed.json');
        $contents = @file_get_contents($installedPath);

        if ($contents === false) {
            error_log("watchtower-agent: packages skipped, cannot read {$installedPath}");

            return null;
        }

        $hash = sha1($contents);

        if ($this->lastSentHash() === $hash) {
            return null;
        }

        $installed = json_decode($contents, true);

        if (! is_array($installed)) {
            error_log("watchtower-agent: packages skipped, {$installedPath} is not valid JSON");

            return null;
        }

        $packages = $this->packages($installed);

        if ($packages === []) {
            error_log("watchtower-agent: packages skipped, {$installedPath} lists no packages");

            return null;
        }

        return ['hash' => $hash, 'packages' => $packages];
    }

    public function markSent(string $hash): void
    {
        try {
            Cache::put(self::CACHE_KEY, $hash, self::RESYNC_SECONDS);
        } catch (Throwable $throwable) {
            error_log("watchtower-agent: could not cache packages hash: {$throwable->getMessage()}");
        }
    }

    /**
     * @param array<mixed> $installed
     * @return array<int, array{name: string, version: string, direct: bool, dev: bool}>
     */
    private function packages(array $installed): array
    {
        $entries = array_key_exists('packages', $installed) ? $installed['packages'] : $installed;
        $devNames = array_flip((array) ($installed['dev-package-names'] ?? []));
        $directNames = $this->directNames();
        $packages = [];

        foreach ((array) $entries as $entry) {
            $package = $this->package($entry, $directNames, $devNames);

            if ($package === null) {
                continue;
            }

            if (isset($packages[$package['name']])) {
                error_log("watchtower-agent: package {$package['name']} skipped, listed more than once");

                continue;
            }

            $packages[$package['name']] = $package;
        }

        return array_values($packages);
    }

    /**
     * @param array<string, int> $directNames
     * @param array<string, int> $devNames
     * @return array{name: string, version: string, direct: bool, dev: bool}|null
     */
    private function package(mixed $entry, array $directNames, array $devNames): ?array
    {
        if (! is_array($entry)) {
            return null;
        }

        $name = $entry['name'] ?? null;
        $version = $entry['pretty_version'] ?? $entry['version'] ?? null;

        if (! is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1) {
            error_log('watchtower-agent: package skipped, invalid name '.json_encode($name));

            return null;
        }

        if (! is_string($version) || $version === '' || strlen($version) > self::MAX_VERSION_LENGTH) {
            error_log("watchtower-agent: package {$name} skipped, invalid version");

            return null;
        }

        return [
            'name' => $name,
            'version' => $version,
            'direct' => isset($directNames[$name]),
            'dev' => isset($devNames[$name]),
        ];
    }

    /** @return array<string, int> */
    private function directNames(): array
    {
        $composerPath = $this->path('composer.json');
        $contents = @file_get_contents($composerPath);
        $composer = $contents === false ? null : json_decode($contents, true);

        if (! is_array($composer)) {
            error_log("watchtower-agent: cannot read {$composerPath}, reporting every package as transitive");

            return [];
        }

        $required = array_merge(
            array_keys((array) ($composer['require'] ?? [])),
            array_keys((array) ($composer['require-dev'] ?? [])),
        );

        return array_flip(array_filter($required, fn (string $name) => str_contains($name, '/')));
    }

    private function lastSentHash(): ?string
    {
        try {
            $hash = Cache::get(self::CACHE_KEY);
        } catch (Throwable $throwable) {
            error_log("watchtower-agent: could not read packages hash: {$throwable->getMessage()}");

            return null;
        }

        return is_string($hash) ? $hash : null;
    }

    private function path(string $relative): string
    {
        return rtrim($this->basePath ?? base_path(), '/').'/'.$relative;
    }
}
