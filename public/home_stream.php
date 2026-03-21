<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = requireAuth();

session_write_close();

@set_time_limit(0);

header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');

$lastEventId = isset($_SERVER['HTTP_LAST_EVENT_ID']) ? (int) $_SERVER['HTTP_LAST_EVENT_ID'] : 0;
$eventId = max($lastEventId, 0);
$lastSignature = '';
$startedAt = time();

while (!connection_aborted()) {
    $payload = chatListPayload((int) $user['id']);
    $signature = md5(json_encode($payload, JSON_THROW_ON_ERROR));

    if ($signature !== $lastSignature) {
        $eventId++;
        $lastSignature = $signature;

        echo 'id: ' . $eventId . "\n";
        echo "event: chat-list\n";
        echo 'data: ' . json_encode($payload, JSON_THROW_ON_ERROR) . "\n\n";

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
