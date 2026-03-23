<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = requireAuth();

const SURF_VIEWPORT_WIDTH = 390;
const SURF_VIEWPORT_HEIGHT = 844;
const SURF_MAX_ACTION_BODY_BYTES = 32768;

function surfStoragePath(): string
{
    $path = STORAGE_PATH . '/surf';
    if (!is_dir($path)) {
        mkdir($path, 0777, true);
    }

    return $path;
}

function surfSessionDirectory(array $user): string
{
    $id = (string) ($user['id'] ?? 'guest');
    $sessionId = session_id();
    $fingerprint = hash('sha256', $id . '|' . $sessionId);
    $path = surfStoragePath() . '/' . $fingerprint;
    if (!is_dir($path)) {
        mkdir($path, 0777, true);
    }

    return $path;
}

function surfNormalizeUrl(?string $candidate): ?string
{
    if (!is_string($candidate)) {
        return null;
    }

    $candidate = trim($candidate);
    if ($candidate === '') {
        return null;
    }

    if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $candidate)) {
        $candidate = 'https://' . ltrim($candidate, '/');
    }

    return $candidate;
}

function surfViewportFromRequest(): array
{
    $width = isset($_REQUEST['viewport_width']) ? (int) $_REQUEST['viewport_width'] : SURF_VIEWPORT_WIDTH;
    $height = isset($_REQUEST['viewport_height']) ? (int) $_REQUEST['viewport_height'] : SURF_VIEWPORT_HEIGHT;

    return [
        'width' => max(320, min(1600, $width)),
        'height' => max(320, min(2400, $height)),
    ];
}

function surfReadJsonBody(): array
{
    $raw = file_get_contents('php://input', false, null, 0, SURF_MAX_ACTION_BODY_BYTES);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function surfBrowserScriptPath(): string
{
    return BASE_PATH . '/scripts/surf_browser.mjs';
}

function surfResolveNodeBinary(): string
{
    $configured = trim((string) getenv('CHAT_NODE_BINARY'));
    if ($configured !== '') {
        if (is_executable($configured)) {
            return $configured;
        }

        $resolved = shell_exec('command -v ' . escapeshellarg($configured) . ' 2>/dev/null');
        if (is_string($resolved) && trim($resolved) !== '') {
            return trim($resolved);
        }
    }

    foreach (['node', 'nodejs', '/usr/bin/node', '/usr/local/bin/node'] as $candidate) {
        if (str_contains($candidate, '/')) {
            if (is_executable($candidate)) {
                return $candidate;
            }
            continue;
        }

        $resolved = shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null');
        if (is_string($resolved) && trim($resolved) !== '') {
            return trim($resolved);
        }
    }

    throw new RuntimeException('Surf mode requires Node.js. Install Node, or set CHAT_NODE_BINARY to the full path of the node/nodejs executable.');
}

function surfRunBrowserAction(string $action, array $payload, array $user): array
{
    $script = surfBrowserScriptPath();
    if (!is_file($script)) {
        throw new RuntimeException('Surf browser worker is missing.');
    }

    $payload['viewport'] = $payload['viewport'] ?? surfViewportFromRequest();
    $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
    $command = sprintf(
        '%s %s %s %s',
        escapeshellarg(surfResolveNodeBinary()),
        escapeshellarg($script),
        escapeshellarg($action),
        escapeshellarg(surfSessionDirectory($user)),
        escapeshellarg($payloadJson)
    );

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptorSpec, $pipes, BASE_PATH);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start the Surf browser worker.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $decoded = json_decode(is_string($stdout) ? trim($stdout) : '', true);
    if (!is_array($decoded)) {
        throw new RuntimeException(trim((string) $stderr) !== '' ? trim((string) $stderr) : 'Surf browser worker returned an invalid response.');
    }

    if (($decoded['ok'] ?? false) !== true || $exitCode !== 0) {
        $message = (string) ($decoded['error'] ?? trim((string) $stderr) ?: 'Unknown Surf browser error.');
        throw new RuntimeException($message);
    }

    return $decoded;
}

function surfJsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$action = isset($_GET['action']) && is_string($_GET['action']) ? $_GET['action'] : null;

