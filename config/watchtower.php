<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('WATCHTOWER_ENABLED', true),
    'hub_url' => env('WATCHTOWER_HUB_URL'),
    'token' => env('WATCHTOWER_TOKEN'),
    'log_level' => env('WATCHTOWER_LOG_LEVEL', 'warning'),
    'queues' => ['default'],
    'buffer' => [
        'path' => env('WATCHTOWER_BUFFER_PATH'),
        'max_rows' => (int) env('WATCHTOWER_BUFFER_MAX_ROWS', 10000),
    ],
    'features' => [
        'jobs' => true,
        'exceptions' => true,
        'logs' => true,
        'schedule' => true,
    ],
    'auto_schedule_flush' => true,
];
