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

if ($requestMethod === 'POST') {
    $action = $_POST['action'] ?? '';
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
