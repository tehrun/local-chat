<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = requireAuth();
$messageId = (int) ($_GET['message'] ?? 0);

purgeExpiredMessages();

$stmt = db()->prepare(
    'SELECT * FROM messages
     WHERE id = :id
       AND audio_path IS NOT NULL
       AND (sender_id = :user_id OR recipient_id = :user_id)
     LIMIT 1'
);
$stmt->execute([
    'id' => $messageId,
    'user_id' => $user['id'],
]);
$message = $stmt->fetch();

if ($message === false) {
    http_response_code(404);
    exit('Audio not found.');
}

$fullPath = BASE_PATH . '/' . $message['audio_path'];
if (!is_file($fullPath)) {
    http_response_code(404);
    exit('Audio file missing.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
header('Content-Type: ' . ($finfo->file($fullPath) ?: 'application/octet-stream'));
header('Content-Length: ' . (string) filesize($fullPath));
readfile($fullPath);
