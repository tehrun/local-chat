<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = requireAuth();
$groupId = requirePositiveInt($_GET, 'group');

if ($groupId > 0) {
    if (!canAccessGroupConversation($groupId, (int) $user['id'])) {
        jsonResponse(['error' => 'Group not found.'], 404);
    }

    session_write_close();

    @set_time_limit(0);

    header('Content-Type: text/event-stream; charset=UTF-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('X-Accel-Buffering: no');

    $lastEventId = isset($_SERVER['HTTP_LAST_EVENT_ID']) ? (int) $_SERVER['HTTP_LAST_EVENT_ID'] : 0;
    $eventId = max($lastEventId, 0);
    $lastSignature = null;
    $startedAt = time();

    while (!connection_aborted()) {
        $signature = groupConversationStateSignature($groupId, (int) $user['id']);

        if ($signature !== $lastSignature) {
            $eventId++;
            $lastSignature = $signature;
            $payload = groupConversationPayload($groupId, (int) $user['id']);

            echo 'id: ' . $eventId . "\n";
            echo "event: conversation\n";
            echo 'data: ' . encodeJson($payload) . "\n\n";

            @ob_flush();
            flush();
        } else {
            echo ": keepalive\n\n";
            @ob_flush();
            flush();
        }

        if (time() - $startedAt >= 25) {
            break;
        }

        sleep(1);
    }

    exit;
}

$otherUserId = requirePositiveInt($_GET, 'user');
$otherUser = findUserById($otherUserId);

if ($otherUser === null || (int) $otherUser['id'] === (int) $user['id'] || !canAccessConversation((int) $user['id'], $otherUserId)) {
    jsonResponse(['error' => 'Conversation not found.'], 404);
}

session_write_close();

@set_time_limit(0);

header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');

$lastEventId = isset($_SERVER['HTTP_LAST_EVENT_ID']) ? (int) $_SERVER['HTTP_LAST_EVENT_ID'] : 0;
$eventId = max($lastEventId, 0);
$lastSignature = null;
$startedAt = time();

while (!connection_aborted()) {
    $signature = conversationStateSignature((int) $user['id'], $otherUserId);

    if ($signature !== $lastSignature) {
        $eventId++;
        $lastSignature = $signature;
        $payload = conversationPayload((int) $user['id'], $otherUserId);

        echo 'id: ' . $eventId . "\n";
        echo "event: conversation\n";
        echo 'data: ' . encodeJson($payload) . "\n\n";

        @ob_flush();
        flush();
    } else {
        echo ": keepalive\n\n";
        @ob_flush();
        flush();
    }

    if (time() - $startedAt >= 25) {
        break;
    }

    sleep(1);
}
