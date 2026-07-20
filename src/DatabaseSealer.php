<?php

declare(strict_types=1);

namespace Watchtower\Agent;

use Throwable;

class DatabaseSealer
{
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

                $blobs[] = base64_encode(sodium_crypto_box_seal(json_encode($credential), $publicKeyRaw));
            }

            return $blobs;
        } catch (Throwable $throwable) {
            error_log("watchtower-agent: database sealing failed: {$throwable->getMessage()}");

            return [];
        }
    }
}
