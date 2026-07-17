<?php

declare(strict_types=1);

namespace Watchtower\Agent;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Throwable;

class PayloadBuilder
{
    /** @param array<int, array{id: int, type: string, payload: array, created_at: string}> $rows */
    public function build(array $rows): array
    {
        $sections = ['job_events' => [], 'log_entries' => [], 'task_runs' => []];
        $exceptionGroups = [];

        foreach ($rows as $row) {
            if ($row['payload'] === []) {
                continue;
            }

            match ($row['type']) {
                'job_event' => $sections['job_events'][] = $row['payload'],
                'log_entry' => $sections['log_entries'][] = $row['payload'],
                'task_run' => $sections['task_runs'][] = $row['payload'],
                'exception' => isset($row['payload']['hash'])
                    ? $exceptionGroups[$row['payload']['hash']][] = $row['payload']
                    : null,
                default => null,
            };
        }

        $payload = [
            'heartbeat' => [
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'agent_version' => AgentServiceProvider::VERSION,
            ],
            'queue_snapshots' => $this->queueSnapshots(),
            'schedule' => $this->schedule(),
        ];

        foreach ($sections as $key => $items) {
            if ($items !== []) {
                $payload[$key] = $items;
            }
        }

        if ($exceptionGroups !== []) {
            $payload['exceptions'] = array_values(array_map(function (array $group): array {
                $occurredAts = array_column($group, 'occurred_at');
                $first = $group[0];

                return [
                    'hash' => $first['hash'],
                    'class' => $first['class'],
                    'message' => $first['message'],
                    'file' => $first['file'],
                    'line' => $first['line'],
                    'trace' => $first['trace'],
                    'count' => count($group),
                    'first_seen_at' => min($occurredAts),
                    'last_seen_at' => max($occurredAts),
                ];
            }, $exceptionGroups));
        }

        return $payload;
    }

    private function queueSnapshots(): array
    {
        $snapshots = [];

        foreach ((array) config('watchtower.queues', ['default']) as $queue) {
            try {
                $snapshots[] = [
                    'queue' => $queue,
                    'pending_count' => Queue::size($queue),
                    'captured_at' => date('c'),
                ];
            } catch (Throwable) {
                // Queue driver unavailable; skip the snapshot.
            }
        }

        return $snapshots;
    }

    private function schedule(): array
    {
        try {
            return collect(app(Schedule::class)->events())->map(fn ($event) => [
                'command' => trim(str_replace([PHP_BINARY, "'artisan'", "''"], '', (string) ($event->command ?? $event->description ?? 'closure'))) ?: 'closure',
                'expression' => $event->expression,
            ])->values()->all();
        } catch (Throwable) {
            return [];
        }
    }
}
