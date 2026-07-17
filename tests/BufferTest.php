<?php

declare(strict_types=1);

use Watchtower\Agent\Buffer;
use Watchtower\Agent\Recorder;

function makeBuffer(int $maxRows = 100): Buffer
{
    return new Buffer(__DIR__.'/tmp/buffer.sqlite', $maxRows);
}

it('round-trips pushed rows', function () {
    $buffer = makeBuffer();
    $buffer->push('job_event', ['queue' => 'default']);
    $buffer->push('log_entry', ['level' => 'warning']);

    $rows = $buffer->pull(10);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['type'])->toBe('job_event')
        ->and($rows[0]['payload'])->toBe(['queue' => 'default'])
        ->and($buffer->count())->toBe(2);
});

it('forgets rows by id', function () {
    $buffer = makeBuffer();
    $buffer->push('job_event', ['n' => 1]);
    $buffer->push('job_event', ['n' => 2]);

    $rows = $buffer->pull(10);
    $buffer->forget([$rows[0]['id']]);

    expect($buffer->count())->toBe(1)
        ->and($buffer->pull(10)[0]['payload'])->toBe(['n' => 2]);
});

it('drops the oldest rows beyond the cap', function () {
    $buffer = makeBuffer(maxRows: 3);

    foreach (range(1, 5) as $n) {
        $buffer->push('job_event', ['n' => $n]);
    }

    $payloads = array_column($buffer->pull(10), 'payload');

    expect($buffer->count())->toBe(3)
        ->and($payloads)->toBe([['n' => 3], ['n' => 4], ['n' => 5]]);
});

it('recorder swallows buffer failures', function () {
    $buffer = new Buffer('/nonexistent-dir/nope/buffer.sqlite', 10);
    $recorder = new Recorder($buffer);

    $recorder->record('job_event', ['n' => 1]);

    expect(true)->toBeTrue();
});

it('container resolves the buffer as a singleton with config values', function () {
    expect(app(Buffer::class))->toBe(app(Buffer::class))
        ->and(app(Recorder::class))->toBeInstanceOf(Recorder::class);
});
