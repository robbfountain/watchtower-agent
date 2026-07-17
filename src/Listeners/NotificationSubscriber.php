<?php

declare(strict_types=1);

namespace Watchtower\Agent\Listeners;

use Illuminate\Events\Dispatcher;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Throwable;
use Watchtower\Agent\Recorder;

class NotificationSubscriber
{
    public function __construct(private readonly Recorder $recorder) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(NotificationSent::class, [$this, 'handleSent']);
        $events->listen(NotificationFailed::class, [$this, 'handleFailed']);
    }

    public function handleSent(NotificationSent $event): void
    {
        try {
            $this->recorder->record('notification', [
                'channel' => $event->channel,
                'notification' => mb_substr($event->notification::class, 0, 500),
                'notifiable_type' => $event->notifiable !== null ? $event->notifiable::class : null,
                'status' => 'sent',
                'error' => null,
                'occurred_at' => date('c'),
            ]);
        } catch (Throwable $throwable) {
            error_log("watchtower-agent: notification listener failed: {$throwable->getMessage()}");
        }
    }

    public function handleFailed(NotificationFailed $event): void
    {
        try {
            $error = $this->resolveError($event->data);

            $this->recorder->record('notification', [
                'channel' => $event->channel,
                'notification' => mb_substr($event->notification::class, 0, 500),
                'notifiable_type' => $event->notifiable !== null ? $event->notifiable::class : null,
                'status' => 'failed',
                'error' => $error !== null ? mb_substr($error, 0, 65000) : null,
                'occurred_at' => date('c'),
            ]);
        } catch (Throwable $throwable) {
            error_log("watchtower-agent: notification listener failed: {$throwable->getMessage()}");
        }
    }

    /** @param array<string, mixed> $data */
    private function resolveError(array $data): ?string
    {
        $exception = $data['exception'] ?? null;

        if ($exception instanceof Throwable) {
            return $exception->getMessage();
        }

        if (is_string($exception)) {
            return $exception;
        }

        return null;
    }
}
