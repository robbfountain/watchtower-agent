<?php

declare(strict_types=1);

use Watchtower\Agent\DatabaseSealer;

function reachableSealer(): DatabaseSealer
{
    return new DatabaseSealer(fn (string $name): bool => true);
}

it('seals configured mysql connections to the hub public key', function () {
    $keypair = sodium_crypto_box_keypair();
    config()->set('watchtower.sealing_public_key', base64_encode(sodium_crypto_box_publickey($keypair)));
    config()->set('database.connections.mysql', [
        'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
        'database' => 'shop', 'username' => 'shopuser', 'password' => 'pw',
    ]);
    config()->set('watchtower.database_connections', ['mysql']);

    $blobs = reachableSealer()->sealed();

    expect($blobs)->toHaveCount(1);

    $opened = json_decode(sodium_crypto_box_seal_open(base64_decode($blobs[0]), $keypair), true);
    expect($opened['database'])->toBe('shop')
        ->and($opened['password'])->toBe('pw');
});

it('returns nothing when no public key is configured', function () {
    config()->set('watchtower.sealing_public_key', null);
    config()->set('database.connections.mysql', ['driver' => 'mysql', 'host' => 'h', 'database' => 'd', 'username' => 'u', 'password' => 'p']);

    expect(reachableSealer()->sealed())->toBe([]);
});

it('skips non-mysql connections', function () {
    config()->set('watchtower.sealing_public_key', base64_encode(sodium_crypto_box_publickey(sodium_crypto_box_keypair())));
    config()->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:']);
    config()->set('watchtower.database_connections', ['sqlite']);

    expect(reachableSealer()->sealed())->toBe([]);
});

it('skips connections it cannot open so a commented-out default database is not reported', function () {
    $keypair = sodium_crypto_box_keypair();
    config()->set('watchtower.sealing_public_key', base64_encode(sodium_crypto_box_publickey($keypair)));
    config()->set('database.connections.mysql', [
        'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
        'database' => 'laravel', 'username' => 'root', 'password' => '',
    ]);
    config()->set('watchtower.database_connections', ['mysql']);

    $sealer = new DatabaseSealer(fn (string $name): bool => false);

    expect($sealer->sealed())->toBe([]);
});

it('seals only the connections it can open', function () {
    $keypair = sodium_crypto_box_keypair();
    config()->set('watchtower.sealing_public_key', base64_encode(sodium_crypto_box_publickey($keypair)));
    config()->set('database.connections.live', [
        'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
        'database' => 'shop', 'username' => 'shopuser', 'password' => 'pw',
    ]);
    config()->set('database.connections.phantom', [
        'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
        'database' => 'laravel', 'username' => 'root', 'password' => '',
    ]);
    config()->set('watchtower.database_connections', ['live', 'phantom']);

    $sealer = new DatabaseSealer(fn (string $name): bool => $name === 'live');

    $blobs = $sealer->sealed();

    expect($blobs)->toHaveCount(1);

    $opened = json_decode(sodium_crypto_box_seal_open(base64_decode($blobs[0]), $keypair), true);
    expect($opened['database'])->toBe('shop');
});

it('seals the valid connection and skips the malformed one when two connections are configured', function () {
    $keypair = sodium_crypto_box_keypair();
    config()->set('watchtower.sealing_public_key', base64_encode(sodium_crypto_box_publickey($keypair)));
    config()->set('database.connections.mysql', [
        'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
        'database' => 'shop', 'username' => 'shopuser', 'password' => 'pw',
    ]);
    // Invalid UTF-8 byte sequence in the password will cause json_encode to throw.
    config()->set('database.connections.broken', [
        'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
        'database' => 'broken', 'username' => 'u', 'password' => "\xB1\x31",
    ]);
    config()->set('watchtower.database_connections', ['mysql', 'broken']);

    $blobs = reachableSealer()->sealed();

    expect($blobs)->toHaveCount(1);

    $opened = json_decode(sodium_crypto_box_seal_open(base64_decode($blobs[0]), $keypair), true);
    expect($opened['database'])->toBe('shop');
});
