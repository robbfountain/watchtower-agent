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
    private const MAX_MESSAGE_CHARS = 16384;

    private const MAX_CONTEXT_BYTES = 8192;

    private const MAX_CONTEXT_DEPTH = 8;

    private const JSON_FLAGS = JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    public function __construct(private readonly Recorder $recorder, Level $level)
    {
        parent::__construct($level, bubble: true);
    }

    protected function write(LogRecord $record): void
    {
        try {
            $this->recorder->record('log_entry', [
                'level' => strtolower($record->level->name),
                'message' => mb_substr($record->message, 0, self::MAX_MESSAGE_CHARS),
                'context' => $record->context !== [] ? $this->boundContext($record->context) : null,
                'logged_at' => $record->datetime->format('c'),
            ]);
        } catch (Throwable $throwable) {
            error_log("watchtower-agent: log handler failed: {$throwable->getMessage()}");
        }
    }

    /**
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>
     */
    private function boundContext(array $context): array
    {
        $encoded = json_encode($context, self::JSON_FLAGS, self::MAX_CONTEXT_DEPTH);
        $jsonError = json_last_error();
        $text = $encoded === false ? '' : $encoded;

        if ($jsonError === JSON_ERROR_NONE && strlen($text) <= self::MAX_CONTEXT_BYTES) {
            return $context;
        }

        $reason = $jsonError !== JSON_ERROR_NONE ? json_last_error_msg() : 'size '.strlen($text).' bytes';
        error_log("watchtower-agent: log context replaced with preview ({$reason})");

        return [
            '_truncated' => true,
            'preview' => substr($text, 0, self::MAX_CONTEXT_BYTES),
        ];
    }
}
