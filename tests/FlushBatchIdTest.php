<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Watchtower\Agent\Buffer;

function batchIdPushRows(int $count, int $from = 1): void
{
    for ($i = $from; $i < $from + $count; $i++) {
        app(Buffer::class)->push('log_entry', ['level' => 'warning', 'message' => "row {$i}", 'context' => null, 'logged_at' => '2026-07-17T12:00:00+00:00']);
    }
}

function batchIdRequests(): array
{
    $requests = [];

    foreach (Http::recorded() as [$request]) {
        $body = json_decode((string) gzdecode($request->body()), true);
        $requests[] = [
            'id' => $request->header('X-Watchtower-Batch-Id')[0] ?? null,
            'messages' => array_column($body['log_entries'] ?? [], 'message'),
        ];
    }

    return $requests;
}

it('sends a uuid batch id header', function () {
    Http::fake(['hub.test/*' => Http::response(['accepted' => true], 202)]);
    batchIdPushRows(2);

    $this->artisan('watchtower:flush')->assertSuccessful();

    Http::assertSent(fn ($request) => Str::isUuid($request->header('X-Watchtower-Batch-Id')[0] ?? ''));
});

it('resends the same rows with the same batch id after a timeout', function () {
    Http::fake([
        'hub.test/*' => Http::sequence()
            ->pushFailedConnection()
            ->push(['accepted' => true], 202)
            ->push(['accepted' => true], 202),
    ]);

    batchIdPushRows(3);
    $this->artisan('watchtower:flush')->assertSuccessful();
    batchIdPushRows(2, 4);
    $this->artisan('watchtower:flush')->assertSuccessful();

    $requests = batchIdRequests();

    expect($requests)->toHaveCount(3)
        ->and($requests[1]['id'])->toBe($requests[0]['id'])
        ->and($requests[1]['messages'])->toBe(['row 1', 'row 2', 'row 3'])
        ->and($requests[2]['id'])->not->toBe($requests[0]['id'])
        ->and(Str::isUuid($requests[2]['id']))->toBeTrue()
        ->and($requests[2]['messages'])->toBe(['row 4', 'row 5'])
        ->and(app(Buffer::class)->count())->toBe(0);
});

it('keeps the batch id after a 500 and clears it after success', function () {
    Http::fake([
        'hub.test/*' => Http::sequence()
            ->push(['message' => 'error'], 500)
            ->push(['accepted' => true], 202),
    ]);
    batchIdPushRows(2);

    $this->artisan('watchtower:flush')->assertSuccessful();

    expect(app(Buffer::class)->pendingBatch())->toBeArray();

    $this->artisan('watchtower:flush')->assertSuccessful();

    $requests = batchIdRequests();

    expect($requests[1]['id'])->toBe($requests[0]['id'])
        ->and(app(Buffer::class)->pendingBatch())->toBeNull();
});

it('clears the pending batch after a 422', function () {
    Http::fake(['hub.test/*' => Http::response(['errors' => ['payload' => ['bad']]], 422)]);
    batchIdPushRows(2);

    $this->artisan('watchtower:flush')->assertSuccessful();

    expect(app(Buffer::class)->pendingBatch())->toBeNull()
        ->and(app(Buffer::class)->count())->toBe(0);
});

it('clears the pending batch after a terminal 413', function () {
    Http::fake(['hub.test/*' => Http::response(['message' => 'Payload too large.'], 413)]);
    batchIdPushRows(1);

    $this->artisan('watchtower:flush')->assertSuccessful();

    expect(app(Buffer::class)->pendingBatch())->toBeNull();
});

it('gives a halved sub-batch a fresh batch id and pins the record to it', function () {
    Http::fake([
        'hub.test/*' => Http::sequence()
            ->push(['message' => 'Payload too large.'], 413)
            ->push(['message' => 'error'], 500),
    ]);
    batchIdPushRows(4);

    $this->artisan('watchtower:flush')->assertSuccessful();

    $requests = batchIdRequests();

    expect($requests)->toHaveCount(2)
        ->and($requests[1]['id'])->not->toBe($requests[0]['id'])
        ->and(Str::isUuid($requests[1]['id']))->toBeTrue()
        ->and($requests[1]['messages'])->toBe(['row 1', 'row 2'])
        ->and(app(Buffer::class)->pendingBatch())->toBe(['batch_id' => $requests[1]['id'], 'last_row_id' => 2]);
});

it('never lets the hub accept an id it answered with 413', function () {
    $claimed = [];
    $rejected = [];

    Http::fake(function ($request) use (&$claimed, &$rejected) {
        $id = $request->header('X-Watchtower-Batch-Id')[0];
        $rows = count(json_decode((string) gzdecode($request->body()), true)['log_entries'] ?? []);

        if (isset($claimed[$id])) {
            return Http::response(['accepted' => true, 'duplicate' => true], 202);
        }

        if ($rows > 2) {
            $rejected[$id] = true;

            return Http::response(['message' => 'Payload too large.'], 413);
        }

        $claimed[$id] = true;

        return Http::response(['accepted' => true], 202);
    });
    batchIdPushRows(8);

    $this->artisan('watchtower:flush')->assertSuccessful();

    expect(array_intersect_key($claimed, $rejected))->toBe([])
        ->and(app(Buffer::class)->count())->toBe(0);
});

