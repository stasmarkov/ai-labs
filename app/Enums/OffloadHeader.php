<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The internal-redirect header a web server understands: a URI for the nginx and OpenLiteSpeed spellings,
 * an absolute path for X-Sendfile (Apache mod_xsendfile and the FrankenPHP Caddy intercept).
 */
enum OffloadHeader: string
{
    case XAccel = 'x-accel';
    case XSendfile = 'x-sendfile';
    case XLiteSpeed = 'x-litespeed';
    case None = 'none';

    public function headerName(): ?string
    {
        return match ($this) {
            self::XAccel => 'X-Accel-Redirect',
            self::XSendfile => 'X-Sendfile',
            self::XLiteSpeed => 'X-LiteSpeed-Location',
            self::None => null,
        };
    }

    public function usesUri(): bool
    {
        return $this === self::XAccel || $this === self::XLiteSpeed;
    }
}
