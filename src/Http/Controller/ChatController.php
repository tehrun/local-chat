<?php

declare(strict_types=1);

namespace LocalChat\Http\Controller;

final class ChatController
{
    public function __invoke(): array
    {
        $user = requireAuth();
        $groupId = requirePositiveInt($_GET, 'group');
        $isGroupConversation = $groupId > 0;
        $otherUserId = $isGroupConversation ? 0 : requirePositiveInt($_GET, 'user');
        $otherUser = null;
        $group = null;
        $friendship = null;
        $blockingState = null;
        $messageBatchSize = 15;
        $typingMembers = [];
        $pinnedMessageIds = [];
        $availableInviteUsers = allOtherUsers((int) $user['id']);

        $uploadMaxFilesizeBytes = $this->parseIniSizeToBytes((string) ini_get('upload_max_filesize'));
        $postMaxSizeBytes = $this->parseIniSizeToBytes((string) ini_get('post_max_size'));
        $serverUploadLimitBytes = min(
            $uploadMaxFilesizeBytes > 0 ? $uploadMaxFilesizeBytes : PHP_INT_MAX,
            $postMaxSizeBytes > 0 ? $postMaxSizeBytes : PHP_INT_MAX
        );
        if ($serverUploadLimitBytes === PHP_INT_MAX) {
            $serverUploadLimitBytes = 5 * 1024 * 1024;
        }
        $clientImageTargetBytes = (int) floor($serverUploadLimitBytes * 0.7);
        $clientImageTargetBytes = max(350 * 1024, min($clientImageTargetBytes, 4_500_000));

        if ($isGroupConversation) {
            $group = findGroupById($groupId);

            if ($group === null || !canAccessGroupConversation($groupId, (int) $user['id'])) {
                header('Location: ./');
                exit;
            }

            $canChat = true;
            $messages = groupMessagesPageWithoutMaintenance($groupId, (int) $user['id'], $messageBatchSize);
            $hasMoreMessages = $messages !== [] && groupConversationHasOlderMessagesWithoutMaintenance($groupId, (int) $user['id'], (int) $messages[0]['id']);
            $typingMembers = groupTypingMembersWithoutMaintenance($groupId, (int) $user['id']);
            $pinnedMessageIds = pinnedGroupMessageIds($groupId, (int) $user['id']);
            $initialConversationSignature = groupConversationStateSignature($groupId, (int) $user['id']);
        } else {
            $otherUser = findUserById($otherUserId);

            if ($otherUser === null || $otherUser['id'] === $user['id'] || !canAccessConversation((int) $user['id'], $otherUserId)) {
                header('Location: ./');
                exit;
            }

            $canChat = canUsersChat((int) $user['id'], $otherUserId);
            $friendship = friendshipRecord((int) $user['id'], $otherUserId);
            $blockingState = blockingStateBetweenUsers((int) $user['id'], $otherUserId);
            $messages = conversationMessagesPageWithoutMaintenance((int) $user['id'], $otherUserId, $messageBatchSize);
            $hasMoreMessages = $messages !== [] && conversationHasOlderMessagesWithoutMaintenance((int) $user['id'], $otherUserId, (int) $messages[0]['id']);
            $typingMembers = $canChat && isUserTyping((int) $user['id'], $otherUserId)
                ? [['user_id' => (int) $otherUser['id'], 'username' => (string) $otherUser['username']]]
                : [];
            $pinnedMessageIds = pinnedPrivateMessageIds((int) $user['id'], $otherUserId);
            $initialConversationSignature = conversationStateSignature((int) $user['id'], $otherUserId);
        }

        return [
            'user' => $user,
            'groupId' => $groupId,
            'isGroupConversation' => $isGroupConversation,
            'otherUserId' => $otherUserId,
            'otherUser' => $otherUser,
            'group' => $group,
            'friendship' => $friendship,
            'blockingState' => $blockingState,
            'messageBatchSize' => $messageBatchSize,
            'typingMembers' => $typingMembers,
            'pinnedMessageIds' => $pinnedMessageIds,
            'availableInviteUsers' => $availableInviteUsers,
            'canChat' => $canChat,
            'messages' => $messages,
            'hasMoreMessages' => $hasMoreMessages,
            'initialConversationSignature' => $initialConversationSignature,
            'clientImageTargetBytes' => $clientImageTargetBytes,
            'bootstrapData' => [
                'currentUserId' => (int) $user['id'],
                'currentUsername' => (string) $user['username'],
                'conversationUserId' => (int) $otherUserId,
                'groupId' => (int) $groupId,
                'isGroupConversation' => $isGroupConversation,
                'conversationDisplayName' => $isGroupConversation ? (string) $group['name'] : $otherUser['username'],
                'messageBatchSize' => (int) $messageBatchSize,
                'initialMessages' => $messages,
                'initialHasMoreMessages' => $hasMoreMessages,
                'initialTypingMembers' => $typingMembers,
                'initialCanChat' => $canChat,
                'initialFriendship' => $friendship,
                'initialBlockingState' => $blockingState,
                'initialGroup' => $group,
                'initialPinnedMessageIds' => $pinnedMessageIds,
                'initialPresence' => !$isGroupConversation && !empty($otherUser['is_online']),
                'initialPresenceUpdatedAt' => $isGroupConversation ? null : ($otherUser['presence_updated_at'] ?? null),
                'preferPolling' => PHP_SAPI === 'cli-server',
                'initialConversationSignature' => $initialConversationSignature,
                'imageUploadTargetBytes' => (int) $clientImageTargetBytes,
                'webPushPublicKey' => webPushPublicKey(),
                'directoryUsersState' => $availableInviteUsers,
            ],
        ];
    }

    private function parseIniSizeToBytes(string $value): int
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return 0;
        }

        $unit = strtolower(substr($trimmed, -1));
        $number = (float) $trimmed;
        $multiplier = match ($unit) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return (int) max(0, round($number * $multiplier));
    }
}