it('swaps the pending batch only when the expected id still matches', function () {
    $buffer = app(Buffer::class);
    $buffer->setPendingBatch('88888888-8888-4888-8888-888888888888', 9);

    $buffer->swapPendingBatch('99999999-9999-4999-8999-999999999999', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 4);

    expect($buffer->pendingBatch())->toBe(['batch_id' => '88888888-8888-4888-8888-888888888888', 'last_row_id' => 9]);

    $buffer->swapPendingBatch('88888888-8888-4888-8888-888888888888', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 4);

    expect($buffer->pendingBatch())->toBe(['batch_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'last_row_id' => 4]);
});

it('keeps independent pending records for buffers on different paths', function () {
    $first = new Buffer(__DIR__.'/tmp/batch-id-a.sqlite', 100);
    $second = new Buffer(__DIR__.'/tmp/batch-id-b.sqlite', 100);

    $first->setPendingBatch('11111111-1111-4111-8111-111111111111', 7);

    expect($second->pendingBatch())->toBeNull()
        ->and($first->pendingBatch())->toBe(['batch_id' => '11111111-1111-4111-8111-111111111111', 'last_row_id' => 7]);

    $first->clearPendingBatch('11111111-1111-4111-8111-111111111111');

    expect($first->pendingBatch())->toBeNull();

    foreach (['a', 'b'] as $name) {
        @unlink(__DIR__."/tmp/batch-id-{$name}.sqlite");
        @unlink(__DIR__."/tmp/batch-id-{$name}.sqlite-wal");
        @unlink(__DIR__."/tmp/batch-id-{$name}.sqlite-shm");
    }
});

it('never sees a pending record from another buffer file', function () {
    app(Buffer::class)->setPendingBatch('22222222-2222-4222-8222-222222222222', 99);
    @unlink(__DIR__.'/tmp/buffer.sqlite');
    @unlink(__DIR__.'/tmp/buffer.sqlite-wal');
    @unlink(__DIR__.'/tmp/buffer.sqlite-shm');

    $fresh = new Buffer(__DIR__.'/tmp/buffer.sqlite', 100);

    expect($fresh->pendingBatch())->toBeNull();
});

it('sends the remaining subset under the same id after a cap deletion', function () {
    config(['watchtower.buffer.max_rows' => 3]);
    app()->forgetInstance(Buffer::class);

    Http::fake([
        'hub.test/*' => Http::sequence()
            ->push(['message' => 'error'], 500)
            ->push(['accepted' => true], 202),
    ]);
    batchIdPushRows(3);

    $this->artisan('watchtower:flush')->assertSuccessful();

    batchIdPushRows(1, 4);

    $this->artisan('watchtower:flush')->assertSuccessful();

    $requests = batchIdRequests();

    expect($requests[1]['id'])->toBe($requests[0]['id'])
        ->and($requests[1]['messages'])->toBe(['row 2', 'row 3']);
});

it('clears a record whose rows are all gone and uses a fresh id', function () {
    Http::fake(['hub.test/*' => Http::response(['accepted' => true], 202)]);
    $buffer = app(Buffer::class);
    $buffer->setPendingBatch('33333333-3333-4333-8333-333333333333', 1);
    batchIdPushRows(1);
    $buffer->forgetThrough(1);
    batchIdPushRows(1, 2);

    $this->artisan('watchtower:flush')->assertSuccessful();

    $requests = batchIdRequests();

    expect($requests[0]['id'])->not->toBe('33333333-3333-4333-8333-333333333333')
        ->and(Str::isUuid($requests[0]['id']))->toBeTrue()
        ->and($buffer->pendingBatch())->toBeNull();
});

it('writes no pending record for a heartbeat-only flush', function () {
    Http::fake(['hub.test/*' => Http::response(['message' => 'error'], 500)]);

    $this->artisan('watchtower:flush')->assertSuccessful();

    expect(app(Buffer::class)->pendingBatch())->toBeNull();
});

it('sends a resend over the byte limit untrimmed under the same id', function () {
    config(['watchtower.flush.max_batch_bytes' => 100000]);
    Http::fake([
        'hub.test/*' => Http::sequence()
            ->push(['message' => 'error'], 500)
            ->push(['accepted' => true], 202),
    ]);
    batchIdPushRows(6);

    $this->artisan('watchtower:flush')->assertSuccessful();

    config(['watchtower.flush.max_batch_bytes' => 300]);

    $this->artisan('watchtower:flush')->assertSuccessful();

    $requests = batchIdRequests();

    expect($requests[1]['id'])->toBe($requests[0]['id'])
        ->and($requests[1]['messages'])->toHaveCount(6)
        ->and(app(Buffer::class)->count())->toBe(0);
});

it('forgets every row up to the record when a resend is answered as a duplicate', function () {
    Http::fake(['hub.test/*' => Http::sequence()
        ->push(['accepted' => true, 'duplicate' => true], 202)
        ->push(['accepted' => true], 202)]);
    batchIdPushRows(7);
    $buffer = app(Buffer::class);
    $buffer->setPendingBatch('44444444-4444-4444-8444-444444444444', 5);
    config(['watchtower.flush.max_batch_bytes' => 100000]);

    $this->artisan('watchtower:flush')->assertSuccessful();

    $requests = batchIdRequests();

    expect($requests[0]['id'])->toBe('44444444-4444-4444-8444-444444444444')
        ->and($requests[0]['messages'])->toHaveCount(5)
        ->and($requests[1]['messages'])->toBe(['row 6', 'row 7'])
        ->and($requests[1]['id'])->not->toBe($requests[0]['id'])
        ->and($buffer->count())->toBe(0)
        ->and($buffer->pendingBatch())->toBeNull();
});

it('does not forget or clear a newer record when an overlapping flush gets a duplicate', function () {
    $buffer = app(Buffer::class);
    batchIdPushRows(5);
    $buffer->setPendingBatch('66666666-6666-4666-8666-666666666666', 3);
    $calls = 0;

    Http::fake(function () use (&$calls) {
        $calls++;

        if ($calls > 1) {
            return Http::response(['message' => 'error'], 500);
        }

        app(Buffer::class)->setPendingBatch('77777777-7777-4777-8777-777777777777', 5);

        return Http::response(['accepted' => true, 'duplicate' => true], 202);
    });

    $this->artisan('watchtower:flush')->assertSuccessful();

    $requests = batchIdRequests();

    expect($requests[0]['id'])->toBe('66666666-6666-4666-8666-666666666666')
        ->and($requests[0]['messages'])->toBe(['row 1', 'row 2', 'row 3'])
        ->and($requests[1]['id'])->toBe('77777777-7777-4777-8777-777777777777')
        ->and($requests[1]['messages'])->toBe(['row 4', 'row 5'])
        ->and($buffer->count())->toBe(2)
        ->and($buffer->pendingBatch())->toBe(['batch_id' => '77777777-7777-4777-8777-777777777777', 'last_row_id' => 5]);
});

it('halves a resend answered with 413 under a fresh id and sends the rest under another', function () {
    Http::fake([
        'hub.test/*' => Http::sequence()
            ->push(['message' => 'error'], 500)
            ->push(['message' => 'Payload too large.'], 413)
            ->push(['accepted' => true], 202)
            ->push(['accepted' => true], 202),
    ]);
    batchIdPushRows(4);

    $this->artisan('watchtower:flush')->assertSuccessful();
    $this->artisan('watchtower:flush')->assertSuccessful();

    $requests = batchIdRequests();

    expect($requests)->toHaveCount(4)
        ->and($requests[1]['id'])->toBe($requests[0]['id'])
        ->and($requests[1]['messages'])->toHaveCount(4)
        ->and($requests[2]['id'])->not->toBe($requests[0]['id'])
        ->and(Str::isUuid($requests[2]['id']))->toBeTrue()
        ->and($requests[2]['messages'])->toBe(['row 1', 'row 2'])
        ->and($requests[3]['id'])->not->toBe($requests[0]['id'])
        ->and($requests[3]['id'])->not->toBe($requests[2]['id'])
        ->and(Str::isUuid($requests[3]['id']))->toBeTrue()
        ->and($requests[3]['messages'])->toBe(['row 3', 'row 4'])
        ->and(app(Buffer::class)->count())->toBe(0)
        ->and(app(Buffer::class)->pendingBatch())->toBeNull();
});

it('flushes a buffer file created before the meta table existed', function () {
    $pdo = new PDO('sqlite:'.__DIR__.'/tmp/buffer.sqlite');
    $pdo->exec('CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT NOT NULL,
            payload TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');

    foreach ([1, 2, 3] as $n) {
        $pdo->prepare('INSERT INTO events (type, payload, created_at) VALUES (?, ?, ?)')->execute([
            'log_entry',
            json_encode(['level' => 'warning', 'message' => "row {$n}", 'context' => null, 'logged_at' => '2026-07-17T12:00:00+00:00']),
            date('c'),
        ]);
    }

    $pdo = null;
    Http::fake(['hub.test/*' => Http::response(['accepted' => true], 202)]);

    $this->artisan('watchtower:flush')->assertSuccessful();

    $requests = batchIdRequests();

    expect($requests[0]['messages'])->toBe(['row 1', 'row 2', 'row 3'])
        ->and(Str::isUuid($requests[0]['id']))->toBeTrue()
        ->and(app(Buffer::class)->count())->toBe(0)
        ->and(app(Buffer::class)->pendingBatch())->toBeNull();
});
