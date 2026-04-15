<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = requireAuth();
$userId = (int) $user['id'];
$targetUserId = max(0, (int) ($_GET['user'] ?? 0));
$targetGroupId = max(0, (int) ($_GET['group'] ?? 0));
$path = null;

function respondWithFallbackAvatar(): void
{
    $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="160" height="160" viewBox="0 0 160 160" role="img" aria-label="Avatar placeholder">
    <defs>
        <linearGradient id="avatar-placeholder-gradient" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="#e9edef"/>
            <stop offset="100%" stop-color="#d7dde2"/>
        </linearGradient>
    </defs>
    <rect width="160" height="160" rx="80" fill="url(#avatar-placeholder-gradient)"/>
    <circle cx="80" cy="64" r="28" fill="#b0bbc4"/>
    <path d="M36 136c7-25 24-38 44-38s37 13 44 38" fill="#b0bbc4"/>
</svg>
SVG;

    header('Content-Type: image/svg+xml; charset=UTF-8');
    header('Cache-Control: private, max-age=60');
    echo $svg;
    exit;
}

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
    respondWithFallbackAvatar();
}

$absolutePath = BASE_PATH . '/' . ltrim($path, '/');
if (!is_file($absolutePath)) {
    respondWithFallbackAvatar();
}

$mime = mime_content_type($absolutePath) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Cache-Control: private, max-age=3600');
if (readfile($absolutePath) === false) {
    respondWithFallbackAvatar();
}
