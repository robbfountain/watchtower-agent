<?php

declare(strict_types=1);

namespace Watchtower\Agent;

use DateTimeZone;
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
        $requests = [];
        $cacheOps = [];
        $notifications = [];

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
                'request' => $requests[] = $row['payload'],
                'cache_op' => $cacheOps[] = $row['payload'],
                'notification' => $notifications[] = $row['payload'],
                default => null,
            };
        }

        $payload = [
            'heartbeat' => [
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'agent_version' => AgentServiceProvider::version(),
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

        if ($requests !== []) {
            $payload['request_metrics'] = $this->requestMetrics($requests);
            $samples = $this->requestSamples($requests);

            if ($samples !== []) {
                $payload['request_samples'] = $samples;
            }
        }

        if ($cacheOps !== []) {
            $payload['cache_metrics'] = $this->cacheMetrics($cacheOps);
        }

        if ($notifications !== []) {
            $payload['notification_events'] = $notifications;
        }

        $databases = app(DatabaseSealer::class)->sealed();

        if ($databases !== []) {
            $payload['databases'] = $databases;
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

    /** @param array<int, array> $requests */
    private function requestMetrics(array $requests): array
    {
        $groups = [];

        foreach ($requests as $request) {
            $minute = substr($request['occurred_at'], 0, 16).':00'.substr($request['occurred_at'], 19);
            $key = "{$request['route']}|{$request['method']}|{$minute}";
            $groups[$key]['route'] = $request['route'];
            $groups[$key]['method'] = $request['method'];
            $groups[$key]['minute'] = $minute;
            $groups[$key]['durations'][] = $request['duration_ms'];
            $class = intdiv($request['status'], 100);
            $groups[$key]['classes'][$class] = ($groups[$key]['classes'][$class] ?? 0) + 1;
        }

        return array_values(array_map(function (array $group): array {
            sort($group['durations']);
            $count = count($group['durations']);

            return [
                'route' => $group['route'],
                'method' => $group['method'],
                'minute' => $group['minute'],
                'count' => $count,
                'avg_ms' => (int) round(array_sum($group['durations']) / $count),
                'p95_ms' => $group['durations'][max((int) ceil(0.95 * $count) - 1, 0)],
                'max_ms' => $group['durations'][$count - 1],
                'count_2xx' => $group['classes'][2] ?? 0,
                'count_3xx' => $group['classes'][3] ?? 0,
                'count_4xx' => $group['classes'][4] ?? 0,
                'count_5xx' => $group['classes'][5] ?? 0,
            ];
        }, $groups));
    }

    /** @param array<int, array> $requests */
    private function requestSamples(array $requests): array
    {
        $threshold = (int) config('watchtower.slow_threshold_ms', 1000);
        $samples = [];

        foreach ($requests as $request) {
            $isError = $request['status'] >= 400;
            $isSlow = $request['duration_ms'] >= $threshold;

            if (! $isError && ! $isSlow) {
                continue;
            }

            $samples[] = [
                'route' => $request['route'],
                'url' => $request['url'],
                'method' => $request['method'],
                'status' => $request['status'],
                'duration_ms' => $request['duration_ms'],
                'memory_kb' => $request['memory_kb'] ?? null,
                'type' => $isError ? 'error' : 'slow',
                'occurred_at' => $request['occurred_at'],
            ];
        }

        return $samples;
    }

    /** @param array<int, array> $ops */
    private function cacheMetrics(array $ops): array
    {
        $groups = [];

        foreach ($ops as $op) {
            $minute = substr($op['occurred_at'], 0, 16).':00'.substr($op['occurred_at'], 19);
            $key = "{$op['store']}|{$minute}";
            $groups[$key] ??= ['store' => $op['store'], 'minute' => $minute, 'hits' => 0, 'misses' => 0, 'writes' => 0, 'forgets' => 0];
            $field = match ($op['op']) {
                'hit' => 'hits', 'miss' => 'misses', 'write' => 'writes', 'forget' => 'forgets', default => null,
            };

            if ($field !== null) {
                $groups[$key][$field]++;
            }
        }

        return array_values($groups);
    }

    private function schedule(): array
    {
        try {
            return collect(app(Schedule::class)->events())->map(fn ($event) => [
                'command' => trim(str_replace([PHP_BINARY, "'artisan'", "''"], '', (string) ($event->command ?? $event->description ?? 'closure'))) ?: 'closure',
                'expression' => $event->expression,
                'timezone' => $this->eventTimezone($event->timezone),
            ])->values()->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function eventTimezone(mixed $timezone): string
    {
        if ($timezone instanceof DateTimeZone) {
            return $timezone->getName();
        }

        if (is_string($timezone) && $timezone !== '') {
            return $timezone;
        }

        return (string) config('app.timezone', 'UTC');
    }
}
