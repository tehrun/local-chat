<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = requireAuth();
$currentUserId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            'payload' => chatListPayload($currentUserId),
        ]);
    }

    if ($action === 'accept_friend_request' || $action === 'reject_friend_request') {
        $error = respondToFriendRequest($currentUserId, $otherUserId, $action === 'accept_friend_request' ? 'accepted' : 'rejected');

        if ($error !== null) {
            jsonResponse(['error' => $error], 422);
        }

        jsonResponse([
            'ok' => true,
            'payload' => chatListPayload($currentUserId),
        ]);
    }

    jsonResponse(['error' => 'Unsupported action.'], 400);
}

jsonResponse(chatListPayload($currentUserId));
