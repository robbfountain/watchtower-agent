<?php

declare(strict_types=1);

namespace Watchtower\Agent\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;
use Watchtower\Agent\Recorder;

class BufferLogHandler extends AbstractProcessingHandler
{
    public function __construct(private readonly Recorder $recorder, Level $level)
    {
        parent::__construct($level, bubble: true);
    }

    protected function write(LogRecord $record): void
    {
        try {
            $this->recorder->record('log_entry', [
                'level' => strtolower($record->level->name),
                'message' => $record->message,
                'context' => $record->context !== [] ? $record->context : null,
                'logged_at' => $record->datetime->format('c'),
            ]);
        } catch (Throwable $throwable) {
            error_log("watchtower-agent: log handler failed: {$throwable->getMessage()}");
        }
    }
}
