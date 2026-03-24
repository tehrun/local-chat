<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = requireAuth();

const SURF_ACTIONS = ['create_session', 'navigate', 'status', 'click', 'type', 'key', 'scroll', 'snapshot'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requireCsrfToken();

    $action = is_string($_POST['action'] ?? null) ? trim((string) $_POST['action']) : '';
    if ($action === '' || !in_array($action, SURF_ACTIONS, true)) {
        jsonResponse(['error' => 'Unsupported action.'], 400);
    }

    $payload = [
        'url' => is_string($_POST['url'] ?? null) ? trim((string) $_POST['url']) : '',
        'selector' => is_string($_POST['selector'] ?? null) ? trim((string) $_POST['selector']) : '',
        'text' => is_string($_POST['text'] ?? null) ? (string) $_POST['text'] : '',
        'key' => is_string($_POST['key'] ?? null) ? trim((string) $_POST['key']) : '',
        'direction' => is_string($_POST['direction'] ?? null) ? trim((string) $_POST['direction']) : 'down',
        'amount' => (int) ($_POST['amount'] ?? 0),
        'session_id' => is_string($_POST['session_id'] ?? null) ? trim((string) $_POST['session_id']) : '',
    ];

    $result = surfRunBrowserAction($action, $payload);
    jsonResponse($result, isset($result['error']) ? 502 : 200);
}

function surfRunBrowserAction(string $action, array $payload): array
{
    $request = [
        'command' => $action,
        'user_id' => (int) (requireAuth()['id'] ?? 0),
        'payload' => $payload,
    ];

    return surfServiceRequest($request);
}

function surfServiceRequest(array $request): array
{
    $address = envValue('CHAT_SURF_BROWSER_ADDR', '127.0.0.1:38555');
    [$host, $portString] = array_pad(explode(':', $address, 2), 2, '38555');
    $port = (int) $portString;

    if ($host === '' || $port < 1 || $port > 65535) {
        return ['error' => 'Surf browser service address is invalid.'];
    }

    $socket = @stream_socket_client(sprintf('tcp://%s:%d', $host, $port), $errno, $errstr, 2.5);
    if (!is_resource($socket)) {
        return [
            'error' => 'Surf browser service is unavailable. Start scripts/surf_browser.mjs first.',
            'details' => $errstr !== '' ? $errstr : ('socket error #' . $errno),
        ];
    }

    stream_set_timeout($socket, 12);
    fwrite($socket, encodeJson($request) . "\n");
    $line = fgets($socket);
    fclose($socket);

    if (!is_string($line) || trim($line) === '') {
        return ['error' => 'Surf browser service returned an empty response.'];
    }

    try {
        $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return ['error' => 'Surf browser service returned malformed JSON.'];
    }

    return is_array($decoded) ? $decoded : ['error' => 'Surf browser service returned an invalid response shape.'];
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrfToken()) ?>">
    <title>Surf Mode</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 24px; }
        form { display: grid; gap: 8px; max-width: 760px; }
        input, button { padding: 8px; font-size: 14px; }
        .hint { color: #555; font-size: 13px; }
    </style>
</head>
<body>
<h1>Surf Mode</h1>
<p class="hint">Authenticated browser automation. Actions are proxied to a long-lived local browser service.</p>
<p class="hint">Signed in as <strong><?= e((string) $user['username']) ?></strong>.</p>
<form id="surf-form" method="post">
    <input name="action" placeholder="action (navigate/click/type/key/scroll/status/snapshot/create_session)" required>
    <input name="session_id" placeholder="session_id (optional for create_session)">
    <input name="url" placeholder="url">
    <input name="selector" placeholder="selector">
    <input name="text" placeholder="text">
    <input name="key" placeholder="key (Enter, Escape, etc.)">
    <input name="direction" placeholder="scroll direction (up/down)" value="down">
    <input name="amount" placeholder="scroll amount" value="640" type="number">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
    <button type="submit">Run</button>
</form>
<pre id="out" style="white-space: pre-wrap; margin-top: 16px;"></pre>
<script>
const form = document.getElementById('surf-form');
const out = document.getElementById('out');
form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const body = new URLSearchParams(new FormData(form));
    const res = await fetch('surf.php', {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content },
        body,
    });
    const payload = await res.json().catch(() => ({ error: 'Non-JSON response from surf.php' }));
    out.textContent = JSON.stringify(payload, null, 2);
    if (payload.session_id) {
        form.querySelector('[name="session_id"]').value = payload.session_id;
    }
});
</script>
</body>
</html>
