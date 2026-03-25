<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = requireAuth();
$messageId = requirePositiveInt($_GET, 'message');
$groupId = max(0, (int) ($_GET['group'] ?? 0));

purgeExpiredMessages();

if ($groupId > 0) {
    if (!canAccessGroupConversation($groupId, (int) $user['id'])) {
        http_response_code(404);
        exit('Media not found.');
    }

    $stmt = db()->prepare(
        'SELECT *
         FROM group_messages
         WHERE id = :id
           AND group_id = :group_id
           AND (audio_path IS NOT NULL OR image_path IS NOT NULL OR file_path IS NOT NULL)
         LIMIT 1'
    );
    $stmt->execute([
        'id' => $messageId,
        'group_id' => $groupId,
    ]);
    $message = $stmt->fetch();
} else {
    $stmt = db()->prepare(
        'SELECT *
         FROM messages
         WHERE id = :id
           AND (audio_path IS NOT NULL OR image_path IS NOT NULL OR file_path IS NOT NULL)
           AND (sender_id = :user_id OR recipient_id = :user_id)
         LIMIT 1'
    );
    $stmt->execute([
        'id' => $messageId,
        'user_id' => $user['id'],
    ]);
    $message = $stmt->fetch();
}

if ($message === false) {
    http_response_code(404);
    exit('Media not found.');
}

$relativePath = $message['image_path'] ?: ($message['audio_path'] ?: $message['file_path']);
if (!isSafeStorageRelativePath(is_string($relativePath) ? $relativePath : null)) {
    http_response_code(404);
    exit('Media not found.');
}

$fullPath = BASE_PATH . '/' . $relativePath;
if (!is_file($fullPath)) {
    http_response_code(404);
    exit('Media file missing.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
header('Content-Type: ' . ($finfo->file($fullPath) ?: 'application/octet-stream'));
header('Content-Length: ' . (string) filesize($fullPath));
$downloadName = trim((string) ($message['file_name'] ?? ''));
if ($downloadName === '') {
    $downloadName = basename((string) $relativePath);
}
$disposition = !empty($message['file_path']) && empty($message['image_path']) && empty($message['audio_path'])
    ? 'attachment'
    : 'inline';
header('Content-Disposition: ' . $disposition . '; filename="' . addcslashes($downloadName, '\"') . '"');
readfile($fullPath);