if ($action !== null) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        surfJsonResponse(['ok' => false, 'error' => 'POST is required.'], 405);
    }

    $body = surfReadJsonBody();
    $csrfToken = (string) ($body['csrf_token'] ?? '');
    if (!hash_equals(csrfToken(), $csrfToken)) {
        surfJsonResponse(['ok' => false, 'error' => 'Invalid CSRF token.'], 403);
    }

    try {
        $payload = [
            'viewport' => surfViewportFromRequest(),
        ];

        if ($action === 'navigate') {
            $payload['url'] = surfNormalizeUrl($body['url'] ?? null) ?? 'https://www.google.com/';
        } elseif ($action === 'click') {
            $payload['x'] = (int) ($body['x'] ?? 0);
            $payload['y'] = (int) ($body['y'] ?? 0);
        } elseif ($action === 'type') {
            $payload['text'] = (string) ($body['text'] ?? '');
        } elseif ($action === 'key') {
            $payload['key'] = (string) ($body['key'] ?? '');
        } elseif ($action === 'scroll') {
            $payload['deltaY'] = (int) ($body['deltaY'] ?? 0);
        } elseif ($action !== 'status') {
            surfJsonResponse(['ok' => false, 'error' => 'Unknown action.'], 404);
        }

        $result = surfRunBrowserAction($action, $payload, $user);
        surfJsonResponse($result);
    } catch (Throwable $exception) {
        surfJsonResponse(['ok' => false, 'error' => $exception->getMessage()], 502);
    }
}

