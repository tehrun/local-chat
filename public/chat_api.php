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
    jsonResponse(conversationPayload((int) $user['id'], $otherUserId));
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

if ($action === 'read') {
    markMessagesRead((int) $user['id'], $otherUserId);

    jsonResponse([
        'ok' => true,
        'messages' => conversationMessagesWithoutMaintenance((int) $user['id'], $otherUserId),
        'typing' => isUserTypingWithoutMaintenance((int) $user['id'], $otherUserId),
    ]);
}

if ($action === 'send_text') {
    $message = sendTextMessage((int) $user['id'], $otherUserId, $_POST['body'] ?? '');

    if (is_string($message)) {
        jsonResponse(['error' => $message], 422);
    }

    jsonResponse([
        'ok' => true,
        'message' => $message,
        'typing' => false,
    ]);
}

if ($action === 'send_voice') {
    $message = sendVoiceMessage((int) $user['id'], $otherUserId, $_FILES['voice_note'] ?? []);

    if (is_string($message)) {
        jsonResponse(['error' => $message], 422);
    }

    jsonResponse([
        'ok' => true,
        'message' => $message,
        'typing' => false,
    ]);
}

jsonResponse(['error' => 'Unsupported action.'], 400);
