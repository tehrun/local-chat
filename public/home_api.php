<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = requireAuth();

jsonResponse(chatListPayload((int) $user['id']));
