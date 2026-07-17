<?php

declare(strict_types=1);

namespace Watchtower\Agent\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;
use Watchtower\Agent\Buffer;
use Watchtower\Agent\PayloadBuilder;

class FlushCommand extends Command
{
    protected $signature = 'watchtower:flush';

    protected $description = 'Send buffered monitoring data to the Watchtower hub';

    public function handle(Buffer $buffer, PayloadBuilder $builder): int
    {
        try {
            $hubUrl = (string) config('watchtower.hub_url');
            $token = (string) config('watchtower.token');

            if ($hubUrl === '' || $token === '') {
                $this->comment('Watchtower hub_url or token not configured; skipping flush.');

                return self::SUCCESS;
            }

            $rows = $buffer->pull(1000);
            $payload = $builder->build($rows);

            $encoded = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);

            if ($encoded === false) {
                error_log('watchtower-agent: json_encode failed for payload; skipping flush.');

                return self::SUCCESS;
            }

            $response = Http::withToken($token)
                ->withHeaders(['Content-Encoding' => 'gzip'])
                ->withBody(gzencode($encoded) ?: '', 'application/json')
                ->timeout(10)
                ->post("{$hubUrl}/api/agent/ingest");

            if ($response->status() === 422) {
                $buffer->forget(array_column($rows, 'id'));
                error_log('watchtower-agent: hub rejected batch (422): '.substr($response->body(), 0, 500));

                return self::SUCCESS;
            }

            if (! $response->successful()) {
                $this->comment("Hub rejected flush ({$response->status()}); keeping buffer.");
                error_log("watchtower-agent: flush failed with status {$response->status()}");

                return self::SUCCESS;
            }

            $buffer->forget(array_column($rows, 'id'));
            $this->info('Flushed '.count($rows).' buffered events.');
        } catch (Throwable $throwable) {
            error_log("watchtower-agent: flush failed: {$throwable->getMessage()}");
        }

        return self::SUCCESS;
    }
}
