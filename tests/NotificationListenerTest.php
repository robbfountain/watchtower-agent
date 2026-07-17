<?php

declare(strict_types=1);

use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Watchtower\Agent\Buffer;

it('records sent and failed notifications', function () {
    $notifiable = new stdClass;
    $notification = new class extends Notification {};

    event(new NotificationSent($notifiable, $notification, 'mail'));
    event(new NotificationFailed($notifiable, $notification, 'mail', ['exception' => new RuntimeException('smtp down')]));

    $rows = app(Buffer::class)->pull(10);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['payload']['status'])->toBe('sent')
        ->and($rows[0]['payload']['channel'])->toBe('mail')
        ->and($rows[1]['payload']['status'])->toBe('failed')
        ->and($rows[1]['payload']['error'])->toContain('smtp down');
});
