<?php

declare(strict_types=1);

use Watchtower\Agent\Buffer;
use Watchtower\Agent\ExceptionReporter;

it('records reported exceptions with a stable hash', function () {
    $reporter = app(ExceptionReporter::class);
    $exception = new RuntimeException('boom');

    $reporter->report($exception);
    $reporter->report($exception);

    $rows = app(Buffer::class)->pull(10);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['type'])->toBe('exception')
        ->and($rows[0]['payload']['class'])->toBe('RuntimeException')
        ->and($rows[0]['payload']['hash'])->toBe($rows[1]['payload']['hash'])
        ->and($rows[0]['payload']['hash'])->toHaveLength(64)
        ->and($rows[0]['payload']['trace'])->toBeArray();
});
