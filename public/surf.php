<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = requireAuth();

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Surf Mode</title>
    <style>
        * {
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        *::-webkit-scrollbar {
            width: 0;
            height: 0;
            display: none;
        }
        body { font-family: system-ui, sans-serif; margin: 0; background: #f5f7fb; }
        .app { max-width: 860px; margin: 24px auto; padding: 0 16px; }
        .card { background: #fff; border-radius: 14px; box-shadow: 0 10px 24px rgba(0,0,0,.08); padding: 16px; }
        .title { margin: 0 0 6px; }
        .subtitle { margin: 0 0 16px; color: #4b5563; font-size: 14px; }
        .bar { display: flex; gap: 8px; }
        .url { flex: 1; border: 1px solid #d1d5db; border-radius: 999px; padding: 10px 14px; font-size: 14px; }
        .go { border: 0; border-radius: 999px; background: #1366d6; color: #fff; padding: 10px 16px; font-weight: 700; cursor: pointer; }
        .hint { margin-top: 10px; color: #6b7280; font-size: 12px; }
    </style>
</head>
<body>
<div class="app">
    <div class="card">
        <h1 class="title">Surf Mode</h1>
        <p class="subtitle">Signed in as <strong><?= e((string) $user['username']) ?></strong>. Enter a URL to load via server internet proxy.</p>
        <form id="surf-form" class="bar" autocomplete="off">
            <input id="surf-url" class="url" type="url" value="https://www.google.com" placeholder="https://example.com" required>
            <button class="go" type="submit">Open</button>
        </form>
        <p class="hint">Pages open through this app's server connection.</p>
    </div>
</div>
<script>
const form = document.getElementById('surf-form');
const input = document.getElementById('surf-url');

function normalizeUrl(raw) {
    const value = (raw || '').trim();
    if (value === '') {
        return '';
    }

    const withScheme = /^[a-zA-Z][a-zA-Z\d+.-]*:/.test(value) ? value : `https://${value}`;

    try {
        const parsed = new URL(withScheme);
        return `${parsed.protocol}//${parsed.hostname}`;
    } catch (error) {
        return '';
    }
}

form.addEventListener('submit', (event) => {
    event.preventDefault();
    const next = normalizeUrl(input.value);
    if (!next) {
        input.focus();
        return;
    }

    window.location.assign(`surf_proxy.php?url=${encodeURIComponent(next)}`);
});
</script>
</body>
</html>
