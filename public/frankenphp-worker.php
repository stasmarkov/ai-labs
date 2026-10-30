<?php

// Not in Octane's stub. Laravel's /up page prints "rendered in Nms" only when LARAVEL_START is
// defined. In worker mode a constant is set once per worker, so this measures time since the
// worker booted, not the request. Kept so /up has the same shape as on the other servers.
defined('LARAVEL_START') || define('LARAVEL_START', microtime(true));

// Set a default for the application base path and public path if they are missing...
$_SERVER['APP_BASE_PATH'] = $_ENV['APP_BASE_PATH'] ?? $_SERVER['APP_BASE_PATH'] ?? __DIR__.'/..';
$_SERVER['APP_PUBLIC_PATH'] = $_ENV['APP_PUBLIC_PATH'] ?? $_SERVER['APP_PUBLIC_PATH'] ?? __DIR__;

require __DIR__.'/../vendor/laravel/octane/bin/frankenphp-worker.php';
