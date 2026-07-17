<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Watchtower\Agent\Buffer;
use Watchtower\Agent\PayloadBuilder;

it('records cache operations', function () {
    Cache::put('watchtower-test-key', 1);
    Cache::get('watchtower-test-key');
    Cache::get('watchtower-missing-key');
    Cache::forget('watchtower-test-key');

    $ops = array_column(array_column(app(Buffer::class)->pull(20), 'payload'), 'op');

    expect($ops)->toContain('write', 'hit', 'miss', 'forget');
});

it('rolls cache ops into per-store minute metrics', function () {
    $rows = collect([['op' => 'hit'], ['op' => 'hit'], ['op' => 'miss'], ['op' => 'write']])
        ->map(fn (array $op, int $index) => [
            'id' => $index, 'type' => 'cache_op', 'created_at' => '',
            'payload' => $op + ['store' => 'array', 'occurred_at' => '2026-07-17T12:00:0'.$index.'+00:00'],
        ])->all();

    $payload = app(PayloadBuilder::class)->build($rows);

    expect($payload['cache_metrics'])->toHaveCount(1)
        ->and($payload['cache_metrics'][0]['hits'])->toBe(2)
        ->and($payload['cache_metrics'][0]['misses'])->toBe(1)
        ->and($payload['cache_metrics'][0]['writes'])->toBe(1)
        ->and($payload['cache_metrics'][0]['forgets'])->toBe(0);
});
