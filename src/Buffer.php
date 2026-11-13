<?php

declare(strict_types=1);

namespace Watchtower\Agent;

use PDO;

class Buffer
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly string $path,
        private readonly int $maxRows,
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    public function push(string $type, array $payload): void
    {
        $statement = $this->pdo()->prepare(
            'INSERT INTO events (type, payload, created_at) VALUES (?, ?, ?)'
        );
        $statement->execute([$type, json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE), date('c')]);

        $this->enforceCap();
    }

    /** @return array<int, array{id: int, type: string, payload: array, created_at: string}> */
    public function pull(int $limit): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT id, type, payload, created_at FROM events ORDER BY id ASC LIMIT ?'
        );
        $statement->execute([$limit]);

        return $this->hydrate($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array<int, array{id: int, type: string, payload: array, created_at: string}>
     */
    private function hydrate(array $records): array
    {
        return array_map(fn (array $row) => [
            'id' => (int) $row['id'],
            'type' => $row['type'],
            'payload' => json_decode($row['payload'], true) ?? [],
            'created_at' => $row['created_at'],
        ], $records);
    }

    /** @return array<int, array{id: int, type: string, payload: array, created_at: string}> */
    public function pullThrough(int $lastId, int $limit): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT id, type, payload, created_at FROM events WHERE id <= ? ORDER BY id ASC LIMIT ?'
        );
        $statement->execute([$lastId, $limit]);

        return $this->hydrate($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function forgetThrough(int $lastId): void
    {
        $this->pdo()->prepare('DELETE FROM events WHERE id <= ?')->execute([$lastId]);
    }

    /** @return array{batch_id: string, last_row_id: int}|null */
    public function pendingBatch(): ?array
    {
        $row = $this->pdo()->query('SELECT batch_id, last_row_id FROM meta WHERE id = 1')->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return ['batch_id' => (string) $row['batch_id'], 'last_row_id' => (int) $row['last_row_id']];
    }

    public function setPendingBatch(string $batchId, int $lastRowId): void
    {
        $this->pdo()->prepare(
            'INSERT OR REPLACE INTO meta (id, batch_id, last_row_id) VALUES (1, ?, ?)'
        )->execute([$batchId, $lastRowId]);
    }

    public function swapPendingBatch(string $expectedBatchId, string $newBatchId, int $lastRowId): bool
    {
        $statement = $this->pdo()->prepare(
            'UPDATE meta SET batch_id = ?, last_row_id = ? WHERE id = 1 AND batch_id = ?'
        );
        $statement->execute([$newBatchId, $lastRowId, $expectedBatchId]);

        return $statement->rowCount() === 1;
    }

    public function clearPendingBatch(string $batchId): void
    {
        $this->pdo()->prepare('DELETE FROM meta WHERE id = 1 AND batch_id = ?')->execute([$batchId]);
    }

    /** @param array<int, int> $ids */
    public function forget(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->pdo()->prepare("DELETE FROM events WHERE id IN ({$placeholders})")->execute($ids);
    }

    public function count(): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM events')->fetchColumn();
    }

    private function enforceCap(): void
    {
        $overflow = $this->count() - $this->maxRows;

        if ($overflow <= 0) {
            return;
        }

        $this->pdo()->prepare(
            'DELETE FROM events WHERE id IN (SELECT id FROM events ORDER BY id ASC LIMIT ?)'
        )->execute([$overflow]);
    }

    private function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $directory = dirname($this->path);

        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        $this->pdo = new PDO("sqlite:{$this->path}");
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA busy_timeout = 2000');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT NOT NULL,
            payload TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS meta (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            batch_id TEXT NOT NULL,
            last_row_id INTEGER NOT NULL
        )');

        return $this->pdo;
    }
}
