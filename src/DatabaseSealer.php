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
}
