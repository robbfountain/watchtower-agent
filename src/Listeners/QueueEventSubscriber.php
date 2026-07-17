<?php

declare(strict_types=1);

namespace Watchtower\Agent\Listeners;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Throwable;
use Watchtower\Agent\Recorder;

class QueueEventSubscriber
{
    /** @var array<string, float> */
    private array $startedAt = [];

    public function __construct(private readonly Recorder $recorder) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(JobProcessing::class, [$this, 'handleProcessing']);
        $events->listen(JobProcessed::class, [$this, 'handleProcessed']);
        $events->listen(JobFailed::class, [$this, 'handleFailed']);
    }

    public function handleProcessing(JobProcessing $event): void
    {
        try {
            $this->startedAt[$event->job->uuid() ?? spl_object_hash($event->job)] = microtime(true);
        } catch (Throwable) {
        }
    }

    public function handleProcessed(JobProcessed $event): void
    {
        $this->recordEvent($event->job, 'completed', null);
    }

    public function handleFailed(JobFailed $event): void
    {
        $exceptionClass = $event->exception::class;
        $this->recordEvent($event->job, 'failed', "{$exceptionClass}: {$event->exception->getMessage()}");
    }

    private function recordEvent(Job $job, string $status, ?string $exception): void
    {
        try {
            $key = $job->uuid() ?? spl_object_hash($job);
            $started = $this->startedAt[$key] ?? null;
            unset($this->startedAt[$key]);

            $this->recorder->record('job_event', [
                'queue' => mb_substr($job->getQueue() ?? 'default', 0, 255),
                'job_class' => mb_substr($job->resolveName(), 0, 500),
                'status' => $status,
                'runtime_ms' => $started !== null ? (int) round((microtime(true) - $started) * 1000) : null,
                'exception' => $exception !== null ? mb_substr($exception, 0, 65000) : null,
                'occurred_at' => date('c'),
            ]);
        } catch (Throwable $throwable) {
            error_log("watchtower-agent: queue listener failed: {$throwable->getMessage()}");
        }
    }
}
