<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = requireAuth();
$currentUserId = (int) $user['id'];
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'GET' && (($_GET['action'] ?? '') === 'signature')) {
    jsonResponse([
        'signature' => chatListStateSignature($currentUserId),
    ]);
}

if ($requestMethod === 'GET' && (($_GET['action'] ?? '') === 'push_notifications')) {
    jsonResponse([
        'ok' => true,
        'payload' => pushNotificationPayload($currentUserId),
    ]);
}

if ($requestMethod === 'POST') {
    requireCsrfToken();

    $action = $_POST['action'] ?? '';

    if ($action === 'save_push_subscription') {
        if (!webPushEnabled()) {
            jsonResponse(['error' => 'Web Push is not available on this server.'], 503);
        }

        $rawSubscription = $_POST['subscription'] ?? null;
        if (!is_string($rawSubscription) || $rawSubscription === '') {
            jsonResponse(['error' => 'Push subscription is required.'], 422);
        }

        try {
            $subscription = json_decode($rawSubscription, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            jsonResponse(['error' => 'Push subscription is invalid.'], 422);
        }

        if (!is_array($subscription) || !savePushSubscription($currentUserId, $subscription)) {
            jsonResponse(['error' => 'Could not save push subscription.'], 422);
        }

        jsonResponse(['ok' => true]);
    }

    if ($action === 'delete_push_subscription') {
        $endpoint = is_string($_POST['endpoint'] ?? null) ? $_POST['endpoint'] : '';
        deletePushSubscription($currentUserId, $endpoint);
        jsonResponse(['ok' => true]);
    }

    if ($action === 'create_group') {
        $error = createGroup($currentUserId, (string) ($_POST['name'] ?? ''));

        if ($error !== null) {
            jsonResponse(['error' => $error], 422);
        }

        jsonResponse([
            'ok' => true,
            'payload' => array_merge(
                chatListPayload($currentUserId),
                ['signature' => chatListStateSignature($currentUserId)]
            ),
        ]);
    }

    if ($action === 'invite_group_member') {
        $groupId = (int) ($_POST['group'] ?? 0);
        $invitedUserId = (int) ($_POST['user'] ?? 0);
        $error = inviteUserToGroup($groupId, $currentUserId, $invitedUserId);

        if ($error !== null) {
            jsonResponse(['error' => $error], 422);
        }

        jsonResponse([
            'ok' => true,
            'payload' => groupConversationPayload($groupId, $currentUserId) + [
                'signature' => groupConversationStateSignature($groupId, $currentUserId),
            ],
        ]);
    }

    $otherUserId = (int) ($_POST['user'] ?? 0);
    $otherUser = $otherUserId > 0 ? findUserById($otherUserId) : null;

    if ($otherUser === null || (int) $otherUser['id'] === $currentUserId) {
        jsonResponse(['error' => 'User not found.'], 404);
    }

    if ($action === 'send_friend_request') {
        $error = sendFriendRequest($currentUserId, $otherUserId);

        if ($error !== null) {
            jsonResponse(['error' => $error], 422);
        }

        jsonResponse([
            'ok' => true,
            'payload' => array_merge(
                chatListPayload($currentUserId),
                ['signature' => chatListStateSignature($currentUserId)]
            ),
        ]);
    }

    if ($action === 'accept_friend_request' || $action === 'reject_friend_request') {
        $error = respondToFriendRequest($currentUserId, $otherUserId, $action === 'accept_friend_request' ? 'accepted' : 'rejected');

        if ($error !== null) {
            jsonResponse(['error' => $error], 422);
        }

        jsonResponse([
            'ok' => true,
            'payload' => array_merge(
                chatListPayload($currentUserId),
                ['signature' => chatListStateSignature($currentUserId)]
            ),
        ]);
    }

    if ($action === 'cancel_friend_request') {
        $error = cancelFriendRequest($currentUserId, $otherUserId);

        if ($error !== null) {
            jsonResponse(['error' => $error], 422);
        }

        jsonResponse([
            'ok' => true,
            'payload' => array_merge(
                chatListPayload($currentUserId),
                ['signature' => chatListStateSignature($currentUserId)]
            ),
        ]);
    }

    if ($action === 'revoke_friendship') {
        $error = revokeFriendship($currentUserId, $otherUserId);

        if ($error !== null) {
            jsonResponse(['error' => $error], 422);
        }

        jsonResponse([
            'ok' => true,
            'payload' => array_merge(
                chatListPayload($currentUserId),
                ['signature' => chatListStateSignature($currentUserId)]
            ),
        ]);
    }

    jsonResponse(['error' => 'Unsupported action.'], 400);
}

jsonResponse(array_merge(
    chatListPayload($currentUserId),
    ['signature' => chatListStateSignature($currentUserId)]
));
