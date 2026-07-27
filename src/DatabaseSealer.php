<?php

declare(strict_types=1);

namespace Watchtower\Agent;

use Throwable;

class DatabaseSealer
{
    /** @var (callable(string): bool)|null */
    private $connectionTester;

    /** @param (callable(string): bool)|null $connectionTester */
    public function __construct(?callable $connectionTester = null)
    {
        $this->connectionTester = $connectionTester;
    }

    /** @return array<int, string> */
    public function sealed(): array
    {
        try {
            if (! (bool) config('watchtower.report_databases', true)) {
                return [];
            }

            $publicKey = config('watchtower.sealing_public_key');

            if (! is_string($publicKey) || $publicKey === '' || ! function_exists('sodium_crypto_box_seal')) {
                return [];
            }

            $publicKeyRaw = base64_decode($publicKey, true);

            if ($publicKeyRaw === false || strlen($publicKeyRaw) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
                return [];
            }

            $blobs = [];

            foreach ((array) config('watchtower.database_connections', ['mysql']) as $name) {
                $connection = config("database.connections.{$name}");

                if (! is_array($connection) || ($connection['driver'] ?? null) !== 'mysql') {
                    continue;
                }

                try {
                    $credential = [
                        'host' => (string) ($connection['host'] ?? '127.0.0.1'),
                        'port' => (int) ($connection['port'] ?? 3306),
                        'database' => (string) ($connection['database'] ?? ''),
                        'username' => (string) ($connection['username'] ?? ''),
                        'password' => (string) ($connection['password'] ?? ''),
                    ];

                    if ($credential['database'] === '') {
                        continue;
                    }

                    if (! $this->canConnect($name)) {
                        continue;
                    }

                    $encoded = json_encode($credential, JSON_THROW_ON_ERROR);
                    $blobs[] = base64_encode(sodium_crypto_box_seal($encoded, $publicKeyRaw));
                } catch (Throwable $throwable) {
                    error_log("watchtower-agent: failed to seal connection credential: {$throwable->getMessage()}");
                }
            }

            return $blobs;
        } catch (Throwable $throwable) {
            error_log("watchtower-agent: database sealing failed: {$throwable->getMessage()}");

            return [];
        }
    }

    private function canConnect(string $name): bool
    {
        if ($this->connectionTester !== null) {
            return ($this->connectionTester)($name);
        }

        try {
            app('db')->connection($name)->getPdo();

            return true;
        } catch (Throwable $throwable) {
            error_log("watchtower-agent: skipping unreachable database connection '{$name}': {$throwable->getMessage()}");

            return false;
        }
    }
}
