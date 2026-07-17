<?php

declare(strict_types=1);

namespace Watchtower\Agent\Listeners;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Events\Dispatcher;
use Throwable;
use Watchtower\Agent\Recorder;

class ScheduleSubscriber
{
    /** @var array<string, string> */
    private array $startedAt = [];

    public function __construct(private readonly Recorder $recorder) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(ScheduledTaskStarting::class, [$this, 'handleStarting']);
        $events->listen(ScheduledTaskFinished::class, [$this, 'handleFinished']);
        $events->listen(ScheduledTaskFailed::class, [$this, 'handleFailed']);
    }

    public function handleStarting(ScheduledTaskStarting $event): void
    {
        try {
            $this->startedAt[$this->key($event->task)] = date('c');
        } catch (Throwable) {
        }
    }

    public function handleFinished(ScheduledTaskFinished $event): void
    {
        $this->recordRun($event->task, 'finished');
    }

    public function handleFailed(ScheduledTaskFailed $event): void
    {
        $this->recordRun($event->task, 'failed');
    }

    private function recordRun(ScheduledEvent $task, string $status): void
    {
        try {
            $key = $this->key($task);
            $startedAt = $this->startedAt[$key] ?? date('c');
            unset($this->startedAt[$key]);

            $this->recorder->record('task_run', [
                'command' => $this->commandName($task),
                'status' => $status,
                'started_at' => $startedAt,
                'finished_at' => date('c'),
                'exit_code' => $task->exitCode,
            ]);
        } catch (Throwable $throwable) {
            error_log("watchtower-agent: schedule listener failed: {$throwable->getMessage()}");
        }
    }

    private function key(ScheduledEvent $task): string
    {
        return spl_object_hash($task);
    }

    private function commandName(ScheduledEvent $task): string
    {
        $command = $task->command ?? $task->description ?? 'closure';

        return trim(str_replace([PHP_BINARY, "'artisan'", "''"], '', (string) $command)) ?: 'closure';
    }
}
