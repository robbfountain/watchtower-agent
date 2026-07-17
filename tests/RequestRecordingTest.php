<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Watchtower\Agent\Buffer;
use Watchtower\Agent\PayloadBuilder;

it('records requests through the middleware', function () {
    Route::get('/ping', fn () => response()->json(['ok' => true]));

    $this->get('/ping')->assertOk();

    $rows = app(Buffer::class)->pull(10);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['type'])->toBe('request')
        ->and($rows[0]['payload']['method'])->toBe('GET')
        ->and($rows[0]['payload']['status'])->toBe(200)
        ->and($rows[0]['payload']['route'])->toBe('GET ping')
        ->and($rows[0]['payload']['duration_ms'])->toBeGreaterThanOrEqual(0);
});

it('rolls request rows into metrics and samples', function () {
    $builder = app(PayloadBuilder::class);
    $minute = '2026-07-17T12:00';
    $rows = [
        ['id' => 1, 'type' => 'request', 'payload' => ['route' => 'GET /a', 'url' => '/a', 'method' => 'GET', 'status' => 200, 'duration_ms' => 100, 'memory_kb' => 1024, 'occurred_at' => "{$minute}:01+00:00"], 'created_at' => ''],
        ['id' => 2, 'type' => 'request', 'payload' => ['route' => 'GET /a', 'url' => '/a', 'method' => 'GET', 'status' => 200, 'duration_ms' => 300, 'memory_kb' => 1024, 'occurred_at' => "{$minute}:30+00:00"], 'created_at' => ''],
        ['id' => 3, 'type' => 'request', 'payload' => ['route' => 'GET /a', 'url' => '/a?fail=1', 'method' => 'GET', 'status' => 500, 'duration_ms' => 2500, 'memory_kb' => 2048, 'occurred_at' => "{$minute}:45+00:00"], 'created_at' => ''],
    ];

    $payload = $builder->build($rows);

    expect($payload['request_metrics'])->toHaveCount(1)
        ->and($payload['request_metrics'][0]['count'])->toBe(3)
        ->and($payload['request_metrics'][0]['max_ms'])->toBe(2500)
        ->and($payload['request_metrics'][0]['count_2xx'])->toBe(2)
        ->and($payload['request_metrics'][0]['count_5xx'])->toBe(1)
        ->and($payload['request_samples'])->toHaveCount(1)
        ->and($payload['request_samples'][0]['type'])->toBe('error');
});
