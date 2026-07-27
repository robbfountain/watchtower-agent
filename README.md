# Watchtower Agent

A Laravel package that buffers events (logs, exceptions, queue jobs, scheduled tasks) locally in a SQLite file and flushes them to a Watchtower hub on a one-minute schedule. If the hub is unreachable, the buffer is retained and retried on the next flush. The package is designed to never throw or interrupt your application when the hub is unavailable.

## Requirements

- PHP 8.3+
- Laravel 11+

## Installation

Add the private repository to your `composer.json`:

```bash
composer config repositories.watchtower-agent vcs git@github.com:robbfountain/watchtower-agent.git
composer require 131studios/watchtower-agent:^0.3
php artisan vendor:publish --tag=watchtower-config
```

## Configuration

Add two lines to your `.env` file (values from the Watchtower hub site registration modal):

```env
WATCHTOWER_HUB_URL=https://your-watchtower-hub.example.com
WATCHTOWER_TOKEN=your-site-token
```

The flush command registers itself on Laravel's scheduler automatically every minute. No additional scheduler entry is required beyond the standard `* * * * * php artisan schedule:run` cron.

## Config Reference

| Key | Environment Variable | Default | Description |
|-----|---------------------|---------|-------------|
| `enabled` | `WATCHTOWER_ENABLED` | `true` | Master switch. Set to `false` to capture nothing. |
| `hub_url` | `WATCHTOWER_HUB_URL` | `null` | URL of the Watchtower hub (required). |
| `token` | `WATCHTOWER_TOKEN` | `null` | Bearer token from the hub site registration. |
| `log_level` | `WATCHTOWER_LOG_LEVEL` | `warning` | Minimum log level to capture (`debug`, `info`, `warning`, `error`, etc.). |
| `queues` | n/a | `['default']` | Queue names to snapshot pending-count metrics for. |
| `buffer.path` | `WATCHTOWER_BUFFER_PATH` | auto | Path to the SQLite buffer file. Defaults to `storage/watchtower.sqlite`. |
| `buffer.max_rows` | `WATCHTOWER_BUFFER_MAX_ROWS` | `10000` | Maximum events held locally before oldest are dropped. |
| `features.jobs` | n/a | `true` | Capture queue job completions and failures. |
| `features.exceptions` | n/a | `true` | Capture exceptions via the exception handler. |
| `features.logs` | n/a | `true` | Capture log entries at or above `log_level`. |
| `features.schedule` | n/a | `true` | Capture scheduled task runs. |
| `features.requests` | n/a | `true` | Capture HTTP request metrics and slow requests. |
| `features.cache` | n/a | `true` | Capture cache operations and hits/misses. |
| `features.notifications` | n/a | `true` | Capture notification send events and delivery status. |
| `slow_threshold_ms` | `WATCHTOWER_SLOW_THRESHOLD_MS` | `1000` | Request duration threshold (milliseconds) for marking as slow. |
| `auto_schedule_flush` | n/a | `true` | Register the flush command on the scheduler automatically. |
| `sealing_public_key` | `WATCHTOWER_SEALING_PUBLIC_KEY` | `null` | Hub public key (base64) for sealing database credentials. Obtain from the hub Databases page. |
| `report_databases` | `WATCHTOWER_REPORT_DATABASES` | `true` | Set to `false` to disable sealed database credential reporting entirely. |
| `database_connections` | n/a | `['mysql']` | List of named database connections whose credentials will be sealed and reported. |

## Database Discovery

When `WATCHTOWER_SEALING_PUBLIC_KEY` is set, the agent seals each configured MySQL connection's credentials (host, port, database name, username, password) using libsodium `crypto_box_seal` with the hub's public key. The sealed blobs are included in every flush. Only the hub can decrypt them using its paired private key. Credentials never leave the site in plaintext.

To enable, copy the sealing public key from the hub's Databases page and add it to your site's `.env`:

```env
WATCHTOWER_SEALING_PUBLIC_KEY=<base64-key-from-hub-databases-page>
```

If no key is configured, or if `WATCHTOWER_REPORT_DATABASES=false`, the databases section is omitted entirely from the flush payload. Sealing failures degrade gracefully to an `error_log` entry and never interrupt the flush or the application.

## Never-Hurt-the-Site Guarantee

All hub communication happens in the flush command, which runs out of band on the scheduler. Every network call is wrapped in a try/catch. If the hub is unreachable, returns an error response, or the buffer file is unwritable, the failure is logged to your application log and the command exits with code 0. Your application's own request cycle is never touched.

## Local End-to-End Test

To verify the full wire from a scratch Laravel app to a locally running hub:

1. Start the Watchtower hub:

   ```bash
   cd ~/Code/watchtower
   php artisan serve --port=8001
   ```

2. Register a site in the hub UI and copy the token from the site registration modal.

3. Create a scratch Laravel app and point Composer at the local agent package:

   ```bash
   laravel new scratch-app
   cd scratch-app
   composer config repositories.watchtower-agent path ~/Code/watchtower-agent
   composer require 131studios/watchtower-agent
   php artisan vendor:publish --tag=watchtower-config
   ```

4. Set the `.env` variables in the scratch app:

   ```env
   WATCHTOWER_HUB_URL=http://localhost:8001
   WATCHTOWER_TOKEN=<token-from-step-2>
   ```

5. Generate some events in the scratch app (trigger a log warning, throw an exception, dispatch a job, etc.), then flush:

   ```bash
   php artisan watchtower:flush
   ```

6. Reload the hub dashboard for the site. The events should appear within a few seconds.
