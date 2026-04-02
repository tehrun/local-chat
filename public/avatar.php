<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = requireAuth();
$userId = (int) $user['id'];
$targetUserId = max(0, (int) ($_GET['user'] ?? 0));
$targetGroupId = max(0, (int) ($_GET['group'] ?? 0));
$path = null;

if ($targetUserId > 0) {
    if ($targetUserId !== $userId && !canAccessConversation($userId, $targetUserId)) {
        http_response_code(404);
        exit;
    }
    $path = avatarPathForUser($targetUserId);
}

if ($targetGroupId > 0) {
    if (!canAccessGroupConversation($targetGroupId, $userId)) {
        http_response_code(404);
        exit;
    }
    $group = findGroupById($targetGroupId);
    $path = is_array($group) ? (($group['avatar_path'] ?? null) ? (string) $group['avatar_path'] : null) : null;
}

if ($path === null || $path === '') {
    http_response_code(404);
    exit;
}

$absolutePath = BASE_PATH . '/' . ltrim($path, '/');
if (!is_file($absolutePath)) {
    http_response_code(404);
    exit;
}

$mime = mime_content_type($absolutePath) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Cache-Control: private, max-age=3600');
readfile($absolutePath);
