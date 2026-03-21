<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = requireAuth();
$otherUserId = (int) ($_GET['user'] ?? $_POST['user'] ?? 0);
$otherUser = findUserById($otherUserId);

if ($otherUser === null || (int) $otherUser['id'] === (int) $user['id']) {
    jsonResponse(['error' => 'Conversation not found.'], 404);
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'messages';

if ($action === 'messages') {
    jsonResponse([
        'messages' => conversationMessages((int) $user['id'], $otherUserId),
        'typing' => isUserTyping((int) $user['id'], $otherUserId),
    ]);
}

if ($action === 'typing') {
    $typing = filter_var($_POST['typing'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

    if ($typing) {
        updateTypingStatus((int) $user['id'], $otherUserId);
    } else {
        clearTypingStatus((int) $user['id'], $otherUserId);
    }

    jsonResponse(['ok' => true]);
}

if ($action === 'send_text') {
    $error = sendTextMessage((int) $user['id'], $otherUserId, $_POST['body'] ?? '');

    if ($error !== null) {
        jsonResponse(['error' => $error], 422);
    }

    jsonResponse([
        'ok' => true,
        'messages' => conversationMessages((int) $user['id'], $otherUserId),
        'typing' => isUserTyping((int) $user['id'], $otherUserId),
    ]);
}

jsonResponse(['error' => 'Unsupported action.'], 400);
