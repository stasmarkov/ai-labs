<?php

declare(strict_types=1);

use App\Enums\OffloadHeader;

const EXPORTS_DIRECTORY = '/var/www/html/storage/app/exports';

function exportConfigFile(string $relative): string
{
    $contents = file_get_contents(dirname(__DIR__, 2).'/'.$relative);

    if (! str_contains($contents, 'SL-11')) {
        test()->fail("{$relative} has no SL-11 offload change.");
    }

    return $contents;
}

test('AC-5 the offload map only names known headers and servers resolve to one', function () {
    foreach (config('exports.offload') as $needle => $header) {
        expect(OffloadHeader::tryFrom($header))->not->toBeNull()->and($needle)->toBe(strtolower($needle));
    }

    expect(config('exports.offload'))->toMatchArray(['nginx' => 'x-accel', 'apache' => 'x-sendfile', 'litespeed' => 'x-litespeed', 'caddy' => 'x-sendfile', 'frankenphp' => 'x-sendfile'])
        ->and(config('exports.uri_prefix'))->toBe('/_protected_exports/');
});

test('AC-5 nginx serves the internal location from the exports directory only', function () {
    $conf = exportConfigFile('.ddev/nginx_full/nginx-site.conf');

    expect($conf)->toMatch('#location\s+/_protected_exports/\s*\{[^}]*\binternal;[^}]*alias\s+'.preg_quote(EXPORTS_DIRECTORY, '#').'/;#s');
});

test('AC-5 frankenphp intercepts X-Sendfile only for paths under the exports directory', function () {
    $conf = exportConfigFile('.ddev/servers/frankenphp/web-build/Caddyfile.frankenphp');

    expect($conf)->toContain('intercept')
        ->and($conf)->toMatch('#header\s+X-Sendfile\s+'.preg_quote(EXPORTS_DIRECTORY, '#').'/\*#');
});
