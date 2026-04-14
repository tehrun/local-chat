<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/assert.php';
require_once __DIR__ . '/../support/bootstrap_integration.php';

return [
    'session diagnostics reports database backend with active session' => static function (): void {
        $diagnostics = sessionDiagnostics();

        assertSameValue($diagnostics['backend'] ?? null, SESSION_BACKEND_NAME);
        assertTrue(isset($diagnostics['session_id']) && is_string($diagnostics['session_id']) && $diagnostics['session_id'] !== '');
    },

    'db connection is initialized and uses configured driver' => static function (): void {
        $pdo = db();

        assertTrue($pdo instanceof PDO);
        assertSameValue($pdo->getAttribute(PDO::ATTR_DRIVER_NAME), dbDriver());
    },
];
