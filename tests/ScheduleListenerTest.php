<?php

declare(strict_types=1);

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Watchtower\Agent\Buffer;

function fakeTask(): ScheduledEvent
{
    $task = Mockery::mock(ScheduledEvent::class)->makePartial();
    $task->shouldReceive('command')->passthru();
    $task->command = "'/usr/bin/php' 'artisan' inspire";
    $task->exitCode = 0;

    return $task;
}

it('records finished task runs', function () {
    $task = fakeTask();

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFinished($task, 0.5));

    $rows = app(Buffer::class)->pull(10);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['type'])->toBe('task_run')
        ->and($rows[0]['payload']['status'])->toBe('finished')
        ->and($rows[0]['payload']['command'])->toContain('inspire')
        ->and($rows[0]['payload']['exit_code'])->toBe(0)
        ->and($rows[0]['payload']['finished_at'])->not->toBeNull();
});

it('records failed task runs', function () {
    $task = fakeTask();

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFailed($task, new RuntimeException('cron blew up')));

    $rows = app(Buffer::class)->pull(10);

    expect($rows[0]['payload']['status'])->toBe('failed');
});
