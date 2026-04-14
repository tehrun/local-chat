<?php

declare(strict_types=1);

namespace LocalChat\Security;

final class Headers
{
    public static function apply(): void
    {
        header_remove('X-Powered-By');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Permissions-Policy: geolocation=(), camera=(), payment=(), usb=(), autoplay=(self)');
        header('X-Permitted-Cross-Domain-Policies: none');
        header('X-Download-Options: noopen');
        if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        ) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'; manifest-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");
    }
}
