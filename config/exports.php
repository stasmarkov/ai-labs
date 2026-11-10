<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Exports Directory
    |--------------------------------------------------------------------------
    |
    | Where exports:generate writes its CSV files. The web server configs
    | point their offload locations at this same directory.
    |
    */

    'directory' => storage_path('app/exports'),

    /*
    |--------------------------------------------------------------------------
    | Download Log
    |--------------------------------------------------------------------------
    |
    | One JSON line per download: PHP hold time, peak memory, CPU and the
    | bytes PHP itself wrote.
    |
    */

    'log' => storage_path('logs/exports.jsonl'),

    /*
    |--------------------------------------------------------------------------
    | Throttle
    |--------------------------------------------------------------------------
    |
    | Each download holds bandwidth and, in stream mode, a PHP worker on
    | purpose, so callers are limited per IP.
    |
    */

    'per_minute' => filled(env('EXPORTS_PER_MINUTE')) ? (int) env('EXPORTS_PER_MINUTE') : 30,

    /*
    |--------------------------------------------------------------------------
    | Offload
    |--------------------------------------------------------------------------
    |
    | "offload" maps a SERVER_SOFTWARE substring to the internal-redirect header
    | that server understands. "offload_header" forces one header for every
    | server, and "offload_enabled" lists the headers that may be used: any other
    | one answers with X-Export-Mode: stream-fallback. "uri_prefix" is the
    | internal location that the nginx config aliases to the
    | exports directory.
    |
    */

    'offload' => [
        'nginx' => 'x-accel',
        'apache' => 'x-sendfile',
        'litespeed' => 'x-litespeed',
        'caddy' => 'x-sendfile',
        'frankenphp' => 'x-sendfile',
    ],

    /*
    | Servers whose X-Sendfile only works when the config in front of PHP intercepts it. FrankenPHP worker mode
    | and Octane report the same SERVER_SOFTWARE as the classic mode but have no intercept, so an empty
    | response would reach the client. The classic Caddyfile marks itself with `env EXPORTS_SENDFILE_INTERCEPT 1`
    | (it reaches PHP as a server variable); without that mark these servers answer stream-fallback.
    */

    'sendfile_needs_intercept' => ['caddy', 'frankenphp'],

    'offload_header' => filled(env('EXPORTS_OFFLOAD_HEADER')) ? env('EXPORTS_OFFLOAD_HEADER') : null,

    'offload_enabled' => array_values(array_filter(array_map(
        trim(...),
        explode(',', (string) env('EXPORTS_OFFLOAD_ENABLED', 'x-accel,x-sendfile,x-litespeed')),
    ))),

    'uri_prefix' => '/_protected_exports/',

    /*
    |--------------------------------------------------------------------------
    | Servers Sharing One PHP Process
    |--------------------------------------------------------------------------
    |
    | SERVER_SOFTWARE substrings of servers that run requests as threads of one
    | process. PHP has no per-thread getrusage, so the download log leaves cpu_ms
    | null (cpu_scope "process") there instead of a figure that mixes requests.
    |
    */

    'shared_process_servers' => ['frankenphp', 'caddy'],

    /*
    |--------------------------------------------------------------------------
    | Longest Stream
    |--------------------------------------------------------------------------
    |
    | Wall-clock seconds after which stream mode stops writing and logs the
    | download as aborted with the reason max_duration, so a client that reads
    | a few bytes a second cannot hold a PHP worker for hours.
    |
    */

    'stream_max_seconds' => filled(env('EXPORTS_STREAM_MAX_SECONDS')) ? (float) env('EXPORTS_STREAM_MAX_SECONDS') : 300.0,

];
