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

        return array_map(fn (array $row) => [
            'id' => (int) $row['id'],
            'type' => $row['type'],
            'payload' => json_decode($row['payload'], true) ?? [],
            'created_at' => $row['created_at'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
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

        return $this->pdo;
    }
}
