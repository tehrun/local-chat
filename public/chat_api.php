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

    if ($action === 'search_messages') {
        $query = is_string($_GET['q'] ?? null) ? $_GET['q'] : (string) ($_POST['q'] ?? '');
        $limit = max(1, min(50, (int) ($_GET['limit'] ?? $_POST['limit'] ?? 20)));
        $beforeMessageId = max(0, (int) ($_GET['before'] ?? $_POST['before'] ?? 0));
        $messages = groupMessageSearchResults(
            $groupId,
            (int) $user['id'],
            $query,
            $beforeMessageId > 0 ? $beforeMessageId : null,
            $limit
        );
        jsonResponse([
            'ok' => true,
            'messages' => $messages,
            'has_more' => count($messages) === $limit,
        ]);
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
        $isForwarded = filter_var($_POST['forwarded'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $message = sendGroupTextMessage(
            $groupId,
            (int) $user['id'],
            (string) ($_POST['body'] ?? ''),
            (int) ($_POST['reply_to_message_id'] ?? 0),
            $isForwarded
        );
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

    if ($action === 'send_voice') {
        $isForwarded = filter_var($_POST['forwarded'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $message = sendGroupVoiceMessage(
            $groupId,
            (int) $user['id'],
            $_FILES['voice_note'] ?? [],
            (int) ($_POST['reply_to_message_id'] ?? 0),
            $isForwarded
        );
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

    if ($action === 'send_image') {
        $isForwarded = filter_var($_POST['forwarded'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $message = sendGroupImageMessage(
            $groupId,
            (int) $user['id'],
            $_FILES['image_file'] ?? [],
            (int) ($_POST['reply_to_message_id'] ?? 0),
            $isForwarded
        );
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

    if ($action === 'send_file') {
        $isForwarded = filter_var($_POST['forwarded'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $message = sendGroupFileMessage(
            $groupId,
            (int) $user['id'],
            $_FILES['shared_file'] ?? [],
            (int) ($_POST['reply_to_message_id'] ?? 0),
            $isForwarded
        );
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

    if ($action === 'react') {
        $result = reactToGroupMessage(
            $groupId,
            (int) $user['id'],
            (int) ($_POST['message_id'] ?? 0),
            (string) ($_POST['emoji'] ?? '')
        );
        if (is_string($result)) {
            jsonResponse(['error' => $result], 422);
        }
        jsonResponse([
            'ok' => true,
            'message_id' => (int) ($result['message_id'] ?? 0),
            'signature' => groupConversationStateSignature($groupId, (int) $user['id']),
        ]);
    }

    if ($action === 'pin_message') {
        $error = pinGroupMessage($groupId, (int) $user['id'], (int) ($_POST['message_id'] ?? 0), (int) $user['id']);
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

    if ($action === 'unpin_message') {
        $error = unpinGroupMessage($groupId, (int) $user['id'], (int) ($_POST['message_id'] ?? 0));
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

    if ($action === 'delete_message') {
        $error = deleteGroupMessage($groupId, (int) $user['id'], (int) ($_POST['message_id'] ?? 0));
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

    if ($action === 'edit_message') {
        $error = editGroupMessage(
            $groupId,
            (int) $user['id'],
            (int) ($_POST['message_id'] ?? 0),
            (string) ($_POST['body'] ?? '')
        );
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

    if ($action === 'remove_group_member') {
        $error = removeGroupMember($groupId, (int) $user['id'], (int) ($_POST['user_id'] ?? 0));
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

    if ($action === 'update_group_avatar') {
        $error = updateGroupAvatar($groupId, (int) $user['id'], $_FILES['avatar_file'] ?? []);
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

if ($action === 'block_user' || $action === 'unblock_user') {
    $error = $action === 'block_user'
        ? blockUser((int) $user['id'], $otherUserId)
        : unblockUser((int) $user['id'], $otherUserId);

    if ($error !== null) {
        jsonResponse(['error' => $error], 422);
    }

    jsonResponse([
        'ok' => true,
        'blocking' => blockingStateBetweenUsers((int) $user['id'], $otherUserId),
        'payload' => array_merge(
            conversationPayload((int) $user['id'], $otherUserId),
            ['signature' => conversationStateSignature((int) $user['id'], $otherUserId)]
        ),
    ]);
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

if ($action === 'search_messages') {
    $query = is_string($_GET['q'] ?? null) ? $_GET['q'] : (string) ($_POST['q'] ?? '');
    $limit = max(1, min(50, (int) ($_GET['limit'] ?? $_POST['limit'] ?? 20)));
    $beforeMessageId = max(0, (int) ($_GET['before'] ?? $_POST['before'] ?? 0));
    $messages = privateMessageSearchResults(
        (int) $user['id'],
        $otherUserId,
        $query,
        $beforeMessageId > 0 ? $beforeMessageId : null,
        $limit
    );
    jsonResponse([
        'ok' => true,
        'messages' => $messages,
        'has_more' => count($messages) === $limit,
    ]);
}

if ($action === 'signature') {
    jsonResponse([
        'signature' => conversationStateSignature((int) $user['id'], $otherUserId),
    ]);
}

if ($action === 'delete_message') {
    $error = deletePrivateMessage((int) $user['id'], $otherUserId, (int) ($_POST['message_id'] ?? 0));

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

if ($action === 'edit_message') {
    $error = editPrivateMessage(
        (int) $user['id'],
        $otherUserId,
        (int) ($_POST['message_id'] ?? 0),
        (string) ($_POST['body'] ?? '')
    );

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

if ($action === 'send_friend_request') {
    if (blockingStateBetweenUsers((int) $user['id'], $otherUserId)['is_blocked']) {
        jsonResponse(['error' => 'Friend requests are unavailable because one of you has blocked the other.'], 422);
    }

    $error = sendFriendRequest((int) $user['id'], $otherUserId);

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

if ($action === 'react') {
    $result = reactToPrivateMessage(
        (int) $user['id'],
        $otherUserId,
        (int) ($_POST['message_id'] ?? 0),
        (string) ($_POST['emoji'] ?? '')
    );

    if (is_string($result)) {
        jsonResponse(['error' => $result], 422);
    }

    jsonResponse([
        'ok' => true,
        'message_id' => (int) ($result['message_id'] ?? 0),
        'signature' => conversationStateSignature((int) $user['id'], $otherUserId),
    ]);
}

if ($action === 'pin_message') {
    $error = pinPrivateMessage((int) $user['id'], $otherUserId, (int) ($_POST['message_id'] ?? 0), (int) $user['id']);
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

if ($action === 'unpin_message') {
    $error = unpinPrivateMessage((int) $user['id'], $otherUserId, (int) ($_POST['message_id'] ?? 0));
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
    $isForwarded = filter_var($_POST['forwarded'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
    $message = sendTextMessage(
        (int) $user['id'],
        $otherUserId,
        $_POST['body'] ?? '',
        (int) ($_POST['reply_to_message_id'] ?? 0),
        $isForwarded
    );

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
    $isForwarded = filter_var($_POST['forwarded'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
    $message = sendVoiceMessage(
        (int) $user['id'],
        $otherUserId,
        $_FILES['voice_note'] ?? [],
        (int) ($_POST['reply_to_message_id'] ?? 0),
        $isForwarded
    );

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
    $isForwarded = filter_var($_POST['forwarded'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
    $message = sendFileMessage(
        (int) $user['id'],
        $otherUserId,
        $_FILES['shared_file'] ?? [],
        (int) ($_POST['reply_to_message_id'] ?? 0),
        $isForwarded
    );

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
    $isForwarded = filter_var($_POST['forwarded'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
    $message = sendImageMessage(
        (int) $user['id'],
        $otherUserId,
        $_FILES['image_file'] ?? [],
        (int) ($_POST['reply_to_message_id'] ?? 0),
        $isForwarded
    );

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
