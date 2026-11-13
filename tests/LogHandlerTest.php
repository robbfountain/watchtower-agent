<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Watchtower\Agent\Buffer;

it('captures warning and above on the default channel', function () {
    Log::warning('disk almost full', ['free_mb' => 120]);
    Log::error('boom');

    $rows = app(Buffer::class)->pull(10);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['type'])->toBe('log_entry')
        ->and($rows[0]['payload']['level'])->toBe('warning')
        ->and($rows[0]['payload']['message'])->toBe('disk almost full')
        ->and($rows[0]['payload']['context'])->toBe(['free_mb' => 120]);
});

it('ignores levels below the configured minimum', function () {
    Log::info('routine noise');

    expect(app(Buffer::class)->count())->toBe(0);
});

it('truncates log messages to 16384 characters', function () {
    Log::warning(str_repeat('a', 20000));

    $rows = app(Buffer::class)->pull(10);

    expect(mb_strlen($rows[0]['payload']['message']))->toBe(16384);
});

it('replaces oversized log context with a preview', function () {
    Log::warning('big context', ['blob' => str_repeat('x', 9000)]);

    $context = app(Buffer::class)->pull(10)[0]['payload']['context'];

    expect($context['_truncated'])->toBeTrue()
        ->and(strlen($context['preview']))->toBe(8192);
});

it('keeps log context nested exactly 8 arrays deep', function () {
    $nested = ['level' => 'leaf'];
    for ($i = 1; $i < 8; $i++) {
        $nested = ['level' => $nested];
    }

    Log::warning('boundary context', $nested);

    expect(app(Buffer::class)->pull(10)[0]['payload']['context'])->toBe($nested);
});

it('replaces log context nested 9 arrays deep with a preview', function () {
    $nested = ['level' => 'leaf'];
    for ($i = 1; $i < 9; $i++) {
        $nested = ['level' => $nested];
    }

    Log::warning('deep context', $nested);

    $context = app(Buffer::class)->pull(10)[0]['payload']['context'];

    expect($context['_truncated'])->toBeTrue()
        ->and($context['preview'])->toBeString();
});

it('replaces log context holding INF with a preview and keeps the row', function () {
    Log::warning('ratio failed', ['ratio' => INF]);

    $payload = app(Buffer::class)->pull(10)[0]['payload'];

    expect($payload['message'])->toBe('ratio failed')
        ->and($payload['context']['_truncated'])->toBeTrue()
        ->and($payload['context']['preview'])->toBeString();
});

it('replaces log context holding a resource or recursive object with a preview', function () {
    $recursive = new stdClass;
    $recursive->self = $recursive;
    $handle = fopen('php://memory', 'r');

    Log::warning('resource context', ['handle' => $handle]);
    Log::warning('recursive context', ['node' => $recursive]);
    fclose($handle);

    $rows = app(Buffer::class)->pull(10);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['payload']['context']['_truncated'])->toBeTrue()
        ->and($rows[1]['payload']['context']['_truncated'])->toBeTrue();
});

it('keeps small log context intact', function () {
    Log::warning('small context', ['user_id' => 5]);

    expect(app(Buffer::class)->pull(10)[0]['payload']['context'])->toBe(['user_id' => 5]);
});
