<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = requireAuth();

const SURF_ACTIONS = ['create_session', 'navigate', 'status', 'click', 'type', 'key', 'scroll', 'snapshot'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requireCsrfToken();

    $action = is_string($_POST['action'] ?? null) ? trim((string) $_POST['action']) : 'navigate';
    if ($action === '') {
        $action = 'navigate';
    }

    if (!in_array($action, SURF_ACTIONS, true)) {
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

    if ($action === 'navigate' && $payload['session_id'] === '') {
        $createResponse = surfRunBrowserAction('create_session', $payload);
        if (isset($createResponse['error'])) {
            jsonResponse($createResponse, 502);
        }

        $payload['session_id'] = is_string($createResponse['session_id'] ?? null) ? $createResponse['session_id'] : '';
        if ($payload['session_id'] === '') {
            jsonResponse(['error' => 'Surf browser service did not return a session id.'], 502);
        }
    }

    $result = surfRunBrowserAction($action, $payload);

    if (!isset($result['error']) && $action === 'navigate' && is_string($payload['session_id']) && $payload['session_id'] !== '') {
        $snapshotResponse = surfRunBrowserAction('snapshot', ['session_id' => $payload['session_id']]);
        if (!isset($snapshotResponse['error']) && is_string($snapshotResponse['image_base64'] ?? null)) {
            $result['image_base64'] = $snapshotResponse['image_base64'];
        }
    }

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

    $autoStartInfo = null;
    $socket = @stream_socket_client(sprintf('tcp://%s:%d', $host, $port), $errno, $errstr, 2.5);
    if (!is_resource($socket)) {
        $autoStartInfo = surfTryAutoStartBrowserService();
        $socket = @stream_socket_client(sprintf('tcp://%s:%d', $host, $port), $errno, $errstr, 2.5);
    }

    if (!is_resource($socket)) {
        $response = [
            'error' => 'Surf browser service is unavailable.',
            'hint' => 'Run: node scripts/surf_browser.mjs',
            'details' => $errstr !== '' ? $errstr : ('socket error #' . $errno),
        ];

        if (is_array($autoStartInfo) && $autoStartInfo !== []) {
            $response['autostart'] = $autoStartInfo;
        }

        $recentLog = surfReadRecentBrowserLog();
        if ($recentLog !== null) {
            $response['log_tail'] = $recentLog;
        }

        return [
            ...$response,
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

function surfTryAutoStartBrowserService(): array
{
    static $attempted = false;
    $result = [];

    if ($attempted) {
        return ['status' => 'skipped', 'reason' => 'already_attempted'];
    }
    $attempted = true;

    $autoStart = strtolower(trim(envValue('CHAT_SURF_BROWSER_AUTOSTART', '1')));
    if (in_array($autoStart, ['0', 'false', 'off', 'no'], true)) {
        return ['status' => 'disabled', 'reason' => 'CHAT_SURF_BROWSER_AUTOSTART'];
    }

    $nodeBin = trim(envValue('CHAT_SURF_NODE_BIN', 'node'));
    $script = BASE_PATH . '/scripts/surf_browser.mjs';
    if (!is_file($script)) {
        return ['status' => 'failed', 'reason' => 'missing_script', 'script' => $script];
    }

    $command = sprintf(
        '%s %s > %s 2>&1 &',
        escapeshellcmd($nodeBin),
        escapeshellarg($script),
        escapeshellarg(STORAGE_PATH . '/surf_browser.log')
    );

    $output = [];
    $exitCode = 0;
    @exec($command, $output, $exitCode);
    usleep(250000);

    $result['status'] = $exitCode === 0 ? 'started' : 'failed';
    $result['exit_code'] = $exitCode;
    $result['node'] = $nodeBin;
    $result['script'] = $script;

    return $result;
}

function surfReadRecentBrowserLog(): ?string
{
    $path = STORAGE_PATH . '/surf_browser.log';
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }

    $contents = @file_get_contents($path);
    if (!is_string($contents) || trim($contents) === '') {
        return null;
    }

    $lines = preg_split('/\r\n|\r|\n/', trim($contents));
    if (!is_array($lines) || $lines === []) {
        return null;
    }

    $tail = array_slice($lines, -8);
    return implode("\n", $tail);
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
        body { font-family: system-ui, sans-serif; margin: 0; padding: 12px; background: #f2f4f8; }
        .toolbar { display: flex; gap: 8px; margin-bottom: 10px; }
        .address { flex: 1; border: 1px solid #c8d0da; border-radius: 999px; padding: 10px 14px; font-size: 14px; }
        .go { border: none; border-radius: 999px; padding: 10px 16px; font-weight: 700; background: #0072ff; color: #fff; cursor: pointer; }
        .meta { margin: 0 0 10px; font-size: 13px; color: #445; }
        .card { background: #fff; border-radius: 12px; box-shadow: 0 8px 22px rgba(0,0,0,.08); overflow: hidden; }
        .preview { display: block; width: 100%; height: auto; }
        .error { color: #b00020; }
    </style>
</head>
<body>
<form id="surf-bar" class="toolbar" method="post" autocomplete="off">
    <input id="address" class="address" name="url" type="url" placeholder="Enter URL" value="https://www.google.com" required>
    <input id="session_id" type="hidden" name="session_id" value="">
    <input type="hidden" name="action" value="navigate">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
    <button class="go" type="submit">Go</button>
</form>
<p id="meta" class="meta">Signed in as <strong><?= e((string) $user['username']) ?></strong>.</p>
<div class="card">
    <img id="preview" class="preview" alt="Surf preview" hidden>
</div>
<script>
const form = document.getElementById('surf-bar');
const addressInput = document.getElementById('address');
const sessionInput = document.getElementById('session_id');
const meta = document.getElementById('meta');
const preview = document.getElementById('preview');

async function navigate(url) {
    const body = new URLSearchParams(new FormData(form));
    body.set('url', url);

    const res = await fetch('surf.php', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content,
        },
        body,
    });

    const payload = await res.json().catch(() => ({ error: 'Non-JSON response from surf.php' }));

    if (payload.session_id) {
        sessionInput.value = String(payload.session_id);
    }

    if (payload.error) {
        meta.classList.add('error');
        meta.textContent = payload.error;
        return;
    }

    meta.classList.remove('error');
    const title = payload.title ? String(payload.title) : '';
    const currentUrl = payload.url ? String(payload.url) : url;
    addressInput.value = currentUrl;
    meta.textContent = title ? `${title} — ${currentUrl}` : currentUrl;

    if (payload.image_base64) {
        preview.src = `data:image/png;base64,${payload.image_base64}`;
        preview.hidden = false;
    }
}

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    await navigate(addressInput.value.trim());
});

window.addEventListener('load', async () => {
    await navigate(addressInput.value.trim() || 'https://www.google.com');
});
</script>
</body>
</html>
