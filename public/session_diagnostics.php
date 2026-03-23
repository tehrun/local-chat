<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = requireAuth();

jsonResponse([
    'ok' => true,
    'user_id' => (int) $user['id'],
    'session' => sessionDiagnostics(),
]);