$initialUrl = surfNormalizeUrl($_GET['url'] ?? null) ?? 'https://www.google.com/';
$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Surf Mode</title>
    <style>
        :root {
            color-scheme: light;
        }

        * {
            box-sizing: border-box;
        }

        html, body {
            margin: 0;
            min-height: 100%;
            background: #0b141a;
            color: #f5f7fa;
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        body {
            display: flex;
            flex-direction: column;
        }

        .surf-shell {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .surf-toolbar {
            position: sticky;
            top: 0;
            z-index: 20;
            display: flex;
            gap: 10px;
            align-items: center;
            padding: 12px;
            background: rgba(11, 20, 26, 0.92);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(12px);
        }

        .surf-address-form {
            display: flex;
            gap: 8px;
            align-items: center;
            flex: 1;
            min-width: 0;
        }

        .surf-address-input {
            width: 100%;
            min-width: 0;
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 999px;
            padding: 12px 14px;
            background: #111b21;
            color: inherit;
            font: inherit;
        }

        .surf-button,
        .surf-link {
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 999px;
            background: #202c33;
            color: inherit;
            font: inherit;
            font-weight: 600;
            padding: 11px 14px;
            text-decoration: none;
            cursor: pointer;
            white-space: nowrap;
        }

        .surf-button--primary {
            background: #25d366;
            border-color: #25d366;
            color: #0b141a;
        }

        .surf-statusbar {
            display: flex;
            gap: 12px;
            align-items: center;
            justify-content: space-between;
            padding: 8px 12px;
            background: #111b21;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            font-size: 13px;
            color: #c7d1d8;
        }

        .surf-status-text {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .surf-viewer-wrap {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 12px;
        }

        .surf-viewer {
            width: min(100%, 1100px);
            max-height: calc(100vh - 132px);
            object-fit: contain;
            border-radius: 18px;
            background: #fff;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35);
            touch-action: manipulation;
            user-select: none;
        }

        .surf-empty,
        .surf-error {
            max-width: 760px;
            margin: 24px auto;
            padding: 18px 20px;
            border-radius: 16px;
        }

        .surf-empty {
            background: rgba(255, 255, 255, 0.06);
            color: #d8e1e8;
        }

        .surf-error {
            background: rgba(255, 89, 89, 0.14);
            border: 1px solid rgba(255, 89, 89, 0.3);
            color: #ffd5d5;
            display: none;
        }

        .surf-hint {
            padding: 0 12px 12px;
            color: #93a4b0;
            font-size: 12px;
            text-align: center;
        }

        @media (max-width: 720px) {
            .surf-toolbar {
                flex-wrap: wrap;
            }

            .surf-address-form,
            .surf-link {
                width: 100%;
            }

            .surf-viewer {
                width: 100%;
                border-radius: 14px;
            }

            .surf-statusbar {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
<div class="surf-shell">
    <div class="surf-toolbar">
        <form class="surf-address-form" id="surf-address-form">
            <input class="surf-address-input" id="surf-address-input" type="text" value="<?= e($initialUrl) ?>" placeholder="Enter a website URL" inputmode="url" autocomplete="off" spellcheck="false">
            <button class="surf-button surf-button--primary" type="submit">Go</button>
        </form>
        <a class="surf-link" href="index.php">Back to chat</a>
    </div>
    <div class="surf-statusbar">
        <div class="surf-status-text" id="surf-current-url">Starting browser…</div>
        <div id="surf-current-title">Surf Mode</div>
    </div>
    <div class="surf-error" id="surf-error"></div>
    <div class="surf-viewer-wrap">
        <img id="surf-viewer" class="surf-viewer" alt="Surf mode remote browser view">
    </div>
    <div class="surf-hint">Click inside the page to focus it, then type, press Enter, or scroll to interact with the remote browser.</div>
</div>
<script>
const surfState = {
    csrfToken: <?= json_encode($csrf, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    viewer: document.getElementById('surf-viewer'),
    addressInput: document.getElementById('surf-address-input'),
    statusUrl: document.getElementById('surf-current-url'),
    statusTitle: document.getElementById('surf-current-title'),
    errorBox: document.getElementById('surf-error'),
    viewport: { width: window.innerWidth <= 720 ? 390 : 1280, height: window.innerWidth <= 720 ? 844 : 900 },
    activeViewport: { width: 390, height: 844 },
    browserFocused: false,
    busy: false,
};

async function surfAction(action, payload = {}) {
    surfState.busy = true;
    try {
        const response = await fetch(`surf.php?action=${encodeURIComponent(action)}&viewport_width=${surfState.viewport.width}&viewport_height=${surfState.viewport.height}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify({ csrf_token: surfState.csrfToken, ...payload }),
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
            throw new Error(data.error || 'Surf action failed.');
        }
        surfRender(data);
    } catch (error) {
        surfState.errorBox.style.display = 'block';
        surfState.errorBox.textContent = error instanceof Error ? error.message : String(error);
    } finally {
        surfState.busy = false;
    }
}

function surfRender(data) {
    surfState.errorBox.style.display = 'none';
    surfState.errorBox.textContent = '';
    surfState.statusUrl.textContent = data.url || 'about:blank';
    surfState.statusTitle.textContent = data.title || 'Surf Mode';
    surfState.addressInput.value = data.url || surfState.addressInput.value;
    if (data.viewport && data.viewport.width && data.viewport.height) {
        surfState.activeViewport = data.viewport;
    }
    if (data.screenshot) {
        surfState.viewer.src = `data:image/png;base64,${data.screenshot}`;
    }
}

function surfViewerCoordinates(event) {
    const rect = surfState.viewer.getBoundingClientRect();
    const scaleX = surfState.activeViewport.width / rect.width;
    const scaleY = surfState.activeViewport.height / rect.height;
    return {
        x: Math.max(0, Math.round((event.clientX - rect.left) * scaleX)),
        y: Math.max(0, Math.round((event.clientY - rect.top) * scaleY)),
    };
}

document.getElementById('surf-address-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    await surfAction('navigate', { url: surfState.addressInput.value });
});

surfState.viewer.addEventListener('click', async (event) => {
    const point = surfViewerCoordinates(event);
    surfState.browserFocused = true;
    await surfAction('click', point);
});

surfState.viewer.addEventListener('wheel', async (event) => {
    event.preventDefault();
    await surfAction('scroll', { deltaY: event.deltaY });
}, { passive: false });

window.addEventListener('keydown', async (event) => {
    if (!surfState.browserFocused || surfState.busy || event.target === surfState.addressInput) {
        return;
    }

    const specialKeys = new Set(['Enter', 'Backspace', 'Tab', 'Escape', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight']);
    if (specialKeys.has(event.key)) {
        event.preventDefault();
        await surfAction('key', { key: event.key });
        return;
    }

    if (event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
        event.preventDefault();
        await surfAction('type', { text: event.key });
    }
});

window.addEventListener('resize', () => {
    surfState.viewport = {
        width: window.innerWidth <= 720 ? 390 : 1280,
        height: window.innerWidth <= 720 ? 844 : 900,
    };
});

setInterval(() => {
    if (!surfState.busy) {
        surfAction('status');
    }
}, 2500);

surfAction('navigate', { url: surfState.addressInput.value });
</script>
</body>
</html>
