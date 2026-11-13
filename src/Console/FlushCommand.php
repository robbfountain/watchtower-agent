<?php

declare(strict_types=1);

namespace Watchtower\Agent\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;
use Watchtower\Agent\AgentServiceProvider;
use Watchtower\Agent\Buffer;
use Watchtower\Agent\PackageCollector;
use Watchtower\Agent\PayloadBuilder;

class FlushCommand extends Command
{
    protected $signature = 'watchtower:flush {--now : Skip the random start delay}';

    protected $description = 'Send buffered monitoring data to the Watchtower hub';

    public static function jitterSeconds(): int
    {
        $configured = (int) config('watchtower.flush.jitter_seconds', 40);

        return random_int(0, max(0, min(50, $configured)));
    }

    public function handle(Buffer $buffer, PayloadBuilder $builder, PackageCollector $packages): int
    {
        $lock = null;

        try {
            $hubUrl = (string) config('watchtower.hub_url');
            $token = (string) config('watchtower.token');

            if ($hubUrl === '' || $token === '') {
                $this->comment('Watchtower hub_url or token not configured; skipping flush.');

                return self::SUCCESS;
            }

            $lock = $this->acquireLock($buffer);

            if ($lock === false) {
                $lockPath = $buffer->path().'.lock';
                error_log("watchtower-agent: another flush is running (lock {$lockPath}); skipping.");

                return self::SUCCESS;
            }

            if (! $this->option('now')) {
                $delay = self::jitterSeconds();

                if ($delay > 0) {
                    error_log("watchtower-agent: delaying flush by {$delay}s.");
                    $this->comment("Delaying flush by {$delay}s.");
                    sleep($delay);
                }
            }

            $totalFlushed = 0;
            $anySucceeded = false;

            for ($iteration = 0; $iteration < 10; $iteration++) {
                ['rows' => $rows, 'batch_id' => $batchId, 'resend' => $isResend, 'through' => $throughId] = $this->nextBatch($buffer);
                $isHeartbeatOnly = $rows === [];

                if ($isHeartbeatOnly && $iteration > 0 && $anySucceeded) {
                    break;
                }

                $batch = $this->fitBatch($builder, $rows, ! $isResend);

                if ($batch['encoded'] === '') {
                    error_log('watchtower-agent: json_encode failed for payload; skipping flush.');

                    break;
                }

                if (! $isResend && $this->isOversizedSingle($batch)) {
                    $this->dropRow($buffer, $batch['rows'][0], $this->rowBytes($batch['rows'][0]), 'oversized');

                    continue;
                }

                if (! $isResend) {
                    $throughId = $this->recordPending($buffer, $batchId, $batch['rows']);
                }

                $response = $this->send($hubUrl, $token, $batch['encoded'], $batchId);

                while ($response->status() === 413 && count($batch['rows']) > 1) {
                    $half = intdiv(count($batch['rows']), 2);
                    error_log('watchtower-agent: hub answered 413 for '.count($batch['rows'])." rows; retrying with {$half}.");

                    $batch = $this->fitBatch($builder, array_slice($batch['rows'], 0, $half));

                    if ($batch['encoded'] === '') {
                        error_log('watchtower-agent: json_encode failed for payload; skipping flush.');

                        break 2;
                    }

                    // A halved set never reuses the rejected id: an overlapping flush may still be
                    // resending the full set under it, and a duplicate answer there would forget rows
                    // this smaller batch never carried.
                    $rejectedBatchId = $batchId;
                    $batchId = (string) Str::uuid();
                    $throughId = $this->swapPending($buffer, $rejectedBatchId, $batchId, $batch['rows']);

                    $response = $this->send($hubUrl, $token, $batch['encoded'], $batchId);
                }

                if ($response->status() === 413) {
                    $bodyBytes = strlen($batch['encoded']);

                    if ($batch['rows'] === []) {
                        error_log("watchtower-agent: hub answered 413 to a heartbeat-only payload ({$bodyBytes} bytes).");
                    } else {
                        $this->dropRow($buffer, $batch['rows'][0], $this->rowBytes($batch['rows'][0]), "rejected (413, body {$bodyBytes} bytes)");
                    }

                    $buffer->clearPendingBatch($batchId);

                    if ($batch['packages_hash'] !== null) {
                        $packages->markSent($batch['packages_hash']);
                        error_log('watchtower-agent: hub answered 413 to a batch carrying packages; not resending this package list until it changes.');
                    }

                    break;
                }

                $rows = $batch['rows'];
                $packagesHash = $batch['packages_hash'];

                if ($response->status() === 422) {
                    $buffer->forget(array_column($rows, 'id'));
                    $buffer->clearPendingBatch($batchId);
                    error_log('watchtower-agent: hub rejected batch (422): '.substr($response->body(), 0, 500));

                    if ($packagesHash !== null) {
                        $packages->markSent($packagesHash);
                        error_log('watchtower-agent: hub rejected a batch carrying packages; not resending this package list until it changes.');
                    }

                    break;
                }

                if (! $response->successful()) {
                    $this->comment("Hub rejected flush ({$response->status()}); keeping buffer.");
                    error_log("watchtower-agent: flush failed with status {$response->status()}");

                    break;
                }

                if ($response->json('duplicate') === true) {
                    $buffer->forgetThrough($throughId);
                    error_log("watchtower-agent: hub already ingested batch {$batchId}; forgot all rows up to id {$throughId}.");
                } else {
                    $buffer->forget(array_column($rows, 'id'));
                }

                $buffer->clearPendingBatch($batchId);
                $totalFlushed += count($rows);
                $anySucceeded = true;

                if ($packagesHash !== null) {
                    $packages->markSent($packagesHash);
                }

                if ($isHeartbeatOnly) {
                    break;
                }
            }

            if ($totalFlushed > 0) {
                $this->info("Flushed {$totalFlushed} buffered events.");
            }
        } catch (Throwable $throwable) {
            error_log("watchtower-agent: flush failed: {$throwable->getMessage()}");
        } finally {
            $this->releaseLock($lock);
        }

        return self::SUCCESS;
    }

