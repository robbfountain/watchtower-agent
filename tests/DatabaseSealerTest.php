<?php

declare(strict_types=1);

use Watchtower\Agent\DatabaseSealer;

it('seals configured mysql connections to the hub public key', function () {
    $keypair = sodium_crypto_box_keypair();
    config()->set('watchtower.sealing_public_key', base64_encode(sodium_crypto_box_publickey($keypair)));
    config()->set('database.connections.mysql', [
        'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
        'database' => 'shop', 'username' => 'shopuser', 'password' => 'pw',
    ]);
    config()->set('watchtower.database_connections', ['mysql']);

    $blobs = app(DatabaseSealer::class)->sealed();

    expect($blobs)->toHaveCount(1);

    $opened = json_decode(sodium_crypto_box_seal_open(base64_decode($blobs[0]), $keypair), true);
    expect($opened['database'])->toBe('shop')
        ->and($opened['password'])->toBe('pw');
});

it('returns nothing when no public key is configured', function () {
    config()->set('watchtower.sealing_public_key', null);
    config()->set('database.connections.mysql', ['driver' => 'mysql', 'host' => 'h', 'database' => 'd', 'username' => 'u', 'password' => 'p']);

    expect(app(DatabaseSealer::class)->sealed())->toBe([]);
});

it('skips non-mysql connections', function () {
    config()->set('watchtower.sealing_public_key', base64_encode(sodium_crypto_box_publickey(sodium_crypto_box_keypair())));
    config()->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:']);
    config()->set('watchtower.database_connections', ['sqlite']);

    expect(app(DatabaseSealer::class)->sealed())->toBe([]);
});
