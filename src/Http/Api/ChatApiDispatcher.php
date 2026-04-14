<?php

declare(strict_types=1);

namespace LocalChat\Http\Api;

final class ChatApiDispatcher
{
    /**
     * @var array<string, string>
     */
    private array $groupHandlers = [
        'messages' => 'handleGroupMessages',
        'search_messages' => 'handleGroupSearchMessages',
        'signature' => 'handleGroupSignature',
        'typing' => 'handleGroupTyping',
        'read' => 'handleGroupRead',
        'send_text' => 'handleGroupSendText',
        'send_voice' => 'handleGroupSendVoice',
        'send_image' => 'handleGroupSendImage',
        'send_file' => 'handleGroupSendFile',
        'react' => 'handleGroupReact',
        'pin_message' => 'handleGroupPinMessage',
        'unpin_message' => 'handleGroupUnpinMessage',
        'delete_message' => 'handleGroupDeleteMessage',
        'edit_message' => 'handleGroupEditMessage',
        'delete_conversation' => 'handleGroupDeleteConversation',
        'leave_group' => 'handleGroupLeaveGroup',
        'remove_group_member' => 'handleGroupRemoveGroupMember',
        'delete_group' => 'handleGroupDeleteGroup',
        'rename_group' => 'handleGroupRenameGroup',
        'update_group_avatar' => 'handleGroupUpdateGroupAvatar',
    ];

    /**
     * @var array<string, string>
     */
    private array $privateHandlers = [
        'block_user' => 'handlePrivateBlockToggle',
        'unblock_user' => 'handlePrivateBlockToggle',
        'messages' => 'handlePrivateMessages',
        'search_messages' => 'handlePrivateSearchMessages',
        'signature' => 'handlePrivateSignature',
        'delete_message' => 'handlePrivateDeleteMessage',
        'edit_message' => 'handlePrivateEditMessage',
        'delete_conversation' => 'handlePrivateDeleteConversation',
        'revoke_friendship' => 'handlePrivateRevokeFriendship',
        'send_friend_request' => 'handlePrivateSendFriendRequest',
        'react' => 'handlePrivateReact',
        'pin_message' => 'handlePrivatePinMessage',
        'unpin_message' => 'handlePrivateUnpinMessage',
        'typing' => 'handlePrivateTyping',
        'read' => 'handlePrivateRead',
        'send_text' => 'handlePrivateSendText',
        'send_voice' => 'handlePrivateSendVoice',
        'send_file' => 'handlePrivateSendFile',
        'send_image' => 'handlePrivateSendImage',
    ];

    public function dispatch(array $context): array
    {
        $action = (string) ($context['action'] ?? 'messages');
        $isGroupConversation = (bool) ($context['is_group_conversation'] ?? false);

        if ($isGroupConversation) {
            $middlewareResult = $this->runGroupPreChecks($context);
            if ($middlewareResult !== null) {
                return $middlewareResult;
            }

            $handler = $this->groupHandlers[$action] ?? null;
            if ($handler === null) {
                return $this->error('unsupported_action', 'Unsupported action.', 400);
            }

            return $this->{$handler}($context);
        }

        $middlewareResult = $this->runPrivatePreChecks($context);
        if ($middlewareResult !== null) {
            return $middlewareResult;
        }

        $handler = $this->privateHandlers[$action] ?? null;
        if ($handler === null) {
            return $this->error('unsupported_action', 'Unsupported action.', 400);
        }

        return $this->{$handler}($context);
    }

    private function runGroupPreChecks(array $context): ?array
    {
        $groupId = (int) ($context['group_id'] ?? 0);
        $userId = (int) ($context['user']['id'] ?? 0);

        if (!canAccessGroupConversation($groupId, $userId)) {
            return $this->error('not_found', 'Group not found.', 404);
        }

        if ($this->isPostRequest($context)) {
            requireCsrfToken();
        }

        return null;
    }

    private function runPrivatePreChecks(array $context): ?array
    {
        $userId = (int) ($context['user']['id'] ?? 0);
        $otherUserId = (int) ($context['other_user_id'] ?? 0);

        if ($otherUserId <= 0) {
            return $this->error('not_found', 'Conversation not found.', 404);
        }

        $otherUser = findUserById($otherUserId);
        if ($otherUser === null || (int) $otherUser['id'] === $userId || !canAccessConversation($userId, $otherUserId)) {
            return $this->error('not_found', 'Conversation not found.', 404);
        }

        if ($this->isPostRequest($context)) {
            requireCsrfToken();
        }

        return null;
    }

