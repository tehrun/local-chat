<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = requireAuth();
$otherUserId = (int) ($_GET['user'] ?? 0);
$otherUser = findUserById($otherUserId);

if ($otherUser === null || (int) $otherUser['id'] === (int) $user['id']) {
    jsonResponse(['error' => 'Conversation not found.'], 404);
}

@set_time_limit(0);

header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');

$lastEventId = isset($_SERVER['HTTP_LAST_EVENT_ID']) ? (int) $_SERVER['HTTP_LAST_EVENT_ID'] : 0;
$eventId = max($lastEventId, 0);
$lastSignature = '';
$startedAt = time();

while (!connection_aborted()) {
    $payload = conversationPayload((int) $user['id'], $otherUserId);
    $signature = md5(json_encode($payload, JSON_THROW_ON_ERROR));

    if ($signature !== $lastSignature) {
        $eventId++;
        $lastSignature = $signature;

        echo 'id: ' . $eventId . "\n";
        echo "event: conversation\n";
        echo 'data: ' . json_encode($payload, JSON_THROW_ON_ERROR) . "\n\n";

        @ob_flush();
        flush();
    }

    if (time() - $startedAt >= 25) {
        break;
    }

    sleep(1);
}
