<?php

declare(strict_types=1);

require_once __DIR__ . '/../../support/assert.php';
require_once __DIR__ . '/../../support/bootstrap_unit.php';

return [
    'csrf token generation and verification works' => static function (): void {
        unset($_SESSION['csrf_token']);

        $token = csrfToken();
        assertTrue(is_string($token) && strlen($token) === 64, 'CSRF token should be a 64-char hex string');
        assertTrue(verifyCsrfToken($token));
        assertFalse(verifyCsrfToken('not-the-token'));
        assertFalse(verifyCsrfToken(null));
    },
];
