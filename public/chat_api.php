<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = requireAuth();
$groupId = requirePositiveInt($_POST + $_GET, 'group');
$isGroupConversation = $groupId > 0;
$action = $_GET['action'] ?? $_POST['action'] ?? 'messages';

if ($isGroupConversation) {
    if (!canAccessGroupConversation($groupId, (int) $user['id'])) {
        jsonResponse(['error' => 'Group not found.'], 404);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        requireCsrfToken();
    }

    if ($action === 'messages') {
        $limit = max(0, (int) ($_GET['limit'] ?? 0));
        $beforeMessageId = max(0, (int) ($_GET['before'] ?? 0));
        $payload = groupConversationPayload(
            $groupId,
            (int) $user['id'],
            $limit,
            $beforeMessageId > 0 ? $beforeMessageId : null
        );
        $payload['signature'] = groupConversationStateSignature($groupId, (int) $user['id']);
        jsonResponse($payload);
    }

    if ($action === 'signature') {
        jsonResponse([
            'signature' => groupConversationStateSignature($groupId, (int) $user['id']),
        ]);
    }

    if ($action === 'typing') {
        $typing = filter_var($_POST['typing'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        if ($typing) {
            updateGroupTypingStatus($groupId, (int) $user['id']);
        } else {
            clearGroupTypingStatus($groupId, (int) $user['id']);
        }
        jsonResponse(['ok' => true]);
    }

    if ($action === 'read') {
        markGroupMessagesRead($groupId, (int) $user['id']);
        jsonResponse(array_merge(
            groupConversationPayload($groupId, (int) $user['id']),
            ['signature' => groupConversationStateSignature($groupId, (int) $user['id'])]
        ));
    }

    if ($action === 'send_text') {
        $message = sendGroupTextMessage($groupId, (int) $user['id'], (string) ($_POST['body'] ?? ''));
        if (is_string($message)) {
            jsonResponse(['error' => $message], 422);
        }
        jsonResponse([
            'ok' => true,
            'message' => $message,
            'typing_members' => groupTypingMembersWithoutMaintenance($groupId, (int) $user['id']),
            'signature' => groupConversationStateSignature($groupId, (int) $user['id']),
        ]);
    }

    if ($action === 'delete_conversation') {
        clearGroupConversationForUser($groupId, (int) $user['id']);
        jsonResponse([
            'ok' => true,
            'payload' => array_merge(
                groupConversationPayload($groupId, (int) $user['id']),
                ['signature' => groupConversationStateSignature($groupId, (int) $user['id'])]
            ),
        ]);
    }

    if ($action === 'leave_group') {
        $error = leaveGroup($groupId, (int) $user['id']);
        if ($error !== null) {
            jsonResponse(['error' => $error], 422);
        }
        jsonResponse(['ok' => true]);
    }

    if ($action === 'delete_group') {
        $error = deleteGroup($groupId, (int) $user['id']);
        if ($error !== null) {
            jsonResponse(['error' => $error], 422);
        }
        jsonResponse(['ok' => true]);
    }

    if ($action === 'rename_group') {
        $error = renameGroup($groupId, (int) $user['id'], (string) ($_POST['name'] ?? ''));
        if ($error !== null) {
            jsonResponse(['error' => $error], 422);
        }
        jsonResponse([
            'ok' => true,
            'payload' => array_merge(
                groupConversationPayload($groupId, (int) $user['id']),
                ['signature' => groupConversationStateSignature($groupId, (int) $user['id'])]
            ),
        ]);
    }

    jsonResponse(['error' => 'Unsupported action.'], 400);
}

$otherUserId = requirePositiveInt($_POST + $_GET, 'user');
if ($otherUserId <= 0) {
    jsonResponse(['error' => 'Conversation not found.'], 404);
}
$otherUser = findUserById($otherUserId);

if ($otherUser === null || (int) $otherUser['id'] === (int) $user['id'] || !canAccessConversation((int) $user['id'], $otherUserId)) {
    jsonResponse(['error' => 'Conversation not found.'], 404);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requireCsrfToken();
}

if ($action === 'messages') {
    $limit = max(0, (int) ($_GET['limit'] ?? 0));
    $beforeMessageId = max(0, (int) ($_GET['before'] ?? 0));
    $payload = conversationPayload(
        (int) $user['id'],
        $otherUserId,
        $limit,
        $beforeMessageId > 0 ? $beforeMessageId : null
    );
    $payload['signature'] = conversationStateSignature((int) $user['id'], $otherUserId);

    jsonResponse($payload);
}

if ($action === 'signature') {
    jsonResponse([
        'signature' => conversationStateSignature((int) $user['id'], $otherUserId),
    ]);
}


if ($action === 'delete_conversation') {
    clearConversationForUser((int) $user['id'], $otherUserId);

    jsonResponse([
        'ok' => true,
        'payload' => array_merge(
            conversationPayload((int) $user['id'], $otherUserId),
            ['signature' => conversationStateSignature((int) $user['id'], $otherUserId)]
        ),
    ]);
}

if ($action === 'revoke_friendship') {
    $error = revokeFriendship((int) $user['id'], $otherUserId);

    if ($error !== null) {
        jsonResponse(['error' => $error], 422);
    }

    jsonResponse([
        'ok' => true,
        'payload' => array_merge(
            conversationPayload((int) $user['id'], $otherUserId),
            ['signature' => conversationStateSignature((int) $user['id'], $otherUserId)]
        ),
    ]);
}

$canChat = canUsersChat((int) $user['id'], $otherUserId);

if (!$canChat) {
    jsonResponse(['error' => 'You can only chat after the friend request is accepted.'], 403);
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
        'signature' => conversationStateSignature((int) $user['id'], $otherUserId),
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
        'signature' => conversationStateSignature((int) $user['id'], $otherUserId),
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
        'signature' => conversationStateSignature((int) $user['id'], $otherUserId),
    ]);
}


if ($action === 'send_file') {
    $message = sendFileMessage((int) $user['id'], $otherUserId, $_FILES['shared_file'] ?? []);

    if (is_string($message)) {
        jsonResponse(['error' => $message], 422);
    }

    jsonResponse([
        'ok' => true,
        'message' => $message,
        'typing' => false,
        'signature' => conversationStateSignature((int) $user['id'], $otherUserId),
    ]);
}

if ($action === 'send_image') {
    $message = sendImageMessage((int) $user['id'], $otherUserId, $_FILES['image_file'] ?? []);

    if (is_string($message)) {
        jsonResponse(['error' => $message], 422);
    }

    jsonResponse([
        'ok' => true,
        'message' => $message,
        'typing' => false,
        'signature' => conversationStateSignature((int) $user['id'], $otherUserId),
    ]);
}

jsonResponse(['error' => 'Unsupported action.'], 400);