    private function handleGroupMessages(array $context): array
    {
        $groupId = (int) $context['group_id'];
        $userId = (int) $context['user']['id'];
        $limit = max(0, (int) ($_GET['limit'] ?? 0));
        $beforeMessageId = max(0, (int) ($_GET['before'] ?? 0));

        return $this->ok($this->conversationPayloadForGroup($groupId, $userId, $limit, $beforeMessageId));
    }

    private function handleGroupSearchMessages(array $context): array
    {
        $groupId = (int) $context['group_id'];
        $userId = (int) $context['user']['id'];
        $query = is_string($_GET['q'] ?? null) ? $_GET['q'] : (string) ($_POST['q'] ?? '');
        $limit = max(1, min(50, (int) ($_GET['limit'] ?? $_POST['limit'] ?? 20)));
        $beforeMessageId = max(0, (int) ($_GET['before'] ?? $_POST['before'] ?? 0));
        $messages = groupMessageSearchResults($groupId, $userId, $query, $beforeMessageId > 0 ? $beforeMessageId : null, $limit);

        return $this->ok([
            'messages' => $messages,
            'has_more' => count($messages) === $limit,
        ]);
    }

    private function handleGroupSignature(array $context): array
    {
        return $this->ok([
            'signature' => groupConversationStateSignature((int) $context['group_id'], (int) $context['user']['id']),
        ]);
    }