    /**
     * Takes a non-blocking exclusive lock beside the buffer file so two flushes on one host never
     * overlap. Returns false when another flush holds it, null when the lock file is unusable (the
     * flush then carries on unlocked, since a broken lock must never stop it).
     *
     * @return resource|false|null
     */
    private function acquireLock(Buffer $buffer)
    {
        $path = $buffer->path().'.lock';
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            error_log("watchtower-agent: could not open flush lock {$path}; flushing without it.");

            return null;
        }

        $handle = @fopen($path, 'c');

        if ($handle === false) {
            error_log("watchtower-agent: could not open flush lock {$path}; flushing without it.");

            return null;
        }

        $wouldBlock = 0;

        if (! $this->tryLock($handle, $wouldBlock)) {
            fclose($handle);

            if ($wouldBlock === 1) {
                return false;
            }

            error_log("watchtower-agent: could not lock {$path}; flushing without it.");

            return null;
        }

        return $handle;
    }

    /** @param resource $handle */
    protected function tryLock($handle, int &$wouldBlock): bool
    {
        return flock($handle, LOCK_EX | LOCK_NB, $wouldBlock);
    }

    /** @param resource|false|null $lock */
    private function releaseLock($lock): void
    {
        if (! is_resource($lock)) {
            return;
        }

        flock($lock, LOCK_UN);
        fclose($lock);
    }

    /**
     * Halves the rows until the encoded payload fits max_batch_bytes or one row remains.
     * A heartbeat-only payload (no rows) is never split, and neither is a resend ($trim false).
     *
     * @param  array<int, array{id: int, type: string, payload: array, created_at: string}>  $rows
     * @return array{rows: array, encoded: string, packages_hash: ?string}
     */
    private function fitBatch(PayloadBuilder $builder, array $rows, bool $trim = true): array
    {
        $max = (int) config('watchtower.flush.max_batch_bytes', 1_000_000);

        while (true) {
            $payload = $builder->build($rows);
            $packagesHash = $payload['packages_hash'] ?? null;
            unset($payload['packages_hash']);

            $encoded = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);

            if ($encoded === false) {
                return ['rows' => $rows, 'encoded' => '', 'packages_hash' => $packagesHash];
            }

            if (! $trim || strlen($encoded) <= $max || count($rows) <= 1) {
                return ['rows' => $rows, 'encoded' => $encoded, 'packages_hash' => $packagesHash];
            }

            $rows = array_slice($rows, 0, intdiv(count($rows), 2));
        }
    }

    /** @param array{rows: array, encoded: string} $batch */
    private function isOversizedSingle(array $batch): bool
    {
        return count($batch['rows']) === 1
            && $this->rowBytes($batch['rows'][0]) > (int) config('watchtower.flush.max_batch_bytes', 1_000_000);
    }

    /** @param array{payload: array} $row */
    private function rowBytes(array $row): int
    {
        return strlen((string) json_encode($row['payload'], JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** @param array{id: int, type: string} $row */
    private function dropRow(Buffer $buffer, array $row, int $bytes, string $reason): void
    {
        $buffer->forget([$row['id']]);
        error_log("watchtower-agent: dropped {$reason} {$row['type']} row ({$bytes} bytes)");
    }

    /**
     * Resumes the pending batch (same id, every row up to its last row id) or starts a new one.
     *
     * @return array{rows: array<int, array{id: int, type: string, payload: array, created_at: string}>, batch_id: string, resend: bool, through: int}
     */
    private function nextBatch(Buffer $buffer): array
    {
        $pending = $buffer->pendingBatch();

        if ($pending !== null) {
            $rows = $buffer->pullThrough($pending['last_row_id'], 1000);

            if ($rows !== []) {
                error_log("watchtower-agent: resending pending batch {$pending['batch_id']} with ".count($rows).' rows.');

                return ['rows' => $rows, 'batch_id' => $pending['batch_id'], 'resend' => true, 'through' => $pending['last_row_id']];
            }

            error_log("watchtower-agent: pending batch {$pending['batch_id']} has no rows left; starting a new batch.");
            $buffer->clearPendingBatch($pending['batch_id']);
        }

        return ['rows' => $buffer->pull(1000), 'batch_id' => (string) Str::uuid(), 'resend' => false, 'through' => 0];
    }

    /**
     * Stores the record for a fresh batch and returns the last row id it covers. A heartbeat-only
     * payload has no rows and needs no record.
     *
     * @param  array<int, array{id: int}>  $rows
     */
    private function recordPending(Buffer $buffer, string $batchId, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $lastId = (int) end($rows)['id'];
        $buffer->setPendingBatch($batchId, $lastId);

        return $lastId;
    }

    /**
     * Replaces the record of a batch the hub answered with 413 by one for its halved set under a
     * new id. If another flush has already replaced or cleared that record, it is left alone.
     *
     * @param  array<int, array{id: int}>  $rows
     */
    private function swapPending(Buffer $buffer, string $rejectedBatchId, string $batchId, array $rows): int
    {
        $lastId = (int) end($rows)['id'];

        if (! $buffer->swapPendingBatch($rejectedBatchId, $batchId, $lastId)) {
            error_log("watchtower-agent: record for batch {$rejectedBatchId} was replaced by another flush; sending {$batchId} unrecorded.");

            return $lastId;
        }

        error_log("watchtower-agent: halved batch {$rejectedBatchId} after 413; sending {$batchId} through row {$lastId}.");

        return $lastId;
    }

    private function send(string $hubUrl, string $token, string $encoded, string $batchId): Response
    {
        return Http::withToken($token)
            ->withHeaders([
                'Content-Encoding' => 'gzip',
                'X-Watchtower-Agent-Version' => AgentServiceProvider::version(),
                'X-Watchtower-Batch-Id' => $batchId,
            ])
            ->withBody(gzencode($encoded) ?: '', 'application/json')
            ->timeout(10)
            ->post("{$hubUrl}/api/agent/ingest");
    }
}
