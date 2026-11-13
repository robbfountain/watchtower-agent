<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Watchtower\Agent\Buffer;
use Watchtower\Agent\Console\FlushCommand;

function jitterLockPath(): string
{
    return app(Buffer::class)->path().'.lock';
}

function jitterHoldLock()
{
    @mkdir(dirname(jitterLockPath()), 0777, true);
    $handle = fopen(jitterLockPath(), 'c');
    flock($handle, LOCK_EX | LOCK_NB);

    return $handle;
}

function jitterCaptureErrorLog(): string
{
    $file = tempnam(sys_get_temp_dir(), 'wt-log');
    $GLOBALS['jitterErrorLogRestore'] ??= ['previous' => (string) ini_get('error_log'), 'files' => []];
    $GLOBALS['jitterErrorLogRestore']['files'][] = $file;
    ini_set('error_log', $file);

    return $file;
}

afterEach(function () {
    if (! isset($GLOBALS['jitterErrorLogRestore'])) {
        return;
    }

    ini_set('error_log', $GLOBALS['jitterErrorLogRestore']['previous']);

    foreach ($GLOBALS['jitterErrorLogRestore']['files'] as $file) {
        @unlink($file);
    }

    unset($GLOBALS['jitterErrorLogRestore']);
});

class JitterFailingLockFlushCommand extends FlushCommand
{
    protected function tryLock($handle, int &$wouldBlock): bool
    {
        $wouldBlock = 0;

        return false;
    }
}

it('clamps the jitter to fifty seconds', function () {
    config(['watchtower.flush.jitter_seconds' => 500]);

    $values = array_map(fn () => FlushCommand::jitterSeconds(), range(1, 200));

    expect(min($values))->toBeGreaterThanOrEqual(0)
        ->and(max($values))->toBeLessThanOrEqual(50);
});

it('disables the jitter at zero', function () {
    config(['watchtower.flush.jitter_seconds' => 0]);

    $values = array_map(fn () => FlushCommand::jitterSeconds(), range(1, 50));

    expect(array_unique($values))->toBe([0]);
});

it('never returns a negative jitter', function () {
    config(['watchtower.flush.jitter_seconds' => -5]);

    expect(FlushCommand::jitterSeconds())->toBe(0);
});

it('skips the delay with --now', function () {
    config(['watchtower.flush.jitter_seconds' => 40]);
    Http::fake(['hub.test/*' => Http::response(['accepted' => true], 202)]);
    app(Buffer::class)->push('log_entry', ['level' => 'warning', 'message' => 'w', 'context' => null, 'logged_at' => '2026-07-17T12:00:00+00:00']);

    $started = microtime(true);
    $this->artisan('watchtower:flush --now')->assertSuccessful();

    expect(microtime(true) - $started)->toBeLessThan(5)
        ->and(app(Buffer::class)->count())->toBe(0);
    Http::assertSentCount(1);
});

it('sends nothing while another flush holds the host lock', function () {
    Http::fake(['hub.test/*' => Http::response(['accepted' => true], 202)]);
    app(Buffer::class)->push('log_entry', ['level' => 'warning', 'message' => 'w', 'context' => null, 'logged_at' => '2026-07-17T12:00:00+00:00']);
    $log = jitterCaptureErrorLog();
    $handle = jitterHoldLock();

    $this->artisan('watchtower:flush')->assertSuccessful();

    Http::assertNothingSent();
    expect(app(Buffer::class)->count())->toBe(1)
        ->and(file_get_contents($log))->toContain('another flush is running (lock');

    flock($handle, LOCK_UN);
    fclose($handle);

    $this->artisan('watchtower:flush')->assertSuccessful();

    Http::assertSentCount(1);
    expect(app(Buffer::class)->count())->toBe(0);
});

it('releases the host lock after a flush', function () {
    Http::fake(['hub.test/*' => Http::response(['accepted' => true], 202)]);

    $this->artisan('watchtower:flush')->assertSuccessful();

    $handle = fopen(jitterLockPath(), 'c');

    expect(flock($handle, LOCK_EX | LOCK_NB))->toBeTrue();
    fclose($handle);
});

it('still flushes when the lock file cannot be opened', function () {
    Http::fake(['hub.test/*' => Http::response(['accepted' => true], 202)]);
    app(Buffer::class)->push('log_entry', ['level' => 'warning', 'message' => 'w', 'context' => null, 'logged_at' => '2026-07-17T12:00:00+00:00']);
    @unlink(jitterLockPath());
    mkdir(jitterLockPath());
    $log = jitterCaptureErrorLog();

    $this->artisan('watchtower:flush')->assertSuccessful();

    Http::assertSentCount(1);
    expect(file_get_contents($log))->toContain('could not open flush lock');
    rmdir(jitterLockPath());
});

it('logs when the pending record was replaced by another flush during a halving', function () {
    $buffer = app(Buffer::class);

    foreach (range(1, 4) as $number) {
        $buffer->push('log_entry', ['level' => 'warning', 'message' => "row {$number}", 'context' => null, 'logged_at' => '2026-07-17T12:00:00+00:00']);
    }

    $calls = 0;
    Http::fake(function () use (&$calls, $buffer) {
        $calls++;

        if ($calls === 1) {
            $buffer->setPendingBatch('88888888-8888-4888-8888-888888888888', 4);

            return Http::response(['message' => 'Payload too large.'], 413);
        }

        return Http::response(['accepted' => true], 202);
    });
    $log = jitterCaptureErrorLog();

    $this->artisan('watchtower:flush')->assertSuccessful();

    expect(file_get_contents($log))->toContain('was replaced by another flush; sending');
});

it('still flushes when the lock call itself fails', function () {
    Http::fake(['hub.test/*' => Http::response(['accepted' => true], 202)]);
    app(Buffer::class)->push('log_entry', ['level' => 'warning', 'message' => 'w', 'context' => null, 'logged_at' => '2026-07-17T12:00:00+00:00']);
    $log = jitterCaptureErrorLog();
    app(\Illuminate\Contracts\Console\Kernel::class)->registerCommand(tap(new JitterFailingLockFlushCommand, fn ($command) => $command->setLaravel(app())));

    $this->artisan('watchtower:flush')->assertSuccessful();

    Http::assertSentCount(1);
    expect(file_get_contents($log))->toContain('could not lock')->not->toContain('another flush is running');
});

it('logs the delay when the jitter is above zero', function () {
    config(['watchtower.flush.jitter_seconds' => 1]);
    Http::fake(['hub.test/*' => Http::response(['accepted' => true], 202)]);
    $log = jitterCaptureErrorLog();

    foreach (range(1, 30) as $attempt) {
        $this->artisan('watchtower:flush')->assertSuccessful();

        if (str_contains((string) file_get_contents($log), 'delaying flush by')) {
            break;
        }
    }

    expect(file_get_contents($log))->toContain('delaying flush by 1s.');
});
