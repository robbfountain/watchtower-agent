<?php

declare(strict_types=1);

namespace Watchtower\Agent;

use Throwable;

class Recorder
{
    public function __construct(private readonly Buffer $buffer) {}

    public function record(string $type, array $payload): void
    {
        try {
            $this->buffer->push($type, $payload);
        } catch (Throwable $exception) {
            error_log("watchtower-agent: buffer write failed: {$exception->getMessage()}");
        }
    }
}
