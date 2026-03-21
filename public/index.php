<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

purgeExpiredMessages();

$errors = [];
$notice = null;
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'register') {
        $error = registerUser($_POST['username'] ?? '', $_POST['password'] ?? '');
        if ($error !== null) {
            $errors[] = $error;
        } else {
            $notice = 'Registration successful. Please sign in.';
        }
    }

    if ($action === 'login') {
        $error = loginUser($_POST['username'] ?? '', $_POST['password'] ?? '');
        if ($error !== null) {
            $errors[] = $error;
        } else {
            header('Location: /');
            exit;
        }
    }

    if ($action === 'logout') {
        session_destroy();
        header('Location: /');
        exit;
    }
}

$user = currentUser();
$users = $user ? allOtherUsers((int) $user['id']) : [];
$loginRequired = isset($_GET['login']) && $_GET['login'] === 'required';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#075e54">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Local Chat">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" href="/icons/icon.svg" type="image/svg+xml">
    <title>Local Chat</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #efeae2;
            --panel: #f7f5f1;
            --header: #075e54;
            --composer: #f0f2f5;
            --mine: #dcf8c6;
            --theirs: #ffffff;
            --text: #111b21;
            --muted: #667781;
            --action: #25d366;
            --action-active: #128c7e;
            --danger: #b42318;
            --shadow: 0 10px 30px rgba(17, 27, 33, 0.12);
            font-family: Arial, sans-serif;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text);
        }
        .app {
            min-height: 100vh;
            max-width: 720px;
            margin: 0 auto;
            background: linear-gradient(180deg, #0b141a 0, #0b141a 72px, var(--bg) 72px);
        }
        .shell {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .topbar {
            position: sticky;
            top: 0;
            z-index: 5;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: calc(14px + env(safe-area-inset-top, 0px)) 16px 14px;
            background: var(--header);
            color: #fff;
            box-shadow: var(--shadow);
        }
        .topbar h1 {
            margin: 0;
            font-size: 20px;
        }
        .topbar p {
            margin: 4px 0 0;
            font-size: 13px;
            color: #d7efe8;
        }
        .topbar-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .user-chip,
        .install-button {
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.14);
            font-size: 13px;
            color: #fff;
            white-space: nowrap;
        }
        .install-button {
            border: 1px solid rgba(255,255,255,0.2);
            cursor: pointer;
            font-weight: 700;
        }
        .install-button[hidden] {
            display: none;
        }
        .logout-button {
            border: none;
            border-radius: 999px;
            background: rgba(11, 20, 26, 0.28);
            color: #fff;
            padding: 10px 14px;
            font-weight: 700;
            cursor: pointer;
        }
        .content {
            flex: 1;
            padding: 14px 12px 18px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .card {
            background: rgba(255, 255, 255, 0.88);
            border-radius: 18px;
            box-shadow: 0 2px 6px rgba(17, 27, 33, 0.06);
            padding: 16px;
        }
        .intro-bubble {
            width: 100%;
            max-width: none;
            background: var(--mine);
            margin-left: 0;
        }
        .panel-title {
            margin: 0 0 6px;
            font-size: 18px;
        }
        .panel-text, .meta-text {
            margin: 0;
            color: var(--muted);
            line-height: 1.5;
        }
        .alerts {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .alert {
            border-radius: 14px;
            padding: 12px 14px;
            font-size: 14px;
        }
        .alert.error { background: #fef3f2; color: var(--danger); }
        .alert.notice { background: #ecfdf3; color: #027a48; }
        .chat-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .chat-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px;
            border-radius: 18px;
            background: var(--theirs);
            color: inherit;
            text-decoration: none;
            box-shadow: 0 2px 6px rgba(17, 27, 33, 0.06);
        }
        .avatar {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            background: #dfe5e7;
            color: #244047;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            flex-shrink: 0;
        }
        .presence-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--muted);
            width: fit-content;
        }
        .presence-badge .dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: #98a2b3;
            box-shadow: none;
            flex-shrink: 0;
        }
        .presence-badge .dot.online {
            background: var(--action);
        }
        .chat-copy {
            min-width: 0;
            flex: 1;
        }
        .chat-copy strong {
            font-size: 16px;
            line-height: 1.35;
            display: block;
        }
        .chat-copy-head {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 4px;
            margin-bottom: 0;
        }
        .chat-time {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 24px;
            height: 24px;
            padding: 0 8px;
            border-radius: 999px;
            background: var(--action);
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            line-height: 1;
            white-space: nowrap;
        }
        .chat-time.is-empty {
            background: transparent;
            color: transparent;
            padding: 0;
            min-width: 0;
        }
        .auth-grid {
            display: grid;
            gap: 14px;
        }
        label {
            display: block;
            margin-bottom: 12px;
            font-size: 14px;
            color: var(--muted);
        }
        input {
            width: 100%;
            margin-top: 6px;
            border: none;
            border-radius: 14px;
            padding: 12px 14px;
            font: inherit;
            background: #fff;
        }
        button.primary,
        button.secondary {
            width: 100%;
            border: none;
            border-radius: 999px;
            padding: 12px 16px;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }
        button.primary { background: var(--action); color: #fff; }
        button.secondary { background: #dfe5e7; color: #244047; }
        .helper-row {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            justify-content: center;
            color: var(--muted);
            font-size: 13px;
            padding: 0 8px;
        }
        .helper-row .dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: var(--action);
            flex-shrink: 0;
        }

        .floating-chat-launcher {
            position: fixed;
            right: max(18px, calc(env(safe-area-inset-right, 0px) + 18px));
            bottom: max(18px, calc(env(safe-area-inset-bottom, 0px) + 18px));
            z-index: 20;
            width: 60px;
            height: 60px;
            border: none;
            border-radius: 50%;
            background: var(--action);
            color: #fff;
            box-shadow: 0 12px 26px rgba(37, 211, 102, 0.34);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .floating-chat-launcher svg {
            width: 28px;
            height: 28px;
            stroke: currentColor;
            stroke-width: 2;
            fill: none;
        }
        .floating-chat-launcher[hidden],
        .chat-switcher[hidden] {
            display: none;
        }
        .chat-switcher {
            position: fixed;
            inset: 0;
            z-index: 25;
            background: rgba(11, 20, 26, 0.42);
            display: flex;
            align-items: flex-end;
            justify-content: center;
            padding: 16px;
        }
        .chat-switcher-panel {
            width: min(100%, 420px);
            max-height: min(70vh, 560px);
            background: rgba(247, 245, 241, 0.98);
            border-radius: 24px;
            box-shadow: var(--shadow);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .chat-switcher-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 18px 18px 12px;
        }
        .chat-switcher-header h2 {
            margin: 0;
            font-size: 18px;
        }
        .chat-switcher-close {
            border: none;
            background: #dfe5e7;
            color: #244047;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            cursor: pointer;
        }
        .chat-switcher-list {
            overflow-y: auto;
            padding: 0 14px 14px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        @media (min-width: 721px) {
            .app {
                min-height: 100vh;
                box-shadow: var(--shadow);
            }
        }
    </style>
</head>
<body>
<div class="app">
    <div class="shell">
        <header class="topbar">
            <div>
                <h1>Local Chat</h1>
                <p>Simple private conversations on your local network.</p>
            </div>
            <div class="topbar-actions">
                <?php if ($user !== null): ?>
                    <span class="user-chip"><?= e($user['username']) ?></span>
                    <button class="install-button" id="install-app-button" type="button" hidden>Install app</button>
                    <form method="post">
                        <input type="hidden" name="action" value="logout">
                        <button class="logout-button" type="submit">Log out</button>
                    </form>
                <?php endif; ?>
            </div>
        </header>

        <main class="content">
            <?php if ($loginRequired): ?>
                <div class="alert error">Please sign in to open a conversation.</div>
            <?php endif; ?>

            <?php foreach ($errors as $error): ?>
                <div class="alert error"><?= e($error) ?></div>
            <?php endforeach; ?>

            <?php if ($notice !== null): ?>
                <div class="alert notice"><?= e($notice) ?></div>
            <?php endif; ?>

            <div class="card intro-bubble">
                <h2 class="panel-title">Welcome</h2>
                <p class="panel-text">Send text messages and voice notes, see who is online, and keep chats automatically cleaned up after 24 hours.</p>
            </div>

            <?php if ($user === null): ?>
                <section class="auth-grid">
                    <div class="card">
                        <h2 class="panel-title">Create account</h2>
                        <form method="post">
                            <input type="hidden" name="action" value="register">
                            <label>
                                Username
                                <input type="text" name="username" minlength="3" required>
                            </label>
                            <label>
                                Password
                                <input type="password" name="password" minlength="6" required>
                            </label>
                            <button class="primary" type="submit">Register</button>
                        </form>
                    </div>

                    <div class="card">
                        <h2 class="panel-title">Sign in</h2>
                        <form method="post">
                            <input type="hidden" name="action" value="login">
                            <label>
                                Username
                                <input type="text" name="username" required>
                            </label>
                            <label>
                                Password
                                <input type="password" name="password" required>
                            </label>
                            <button class="secondary" type="submit">Login</button>
                        </form>
                    </div>
                </section>
            <?php else: ?>
                <section class="chat-list" id="chat-list">
                    <?php if ($users === []): ?>
                        <div class="card" id="chat-list-empty">
                            <h2 class="panel-title">No chats yet</h2>
                            <p class="panel-text">You will see people here after you have exchanged messages with them.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($users as $chatUser): ?>
                            <?php $unseenCount = (int) ($chatUser['unseen_count'] ?? 0); ?>
                            <a class="chat-item" data-chat-user-id="<?= (int) $chatUser['id'] ?>" href="/chat.php?user=<?= (int) $chatUser['id'] ?>">
                                <div class="avatar"><?= e(strtoupper(substr((string) $chatUser['username'], 0, 2))) ?></div>
                                <div class="chat-copy">
                                    <div class="chat-copy-head">
                                        <strong><?= e($chatUser['username']) ?></strong>
                                        <span class="presence-badge">
                                            <span class="dot <?= !empty($chatUser['is_online']) ? 'online' : '' ?>" data-role="presence-dot" aria-hidden="true"></span>
                                            <span data-role="presence-label"><?= e($chatUser['presence_label'] ?? 'Offline') ?></span>
                                        </span>
                                    </div>
                                </div>
                                <span class="chat-time<?= $unseenCount > 0 ? '' : ' is-empty' ?>" data-role="unseen-count"<?= $unseenCount > 0 ? '' : ' aria-hidden="true"' ?>><?= $unseenCount > 0 ? $unseenCount : '' ?></span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <div class="helper-row">
                <span class="dot"></span>
                <span>Now installable on supported devices and browsers.</span>
            </div>
        </main>
    </div>
</div>

<?php if ($user !== null): ?>
    <button class="floating-chat-launcher" id="chat-switcher-toggle" type="button" aria-label="Open chats">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <circle cx="11" cy="11" r="6"></circle>
            <path d="m20 20-4.35-4.35"></path>
        </svg>
    </button>
    <div class="chat-switcher" id="chat-switcher" hidden>
        <div class="chat-switcher-panel" role="dialog" aria-modal="true" aria-labelledby="chat-switcher-title">
            <div class="chat-switcher-header">
                <h2 id="chat-switcher-title">Your chats</h2>
                <button class="chat-switcher-close" id="chat-switcher-close" type="button" aria-label="Close chat list">×</button>
            </div>
            <div class="chat-switcher-list" id="chat-switcher-list"></div>
        </div>
    </div>
<?php endif; ?>
<script>
const currentUserId = <?= $user !== null ? (int) $user['id'] : 'null' ?>;
const initialChatUsers = <?= json_encode($users, JSON_THROW_ON_ERROR) ?>;
const preferPolling = <?= PHP_SAPI === 'cli-server' ? 'true' : 'false' ?>;
const chatListEl = document.getElementById('chat-list');
let chatListStream = null;
let chatListReconnectTimer = null;
let chatListPollTimer = null;
let chatListSignature = '';
let deferredInstallPrompt = null;
const installButton = document.getElementById('install-app-button');

const chatSwitcherToggle = document.getElementById('chat-switcher-toggle');
const chatSwitcherEl = document.getElementById('chat-switcher');
const chatSwitcherListEl = document.getElementById('chat-switcher-list');
const chatSwitcherClose = document.getElementById('chat-switcher-close');

function renderChatSwitcher(users) {
    if (!chatSwitcherListEl) {
        return;
    }

    if (!Array.isArray(users) || users.length === 0) {
        chatSwitcherListEl.innerHTML = `
            <div class="card">
                <h2 class="panel-title">No chats yet</h2>
                <p class="panel-text">You will see people here after you have exchanged messages with them.</p>
            </div>`;
        return;
    }

    chatSwitcherListEl.innerHTML = users.map((chatUser) => {
        const userId = Number(chatUser.id);
        const avatar = escapeHtml(String(chatUser.username || '').slice(0, 2).toUpperCase());
        const presenceLabel = escapeHtml(chatUser.presence_label || 'Offline');
        const username = escapeHtml(chatUser.username || '');
        const presenceClass = chatUser.is_online ? ' online' : '';

        return `
            <a class="chat-item" href="/chat.php?user=${userId}">
                <div class="avatar">${avatar}</div>
                <div class="chat-copy">
                    <div class="chat-copy-head">
                        <strong>${username}</strong>
                        <span class="presence-badge">
                            <span class="dot${presenceClass}" aria-hidden="true"></span>
                            <span>${presenceLabel}</span>
                        </span>
                    </div>
                </div>
            </a>`;
    }).join('');
}

function setChatSwitcherOpen(isOpen) {
    if (!chatSwitcherEl) {
        return;
    }

    chatSwitcherEl.hidden = !isOpen;
    document.body.style.overflow = isOpen ? 'hidden' : '';
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function renderChatList(users) {
    if (!chatListEl) {
        return;
    }

    if (!Array.isArray(users) || users.length === 0) {
        chatListEl.innerHTML = `
            <div class="card" id="chat-list-empty">
                <h2 class="panel-title">No chats yet</h2>
                <p class="panel-text">You will see people here after you have exchanged messages with them.</p>
            </div>`;
        return;
    }

    chatListEl.innerHTML = users.map((chatUser) => {
        const userId = Number(chatUser.id);
        const unseenCount = Number(chatUser.unseen_count || 0);
        const avatar = escapeHtml(String(chatUser.username || '').slice(0, 2).toUpperCase());
        const presenceLabel = escapeHtml(chatUser.presence_label || 'Offline');
        const username = escapeHtml(chatUser.username || '');
        const presenceClass = chatUser.is_online ? ' online' : '';
        const countClass = unseenCount > 0 ? '' : ' is-empty';
        const hiddenAttr = unseenCount > 0 ? '' : ' aria-hidden="true"';
        const countText = unseenCount > 0 ? String(unseenCount) : '';

        return `
            <a class="chat-item" data-chat-user-id="${userId}" href="/chat.php?user=${userId}">
                <div class="avatar">${avatar}</div>
                <div class="chat-copy">
                    <div class="chat-copy-head">
                        <strong>${username}</strong>
                        <span class="presence-badge">
                            <span class="dot${presenceClass}" data-role="presence-dot" aria-hidden="true"></span>
                            <span data-role="presence-label">${presenceLabel}</span>
                        </span>
                    </div>
                </div>
                <span class="chat-time${countClass}" data-role="unseen-count"${hiddenAttr}>${countText}</span>
            </a>`;
    }).join('');
}

function applyChatListPayload(payload) {
    const users = Array.isArray(payload?.users) ? payload.users : [];
    const signature = JSON.stringify(users);

    if (signature === chatListSignature) {
        return;
    }

    chatListSignature = signature;
    renderChatList(users);
    renderChatSwitcher(users);
}

async function fetchChatList() {
    const response = await fetch('/home_api.php', {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        cache: 'no-store',
    });

    if (!response.ok) {
        throw new Error(`Chat list request failed with ${response.status}`);
    }

    return response.json();
}

function scheduleChatListPoll(delay = 4000) {
    window.clearTimeout(chatListPollTimer);
    chatListPollTimer = window.setTimeout(async () => {
        try {
            const payload = await fetchChatList();
            applyChatListPayload(payload);
            scheduleChatListPoll(4000);
        } catch (error) {
            scheduleChatListPoll(6000);
        }
    }, delay);
}

function connectChatListStream() {
    if (!currentUserId || preferPolling || typeof EventSource === 'undefined') {
        scheduleChatListPoll(0);
        return;
    }

    if (chatListStream) {
        chatListStream.close();
    }

    chatListStream = new EventSource('/home_stream.php');

    chatListStream.addEventListener('chat-list', (event) => {
        try {
            applyChatListPayload(JSON.parse(event.data));
        } catch (error) {
            // Ignore malformed stream payloads.
        }
    });

    chatListStream.onerror = () => {
        chatListStream?.close();
        chatListStream = null;
        window.clearTimeout(chatListReconnectTimer);
        chatListReconnectTimer = window.setTimeout(connectChatListStream, 1500);
    };
}

applyChatListPayload({ users: initialChatUsers });
connectChatListStream();

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // Ignore service worker registration errors.
        });
    });
}

window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    deferredInstallPrompt = event;
    if (installButton) {
        installButton.hidden = false;
    }
});

window.addEventListener('appinstalled', () => {
    deferredInstallPrompt = null;
    if (installButton) {
        installButton.hidden = true;
    }
});

chatSwitcherToggle?.addEventListener('click', () => setChatSwitcherOpen(true));
chatSwitcherClose?.addEventListener('click', () => setChatSwitcherOpen(false));
chatSwitcherEl?.addEventListener('click', (event) => {
    if (event.target === chatSwitcherEl) {
        setChatSwitcherOpen(false);
    }
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        setChatSwitcherOpen(false);
    }
});

installButton?.addEventListener('click', async () => {
    if (!deferredInstallPrompt) {
        return;
    }

    deferredInstallPrompt.prompt();
    await deferredInstallPrompt.userChoice;
    deferredInstallPrompt = null;
    installButton.hidden = true;
});
</script>
</body>
</html>
