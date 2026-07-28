<?php

declare(strict_types=1);

namespace Watchtower\Agent\Notifications;

use Illuminate\Notifications\Notification;

class WatchtowerTestNotification extends Notification
{
    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }
}
