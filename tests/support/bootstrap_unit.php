<?php

declare(strict_types=1);

if (!defined('LOCAL_CHAT_SKIP_APP_INIT')) {
    define('LOCAL_CHAT_SKIP_APP_INIT', true);
}

if (getenv('CHAT_MESSAGE_ENCRYPTION_KEY') === false) {
    putenv('CHAT_MESSAGE_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
}

require_once __DIR__ . '/../../src/bootstrap.php';