    private function handleGroupTyping(array $context): array
    {
        $typing = filter_var($_POST['typing'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $groupId = (int) $context['group_id'];
        $userId = (int) $context['user']['id'];

        if ($typing) {
            updateGroupTypingStatus($groupId, $userId);
        } else {
            clearGroupTypingStatus($groupId, $userId);
        }

        return $this->ok([]);
    }

    private function handleGroupRead(array $context): array
    {
        $groupId = (int) $context['group_id'];
        $userId = (int) $context['user']['id'];
        markGroupMessagesRead($groupId, $userId);

        return $this->ok($this->conversationPayloadForGroup($groupId, $userId));
    }

    private function handleGroupSendText(array $context): array
    {
        $message = sendGroupTextMessage(
            (int) $context['group_id'],
            (int) $context['user']['id'],
            (string) ($_POST['body'] ?? ''),
            (int) ($_POST['reply_to_message_id'] ?? 0),
            $this->isForwarded()
        );

        return $this->messageSendResponse($context, $message);
    }

    private function handleGroupSendVoice(array $context): array
    {
        $message = sendGroupVoiceMessage(
            (int) $context['group_id'],
            (int) $context['user']['id'],
            $_FILES['voice_note'] ?? [],
            (int) ($_POST['reply_to_message_id'] ?? 0),
            $this->isForwarded()
        );

        return $this->messageSendResponse($context, $message);
    }

    private function handleGroupSendImage(array $context): array
    {
        $message = sendGroupImageMessage(
            (int) $context['group_id'],
            (int) $context['user']['id'],
            $_FILES['image_file'] ?? [],
            (int) ($_POST['reply_to_message_id'] ?? 0),
            $this->isForwarded()
        );

        return $this->messageSendResponse($context, $message);
    }

    private function handleGroupSendFile(array $context): array
    {
        $message = sendGroupFileMessage(
            (int) $context['group_id'],
            (int) $context['user']['id'],
            $_FILES['shared_file'] ?? [],
            (int) ($_POST['reply_to_message_id'] ?? 0),
            $this->isForwarded()
        );

        return $this->messageSendResponse($context, $message);
    }

    private function handleGroupReact(array $context): array
    {
        $groupId = (int) $context['group_id'];
        $userId = (int) $context['user']['id'];
        $result = reactToGroupMessage($groupId, $userId, (int) ($_POST['message_id'] ?? 0), (string) ($_POST['emoji'] ?? ''));

        if (is_string($result)) {
            return $this->error('validation_error', $result, 422);
        }

        return $this->ok([
            'message_id' => (int) ($result['message_id'] ?? 0),
            'signature' => groupConversationStateSignature($groupId, $userId),
        ]);
    }

    private function handleGroupPinMessage(array $context): array
    {
        $error = pinGroupMessage((int) $context['group_id'], (int) $context['user']['id'], (int) ($_POST['message_id'] ?? 0), (int) $context['user']['id']);

        return $this->conversationMutationResponse($context, $error);
    }

    private function handleGroupUnpinMessage(array $context): array
    {
        $error = unpinGroupMessage((int) $context['group_id'], (int) $context['user']['id'], (int) ($_POST['message_id'] ?? 0));

        return $this->conversationMutationResponse($context, $error);
    }

    private function handleGroupDeleteMessage(array $context): array
    {
        $error = deleteGroupMessage((int) $context['group_id'], (int) $context['user']['id'], (int) ($_POST['message_id'] ?? 0));

        return $this->conversationMutationResponse($context, $error);
    }

    private function handleGroupEditMessage(array $context): array
    {
        $error = editGroupMessage((int) $context['group_id'], (int) $context['user']['id'], (int) ($_POST['message_id'] ?? 0), (string) ($_POST['body'] ?? ''));

        return $this->conversationMutationResponse($context, $error);
    }

    private function handleGroupDeleteConversation(array $context): array
    {
        clearGroupConversationForUser((int) $context['group_id'], (int) $context['user']['id']);

        return $this->conversationMutationResponse($context, null);
    }

    private function handleGroupLeaveGroup(array $context): array
    {
        $error = leaveGroup((int) $context['group_id'], (int) $context['user']['id']);
        if ($error !== null) {
            return $this->error('validation_error', $error, 422);
        }

        return $this->ok([]);
    }

    private function handleGroupRemoveGroupMember(array $context): array
    {
        $error = removeGroupMember((int) $context['group_id'], (int) $context['user']['id'], (int) ($_POST['user_id'] ?? 0));

        return $this->conversationMutationResponse($context, $error);
    }

    private function handleGroupDeleteGroup(array $context): array
    {
        $error = deleteGroup((int) $context['group_id'], (int) $context['user']['id']);
        if ($error !== null) {
            return $this->error('validation_error', $error, 422);
        }

        return $this->ok([]);
    }

    private function handleGroupRenameGroup(array $context): array
    {
        $error = renameGroup((int) $context['group_id'], (int) $context['user']['id'], (string) ($_POST['name'] ?? ''));

        return $this->conversationMutationResponse($context, $error);
    }

    private function handleGroupUpdateGroupAvatar(array $context): array
    {
        $error = updateGroupAvatar((int) $context['group_id'], (int) $context['user']['id'], $_FILES['avatar_file'] ?? []);

        return $this->conversationMutationResponse($context, $error);
    }

    private function handlePrivateBlockToggle(array $context): array
    {
        $userId = (int) $context['user']['id'];
        $otherUserId = (int) $context['other_user_id'];
        $action = (string) $context['action'];

        $error = $action === 'block_user' ? blockUser($userId, $otherUserId) : unblockUser($userId, $otherUserId);
        if ($error !== null) {
            return $this->error('validation_error', $error, 422);
        }

        return $this->ok([
            'blocking' => blockingStateBetweenUsers($userId, $otherUserId),
            'payload' => $this->conversationPayloadForPrivate($userId, $otherUserId),
        ]);
    }

    private function handlePrivateMessages(array $context): array
    {
        $userId = (int) $context['user']['id'];
        $otherUserId = (int) $context['other_user_id'];
        $limit = max(0, (int) ($_GET['limit'] ?? 0));
        $beforeMessageId = max(0, (int) ($_GET['before'] ?? 0));

        return $this->ok($this->conversationPayloadForPrivate($userId, $otherUserId, $limit, $beforeMessageId));
    }

    private function handlePrivateSearchMessages(array $context): array
    {
        $userId = (int) $context['user']['id'];
        $otherUserId = (int) $context['other_user_id'];
        $query = is_string($_GET['q'] ?? null) ? $_GET['q'] : (string) ($_POST['q'] ?? '');
        $limit = max(1, min(50, (int) ($_GET['limit'] ?? $_POST['limit'] ?? 20)));
        $beforeMessageId = max(0, (int) ($_GET['before'] ?? $_POST['before'] ?? 0));
        $messages = privateMessageSearchResults($userId, $otherUserId, $query, $beforeMessageId > 0 ? $beforeMessageId : null, $limit);

        return $this->ok([
            'messages' => $messages,
            'has_more' => count($messages) === $limit,
        ]);
    }

    private function handlePrivateSignature(array $context): array
    {
        return $this->ok([
            'signature' => conversationStateSignature((int) $context['user']['id'], (int) $context['other_user_id']),
        ]);
    }

    private function handlePrivateDeleteMessage(array $context): array
    {
        $error = deletePrivateMessage((int) $context['user']['id'], (int) $context['other_user_id'], (int) ($_POST['message_id'] ?? 0));

        return $this->conversationMutationResponse($context, $error);
    }

    private function handlePrivateEditMessage(array $context): array
    {
        $error = editPrivateMessage(
            (int) $context['user']['id'],
            (int) $context['other_user_id'],
            (int) ($_POST['message_id'] ?? 0),
            (string) ($_POST['body'] ?? '')
        );

        return $this->conversationMutationResponse($context, $error);
    }

    private function handlePrivateDeleteConversation(array $context): array
    {
        clearConversationForUser((int) $context['user']['id'], (int) $context['other_user_id']);

        return $this->conversationMutationResponse($context, null);
    }

    private function handlePrivateRevokeFriendship(array $context): array
    {
        $error = revokeFriendship((int) $context['user']['id'], (int) $context['other_user_id']);

        return $this->conversationMutationResponse($context, $error);
    }

    private function handlePrivateSendFriendRequest(array $context): array
    {
        $userId = (int) $context['user']['id'];
        $otherUserId = (int) $context['other_user_id'];
        if (blockingStateBetweenUsers($userId, $otherUserId)['is_blocked']) {
            return $this->error('validation_error', 'Friend requests are unavailable because one of you has blocked the other.', 422);
        }

        $error = sendFriendRequest($userId, $otherUserId);

        return $this->conversationMutationResponse($context, $error);
    }

    private function handlePrivateReact(array $context): array
    {
        $userId = (int) $context['user']['id'];
        $otherUserId = (int) $context['other_user_id'];
        $result = reactToPrivateMessage($userId, $otherUserId, (int) ($_POST['message_id'] ?? 0), (string) ($_POST['emoji'] ?? ''));

        if (is_string($result)) {
            return $this->error('validation_error', $result, 422);
        }

        return $this->ok([
            'message_id' => (int) ($result['message_id'] ?? 0),
            'signature' => conversationStateSignature($userId, $otherUserId),
        ]);
    }

    private function handlePrivatePinMessage(array $context): array
    {
        $error = pinPrivateMessage((int) $context['user']['id'], (int) $context['other_user_id'], (int) ($_POST['message_id'] ?? 0), (int) $context['user']['id']);

        return $this->conversationMutationResponse($context, $error);
    }

    private function handlePrivateUnpinMessage(array $context): array
    {
        $error = unpinPrivateMessage((int) $context['user']['id'], (int) $context['other_user_id'], (int) ($_POST['message_id'] ?? 0));

        return $this->conversationMutationResponse($context, $error);
    }

    private function handlePrivateTyping(array $context): array
    {
        $typing = filter_var($_POST['typing'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $userId = (int) $context['user']['id'];
        $otherUserId = (int) $context['other_user_id'];
        $canChat = canUsersChat($userId, $otherUserId);
        if (!$canChat) {
            return $this->error('forbidden', 'You can only chat after the friend request is accepted.', 403);
        }

        if ($typing) {
            updateTypingStatus($userId, $otherUserId);
        } else {
            clearTypingStatus($userId, $otherUserId);
        }

        return $this->ok([]);
    }

    private function handlePrivateRead(array $context): array
    {
        $userId = (int) $context['user']['id'];
        $otherUserId = (int) $context['other_user_id'];
        $canChat = canUsersChat($userId, $otherUserId);
        if (!$canChat) {
            return $this->error('forbidden', 'You can only chat after the friend request is accepted.', 403);
        }

        markMessagesRead($userId, $otherUserId);

        return $this->ok([
            'messages' => conversationMessagesWithoutMaintenance($userId, $otherUserId),
            'typing' => isUserTypingWithoutMaintenance($userId, $otherUserId),
            'signature' => conversationStateSignature($userId, $otherUserId),
        ]);
    }

    private function handlePrivateSendText(array $context): array
    {
        $chatCheck = $this->ensurePrivateChatAllowed($context);
        if ($chatCheck !== null) {
            return $chatCheck;
        }

        $message = sendTextMessage(
            (int) $context['user']['id'],
            (int) $context['other_user_id'],
            (string) ($_POST['body'] ?? ''),
            (int) ($_POST['reply_to_message_id'] ?? 0),
            $this->isForwarded()
        );

        return $this->messageSendResponse($context, $message);
    }

    private function handlePrivateSendVoice(array $context): array
    {
        $chatCheck = $this->ensurePrivateChatAllowed($context);
        if ($chatCheck !== null) {
            return $chatCheck;
        }

        $message = sendVoiceMessage(
            (int) $context['user']['id'],
            (int) $context['other_user_id'],
            $_FILES['voice_note'] ?? [],
            (int) ($_POST['reply_to_message_id'] ?? 0),
            $this->isForwarded()
        );

        return $this->messageSendResponse($context, $message);
    }

    private function handlePrivateSendFile(array $context): array
    {
        $chatCheck = $this->ensurePrivateChatAllowed($context);
        if ($chatCheck !== null) {
            return $chatCheck;
        }

        $message = sendFileMessage(
            (int) $context['user']['id'],
            (int) $context['other_user_id'],
            $_FILES['shared_file'] ?? [],
            (int) ($_POST['reply_to_message_id'] ?? 0),
            $this->isForwarded()
        );

        return $this->messageSendResponse($context, $message);
    }

    private function handlePrivateSendImage(array $context): array
    {
        $chatCheck = $this->ensurePrivateChatAllowed($context);
        if ($chatCheck !== null) {
            return $chatCheck;
        }

        $message = sendImageMessage(
            (int) $context['user']['id'],
            (int) $context['other_user_id'],
            $_FILES['image_file'] ?? [],
            (int) ($_POST['reply_to_message_id'] ?? 0),
            $this->isForwarded()
        );

        return $this->messageSendResponse($context, $message);
    }

    private function ensurePrivateChatAllowed(array $context): ?array
    {
        $canChat = canUsersChat((int) $context['user']['id'], (int) $context['other_user_id']);
        if (!$canChat) {
            return $this->error('forbidden', 'You can only chat after the friend request is accepted.', 403);
        }

        return null;
    }

    private function conversationMutationResponse(array $context, ?string $error): array
    {
        if ($error !== null) {
            return $this->error('validation_error', $error, 422);
        }

        if ((bool) $context['is_group_conversation']) {
            return $this->ok([
                'payload' => $this->conversationPayloadForGroup((int) $context['group_id'], (int) $context['user']['id']),
            ]);
        }

        return $this->ok([
            'payload' => $this->conversationPayloadForPrivate((int) $context['user']['id'], (int) $context['other_user_id']),
        ]);
    }

    private function messageSendResponse(array $context, mixed $message): array
    {
        if (is_string($message)) {
            return $this->error('validation_error', $message, 422);
        }

        if ((bool) $context['is_group_conversation']) {
            return $this->ok([
                'message' => $message,
                'typing_members' => groupTypingMembersWithoutMaintenance((int) $context['group_id'], (int) $context['user']['id']),
                'signature' => groupConversationStateSignature((int) $context['group_id'], (int) $context['user']['id']),
            ]);
        }

        return $this->ok([
            'message' => $message,
            'typing' => false,
            'signature' => conversationStateSignature((int) $context['user']['id'], (int) $context['other_user_id']),
        ]);
    }

    private function conversationPayloadForGroup(int $groupId, int $userId, int $limit = 0, int $beforeMessageId = 0): array
    {
        $payload = groupConversationPayload($groupId, $userId, $limit, $beforeMessageId > 0 ? $beforeMessageId : null);
        $payload['signature'] = groupConversationStateSignature($groupId, $userId);

        return $payload;
    }

    private function conversationPayloadForPrivate(int $userId, int $otherUserId, int $limit = 0, int $beforeMessageId = 0): array
    {
        $payload = conversationPayload($userId, $otherUserId, $limit, $beforeMessageId > 0 ? $beforeMessageId : null);
        $payload['signature'] = conversationStateSignature($userId, $otherUserId);

        return $payload;
    }

    private function isForwarded(): bool
    {
        return filter_var($_POST['forwarded'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
    }

    private function isPostRequest(array $context): bool
    {
        return (string) ($context['method'] ?? 'GET') === 'POST';
    }

    private function ok(array $payload): array
    {
        return [
            'status' => 200,
            'payload' => array_merge(['ok' => true], $payload),
        ];
    }

    private function error(string $code, string $message, int $status): array
    {
        return [
            'status' => $status,
            'payload' => [
                'ok' => false,
                'error' => [
                    'code' => $code,
                    'message' => $message,
                ],
            ],
        ];
    }
}
