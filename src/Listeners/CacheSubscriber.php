<?php

declare(strict_types=1);

namespace Watchtower\Agent\Listeners;

use Illuminate\Cache\Events\CacheEvent;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Events\Dispatcher;
use Throwable;
use Watchtower\Agent\PackageCollector;
use Watchtower\Agent\Recorder;

class CacheSubscriber
{
    public function __construct(private readonly Recorder $recorder) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(CacheHit::class, [$this, 'handleHit']);
        $events->listen(CacheMissed::class, [$this, 'handleMiss']);
        $events->listen(KeyWritten::class, [$this, 'handleWrite']);
        $events->listen(KeyForgotten::class, [$this, 'handleForget']);
    }

    public function handleHit(CacheHit $event): void
    {
        $this->record($event, 'hit');
    }

    public function handleMiss(CacheMissed $event): void
    {
        $this->record($event, 'miss');
    }

    public function handleWrite(KeyWritten $event): void
    {
        $this->record($event, 'write');
    }

    public function handleForget(KeyForgotten $event): void
    {
        $this->record($event, 'forget');
    }

    private function record(CacheEvent $event, string $op): void
    {
        if ($event->key === PackageCollector::CACHE_KEY) {
            return;
        }

        try {
            $this->recorder->record('cache_op', [
                'store' => mb_substr($event->storeName ?? 'default', 0, 100),
                'op' => $op,
                'occurred_at' => date('c'),
            ]);
        } catch (Throwable $throwable) {
            error_log("watchtower-agent: cache listener failed: {$throwable->getMessage()}");
        }
    }
}
