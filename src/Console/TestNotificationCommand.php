<?php

declare(strict_types=1);

namespace Watchtower\Agent\Console;

use Illuminate\Console\Command;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Notification;
use Watchtower\Agent\Notifications\WatchtowerTestNotification;

class TestNotificationCommand extends Command
{
    protected $signature = 'watchtower:test-notification {--no-flush : Buffer the notification event without flushing to the hub}';

    protected $description = 'Emit a synthetic notification event to verify it reaches the Watchtower hub';

    public function handle(): int
    {
        if (! (bool) config('watchtower.enabled', true)) {
            $this->warn('Watchtower is disabled (WATCHTOWER_ENABLED=false); the notification will not be captured.');
        }

        if (! (bool) config('watchtower.features.notifications', true)) {
            $this->warn('Notification capture is off (watchtower.features.notifications=false); nothing will be reported.');
        }

        $notifiable = Notification::route('mail', 'watchtower-test@example.com');

        event(new NotificationSent($notifiable, new WatchtowerTestNotification(), 'mail'));

        $this->info('Emitted a synthetic notification event to the Watchtower agent.');

        if ($this->option('no-flush')) {
            $this->comment('Buffered only; it will be sent on the next scheduled flush.');

            return self::SUCCESS;
        }

        $this->comment('Flushing to the hub...');
        $this->call('watchtower:flush');
        $this->info("Done. Check the site's Notifications tab on the hub.");

        return self::SUCCESS;
    }
}
