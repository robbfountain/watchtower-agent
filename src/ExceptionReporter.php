<?php

declare(strict_types=1);

namespace Watchtower\Agent;

use Throwable;

class ExceptionReporter
{
    public function __construct(private readonly Recorder $recorder) {}

    public function report(Throwable $throwable): void
    {
        try {
            $class = $throwable::class;
            $file = $throwable->getFile();
            $line = $throwable->getLine();

            $this->recorder->record('exception', [
                'hash' => hash('sha256', "{$class}|{$file}|{$line}"),
                'class' => $class,
                'message' => $throwable->getMessage(),
                'file' => $file,
                'line' => $line,
                'trace' => array_slice(explode("\n", $throwable->getTraceAsString()), 0, 30),
                'occurred_at' => date('c'),
            ]);
        } catch (Throwable $inner) {
            error_log("watchtower-agent: exception reporter failed: {$inner->getMessage()}");
        }
    }
}
