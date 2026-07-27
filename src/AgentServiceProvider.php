<?php

declare(strict_types=1);

namespace Watchtower\Agent;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Monolog\Level;
use Throwable;
use Watchtower\Agent\Console\FlushCommand;
use Watchtower\Agent\Console\TestExceptionCommand;
use Watchtower\Agent\Listeners\CacheSubscriber;
use Watchtower\Agent\Listeners\NotificationSubscriber;
use Watchtower\Agent\Listeners\QueueEventSubscriber;
use Watchtower\Agent\Listeners\ScheduleSubscriber;
use Watchtower\Agent\Logging\BufferLogHandler;

class AgentServiceProvider extends ServiceProvider
{
    public const VERSION = '0.3.0';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/watchtower.php', 'watchtower');

        $this->app->singleton(Buffer::class, function ($app): Buffer {
            $path = $app['config']->get('watchtower.buffer.path')
                ?? $app->storagePath('watchtower/buffer.sqlite');

            return new Buffer($path, (int) $app['config']->get('watchtower.buffer.max_rows', 10000));
        });

        $this->app->singleton(Recorder::class);
        $this->app->singleton(ExceptionReporter::class);
        $this->app->singleton(PayloadBuilder::class);
        $this->app->singleton(DatabaseSealer::class);
        $this->app->singleton(QueueEventSubscriber::class);
        $this->app->singleton(ScheduleSubscriber::class);
        $this->app->singleton(CacheSubscriber::class);
        $this->app->singleton(NotificationSubscriber::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/watchtower.php' => config_path('watchtower.php'),
        ], 'watchtower-config');

        if (! $this->app['config']->get('watchtower.enabled')) {
            return;
        }

        if ($this->app->runningInConsole()) {
            $this->commands([FlushCommand::class, TestExceptionCommand::class]);
        }

        if ($this->app['config']->get('watchtower.auto_schedule_flush')) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('watchtower:flush')->everyMinute();
            });
        }

        $events = $this->app['events'];

        if ($this->app['config']->get('watchtower.features.jobs')) {
            $events->subscribe(QueueEventSubscriber::class);
        }

        if ($this->app['config']->get('watchtower.features.schedule')) {
            $events->subscribe(ScheduleSubscriber::class);
        }

        if ($this->app['config']->get('watchtower.features.logs')) {
            try {
                $level = Level::fromName(ucfirst((string) $this->app['config']->get('watchtower.log_level', 'warning')));
                Log::getLogger()->pushHandler(new BufferLogHandler($this->app->make(Recorder::class), $level));
            } catch (Throwable $throwable) {
                error_log("watchtower-agent: log handler registration failed: {$throwable->getMessage()}");
            }
        }

        if ($this->app['config']->get('watchtower.features.requests')) {
            try {
                $this->app[\Illuminate\Contracts\Http\Kernel::class]->pushMiddleware(Http\RecordRequest::class);
            } catch (Throwable $throwable) {
                error_log("watchtower-agent: request middleware registration failed: {$throwable->getMessage()}");
            }
        }

        if ($this->app['config']->get('watchtower.features.cache')) {
            $events->subscribe(CacheSubscriber::class);
        }

        if ($this->app['config']->get('watchtower.features.notifications')) {
            $events->subscribe(NotificationSubscriber::class);
        }

        if ($this->app['config']->get('watchtower.features.exceptions')) {
            $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
                if (method_exists($handler, 'reportable')) {
                    $reporter = $this->app->make(ExceptionReporter::class);
                    $handler->reportable(fn (Throwable $throwable) => $reporter->report($throwable));
                }
            });
        }
    }
}
