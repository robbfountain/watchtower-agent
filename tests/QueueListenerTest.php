<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Watchtower\Agent\Buffer;

function fakeJob(string $class = 'App\\Jobs\\Example'): Job
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn($class);
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('uuid')->andReturn('uuid-1');
    $job->shouldReceive('payload')->andReturn([]);

    return $job;
}

it('records completed jobs with runtime', function () {
    $job = fakeJob();

    event(new JobProcessing('redis', $job));
    event(new JobProcessed('redis', $job));

    $rows = app(Buffer::class)->pull(10);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['type'])->toBe('job_event')
        ->and($rows[0]['payload']['status'])->toBe('completed')
        ->and($rows[0]['payload']['job_class'])->toBe('App\\Jobs\\Example')
        ->and($rows[0]['payload']['queue'])->toBe('default')
        ->and($rows[0]['payload']['runtime_ms'])->toBeGreaterThanOrEqual(0);
});

it('records failed jobs with the exception message', function () {
    $job = fakeJob();

    event(new JobFailed('redis', $job, new RuntimeException('nope')));

    $rows = app(Buffer::class)->pull(10);

    expect($rows[0]['payload']['status'])->toBe('failed')
        ->and($rows[0]['payload']['exception'])->toContain('nope');
});
