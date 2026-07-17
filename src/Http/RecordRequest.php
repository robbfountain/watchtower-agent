<?php

declare(strict_types=1);

namespace Watchtower\Agent\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Watchtower\Agent\Recorder;

class RecordRequest
{
    public function __construct(private readonly Recorder $recorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        $response = $next($request);

        try {
            $routePattern = $request->route()?->uri() ?? $request->path();
            $query = $request->getQueryString();

            $this->recorder->record('request', [
                'route' => mb_substr("{$request->method()} {$routePattern}", 0, 500),
                'url' => mb_substr('/'.ltrim($request->path(), '/').($query !== null ? "?{$query}" : ''), 0, 2000),
                'method' => $request->method(),
                'status' => $response->getStatusCode(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'memory_kb' => (int) round(memory_get_peak_usage(true) / 1024),
                'occurred_at' => date('c'),
            ]);
        } catch (Throwable $throwable) {
            error_log("watchtower-agent: request recording failed: {$throwable->getMessage()}");
        }

        return $response;
    }
}
