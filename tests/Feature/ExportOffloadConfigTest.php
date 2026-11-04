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

test('AC-5 apache limits mod_xsendfile to the exports directory', function () {
    $conf = exportConfigFile('.ddev/apache/apache-site.conf');

    expect($conf)->toMatch('#^\s*XSendFile\s+On#m')
        ->and($conf)->toMatch('#^\s*XSendFilePath\s+'.preg_quote(EXPORTS_DIRECTORY, '#').'/?\s*$#m')
        ->and($conf)->not->toMatch('#^\s*XSendFilePath\s+/\s*$#m');
});

test('AC-2 the web image installs mod_xsendfile for apache-fpm', function () {
    $dockerfile = exportConfigFile('.ddev/web-build/Dockerfile');

    expect($dockerfile)->toContain('libapache2-mod-xsendfile');
});

test('AC-5 frankenphp intercepts X-Sendfile only for paths under the exports directory', function () {
    $conf = exportConfigFile('.ddev/servers/frankenphp/web-build/Caddyfile.frankenphp');

    expect($conf)->toContain('intercept')
        ->and($conf)->toMatch('#header\s+X-Sendfile\s+'.preg_quote(EXPORTS_DIRECTORY, '#').'/\*#');
});

test('AC-5 openlitespeed maps the internal context to the exports directory only', function () {
    $conf = exportConfigFile('.ddev/servers/openlitespeed/conf/vhosts/interview/vhconf.conf');

    expect($conf)->toMatch('#context\s+/_protected_exports/\s*\{[^}]*location\s+'.preg_quote(EXPORTS_DIRECTORY, '#').'/?#s');
});

test('AC-5 openlitespeed denies the internal URI to clients on the decoded path, whatever the encoding', function () {
    $conf = exportConfigFile('.ddev/servers/openlitespeed/conf/vhosts/interview/vhconf.conf');

    expect($conf)->toContain(implode("\n", [
        'RewriteCond %{REQUEST_URI} "_protected_exports" [NC]',
        'RewriteCond %{THE_REQUEST} "!^(GET|HEAD) /exports/[a-z0-9-]{1,40}(\?[^ ]*)? HTTP/"',
        'RewriteRule .* - [F,L]',
    ]));
    // The raw request line alone would miss /%5Fprotected_exports/ and //_protected_exports/.
    expect($conf)->not->toMatch('#RewriteCond\s+%\{THE_REQUEST\}\s+"\^\[A-Z\]\+\s+/_protected_exports/"#');
});

test('AC-5 the openlitespeed allow rule lets through only the request line of an export download', function (string $requestLine, bool $allowed) {
    $conf = exportConfigFile('.ddev/servers/openlitespeed/conf/vhosts/interview/vhconf.conf');
    preg_match('#RewriteCond\s+%\{THE_REQUEST\}\s+"!([^"]+)"#', $conf, $match);

    expect(preg_match('#'.str_replace('#', '\#', $match[1]).'#', $requestLine) === 1)->toBe($allowed);
})->with([
    'download' => ['GET /exports/listings HTTP/1.1', true],
    'download with query' => ['GET /exports/listings?mode=offload HTTP/2', true],
    'head' => ['HEAD /exports/listings HTTP/1.1', true],
    'direct' => ['GET /_protected_exports/listings.csv HTTP/1.1', false],
    'encoded underscore' => ['GET /%5Fprotected_exports/listings.csv HTTP/1.1', false],
    'double slash' => ['GET //_protected_exports/listings.csv HTTP/1.1', false],
    'dot segment' => ['GET /exports/../_protected_exports/listings.csv HTTP/1.1', false],
    'encoded dot segment' => ['GET /exports/%2e%2e/_protected_exports/listings.csv HTTP/1.1', false],
    'encoded slash' => ['GET /exports/a%2f..%2f_protected_exports/x HTTP/1.1', false],
    'absolute form' => ['GET http://interview.ddev.site/_protected_exports/listings.csv HTTP/1.1', false],
    'other method' => ['POST /exports/listings HTTP/1.1', false],
]);

test('AC-2 the openlitespeed image maps csv to text/csv so an offloaded download keeps its Content-Type', function () {
    $dockerfile = file_get_contents(dirname(__DIR__, 2).'/.ddev/servers/openlitespeed/Dockerfile');

    expect($dockerfile)->toContain('csv     = text/csv')->toContain('/usr/local/lsws/conf/mime.properties');
});
