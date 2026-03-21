<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

purgeExpiredMessages();

$errors = [];
$notice = null;
$user = currentUser();
$authMode = (isset($_GET['auth']) && $_GET['auth'] === 'register') ? 'register' : 'login';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'register') {
        $authMode = 'register';
        $error = registerUser($_POST['username'] ?? '', $_POST['password'] ?? '');
        if ($error !== null) {
            $errors[] = $error;
        } else {
            $notice = 'Registration successful. Please sign in.';
            $authMode = 'login';
        }
    }

    if ($action === 'login') {
        $authMode = 'login';
        $error = loginUser($_POST['username'] ?? '', $_POST['password'] ?? '');
        if ($error !== null) {
            $errors[] = $error;
        } else {
            header('Location: index.php');
            exit;
        }
    }

    if ($action === 'logout') {
        session_destroy();
        header('Location: index.php');
        exit;
    }
}

$user = currentUser();
$users = $user ? allOtherUsers((int) $user['id']) : [];
$chatUsers = $user ? chattedUsers((int) $user['id']) : [];
$incomingRequests = $user ? incomingFriendRequests((int) $user['id']) : [];
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
    <link rel="manifest" href="manifest.json">
    <link rel="icon" href="icons/icon.svg" type="image/svg+xml">
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
        .request-list,
        .chat-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .user-row-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .mini-button {
            border: none;
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }
        .mini-button.primary { background: var(--action); color: #fff; }
        .mini-button.secondary { background: #dfe5e7; color: #244047; }
        .mini-button.danger { background: #fef3f2; color: var(--danger); }
        .mini-button.icon-button {
            width: 36px;
            height: 36px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .mini-button.icon-button svg {
            width: 18px;
            height: 18px;
        }
        .mini-button.danger.icon-button {
            background: #fdecec;
            color: var(--danger);
        }
        .request-actions {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }
        .mini-button:disabled { opacity: 0.65; cursor: wait; }
        .request-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px;
            border-radius: 18px;
            background: var(--theirs);
            box-shadow: 0 2px 6px rgba(17, 27, 33, 0.06);
        }
        .request-meta { min-width: 0; flex: 1; }
        .request-meta strong { display: block; margin-bottom: 4px; }
        .request-copy { margin: 6px 0 0; font-size: 13px; color: var(--muted); }
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
        .chat-item[role="link"] {
            cursor: pointer;
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
            transition: background-color 0.18s ease, color 0.18s ease, box-shadow 0.18s ease;
        }
        button.primary { background: var(--action); color: #fff; }
        button.secondary { background: #dfe5e7; color: #244047; }
        .auth-submit-ready {
            background: var(--action) !important;
            color: #fff !important;
            box-shadow: 0 8px 18px rgba(37, 211, 102, 0.24);
        }
        .auth-switch {
            margin-top: 14px;
            text-align: center;
            font-size: 14px;
            color: var(--muted);
        }
        .auth-switch a {
            color: var(--header);
            font-weight: 700;
            text-decoration: none;
        }
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
                        <?php if ($authMode === 'register'): ?>
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
                            <p class="auth-switch">Already have an account? <a href="index.php">Sign in</a></p>
                        <?php else: ?>
                            <h2 class="panel-title">Sign in</h2>
                            <form method="post" id="login-form">
                                <input type="hidden" name="action" value="login">
                                <label>
                                    Username
                                    <input type="text" name="username" required data-login-field="username">
                                </label>
                                <label>
                                    Password
                                    <input type="password" name="password" required data-login-field="password">
                                </label>
                                <button class="secondary" id="login-submit" type="submit">Login</button>
                            </form>
                            <p class="auth-switch">Don&apos;t have an account? <a href="index.php?auth=register">Create one</a></p>
                        <?php endif; ?>
                    </div>
                </section>
            <?php else: ?>
                <section class="request-list" id="friend-request-list">
                    <?php if ($incomingRequests !== []): ?>
                        <?php foreach ($incomingRequests as $request): ?>
                            <div class="request-card" data-request-user-id="<?= (int) $request['sender_id'] ?>">
                                <div class="avatar"><?= e(strtoupper(substr((string) $request['sender_name'], 0, 2))) ?></div>
                                <div class="request-meta">
                                    <strong><?= e($request['sender_name']) ?></strong>
                                    <span class="presence-badge">
                                        <span class="dot <?= !empty($request['is_online']) ? 'online' : '' ?>" aria-hidden="true"></span>
                                        <span><?= e($request['presence_label'] ?? 'Offline') ?></span>
                                    </span>
                                    <p class="request-copy"><?= e($request['sender_name']) ?> wants to add you as a friend.</p>
                                </div>
                                <div class="request-actions" aria-label="Friend request actions">
                                    <button class="mini-button primary icon-button" type="button" data-request-action="accept_friend_request" data-user-id="<?= (int) $request['sender_id'] ?>" aria-label="Accept friend request" title="Accept friend request">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                            <path d="M20 6 9 17l-5-5"></path>
                                        </svg>
                                    </button>
                                    <button class="mini-button danger icon-button" type="button" data-request-action="reject_friend_request" data-user-id="<?= (int) $request['sender_id'] ?>" aria-label="Reject friend request" title="Reject friend request">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                            <path d="M18 6 6 18"></path>
                                            <path d="m6 6 12 12"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>

                <section class="chat-list" id="chat-list">
                    <?php if ($chatUsers === []): ?>
                        <div class="card" id="chat-list-empty">
                            <h2 class="panel-title">No chats yet</h2>
                            <p class="panel-text">Start a conversation from the new chat button to see it here.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($chatUsers as $chatUser): ?>
                            <?php $unseenCount = (int) ($chatUser['unseen_count'] ?? 0); ?>
                            <a class="chat-item" data-chat-user-id="<?= (int) $chatUser['id'] ?>" href="chat.php?user=<?= (int) $chatUser['id'] ?>">
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
    <button class="floating-chat-launcher" id="chat-switcher-toggle" type="button" aria-label="Start a new chat">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M12 20.25c4.97 0 9-3.53 9-7.88s-4.03-7.87-9-7.87-9 3.52-9 7.87c0 2.2 1.03 4.18 2.68 5.61L4.5 21l4.1-1.78a10.3 10.3 0 0 0 3.4.53Z"></path>
            <path d="M12 9v6"></path>
            <path d="M9 12h6"></path>
        </svg>
    </button>
    <div class="chat-switcher" id="chat-switcher" hidden>
        <div class="chat-switcher-panel" role="dialog" aria-modal="true" aria-labelledby="chat-switcher-title">
            <div class="chat-switcher-header">
                <h2 id="chat-switcher-title">All users</h2>
                <button class="chat-switcher-close" id="chat-switcher-close" type="button" aria-label="Close user list">×</button>
            </div>
            <div class="chat-switcher-list" id="chat-switcher-list"></div>
        </div>
    </div>
<?php endif; ?>
<script>
const currentUserId = <?= $user !== null ? (int) $user['id'] : 'null' ?>;
const initialChatUsers = <?= json_encode($chatUsers, JSON_THROW_ON_ERROR) ?>;
const initialDirectoryUsers = <?= json_encode($users, JSON_THROW_ON_ERROR) ?>;
const initialIncomingRequests = <?= json_encode($incomingRequests, JSON_THROW_ON_ERROR) ?>;
const preferPolling = <?= PHP_SAPI === 'cli-server' ? 'true' : 'false' ?>;
const chatListEl = document.getElementById('chat-list');
const friendRequestListEl = document.getElementById('friend-request-list');
let chatListStream = null;
let chatListReconnectTimer = null;
let chatListPollTimer = null;
let chatListSignature = '';
let directorySignature = '';
let requestSignature = '';
let seenIncomingRequestIds = new Set(initialIncomingRequests.map((request) => String(request.id || request.sender_id)));
let deferredInstallPrompt = null;
const installButton = document.getElementById('install-app-button');

const chatSwitcherToggle = document.getElementById('chat-switcher-toggle');
const chatSwitcherEl = document.getElementById('chat-switcher');
const chatSwitcherListEl = document.getElementById('chat-switcher-list');
const chatSwitcherClose = document.getElementById('chat-switcher-close');
const loginForm = document.getElementById('login-form');
const loginSubmitButton = document.getElementById('login-submit');
const loginUsernameInput = loginForm?.querySelector('[data-login-field="username"]') || null;
const loginPasswordInput = loginForm?.querySelector('[data-login-field="password"]') || null;

let notificationPermissionRequested = false;
let notificationPermissionPromptDismissed = false;
let hasInteracted = false;
let lastUnseenCounts = new Map(initialChatUsers.map((chatUser) => [String(chatUser.id), Number(chatUser.unseen_count || 0)]));

const personPlusIcon = `
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        <path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"></path>
        <circle cx="9.5" cy="7" r="4"></circle>
        <path d="M19 8v6"></path>
        <path d="M22 11h-6"></path>
    </svg>`;
const acceptIcon = `
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        <path d="M20 6 9 17l-5-5"></path>
    </svg>`;
const rejectIcon = `
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        <path d="M18 6 6 18"></path>
        <path d="m6 6 12 12"></path>
    </svg>`;

function updateLoginButtonState() {
    if (!loginSubmitButton) {
        return;
    }

    const hasUsername = Boolean(loginUsernameInput && loginUsernameInput.value.trim() !== '');
    const hasPassword = Boolean(loginPasswordInput && loginPasswordInput.value.trim() !== '');
    loginSubmitButton.classList.toggle('auth-submit-ready', hasUsername && hasPassword);
}

function markUserInteraction() {
    hasInteracted = true;
    requestNotificationPermission();
}

function requestNotificationPermission() {
    if (notificationPermissionRequested || notificationPermissionPromptDismissed || !('Notification' in window) || Notification.permission !== 'default') {
        return;
    }

    const accepted = window.confirm('Would you like to receive push notifications for new activity in Local Chat?');
    if (!accepted) {
        notificationPermissionPromptDismissed = true;
        return;
    }

    notificationPermissionRequested = true;
    Notification.requestPermission().catch(() => {
        // Ignore notification permission errors.
    });
}

async function playNotificationSound() {
    if (!hasInteracted) {
        return;
    }

    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextClass) {
        return;
    }

    try {
        const audioContext = new AudioContextClass();
        if (audioContext.state === 'suspended') {
            await audioContext.resume();
        }

        const oscillator = audioContext.createOscillator();
        const gainNode = audioContext.createGain();
        const startAt = audioContext.currentTime + 0.01;
        const endAt = startAt + 0.18;

        oscillator.type = 'sine';
        oscillator.frequency.setValueAtTime(932.33, startAt);
        gainNode.gain.setValueAtTime(0.0001, startAt);
        gainNode.gain.exponentialRampToValueAtTime(0.08, startAt + 0.03);
        gainNode.gain.exponentialRampToValueAtTime(0.0001, endAt);

        oscillator.connect(gainNode);
        gainNode.connect(audioContext.destination);
        oscillator.start(startAt);
        oscillator.stop(endAt);

        window.setTimeout(() => {
            audioContext.close().catch(() => {
                // Ignore audio context close errors.
            });
        }, 400);
    } catch (error) {
        // Ignore audio playback errors.
    }
}

function friendshipActionMarkup(chatUser) {
    const userId = Number(chatUser.id);
    const status = String(chatUser.friendship_status || 'none');
    const direction = chatUser.request_direction || '';

    if (chatUser.can_chat) {
        return '';
    }

    if (status === 'pending' && direction === 'incoming') {
        return `
            <button class="mini-button primary icon-button" type="button" data-request-action="accept_friend_request" data-user-id="${userId}" aria-label="Accept friend request" title="Accept friend request">${acceptIcon}</button>
            <button class="mini-button danger icon-button" type="button" data-request-action="reject_friend_request" data-user-id="${userId}" aria-label="Reject friend request" title="Reject friend request">${rejectIcon}</button>`;
    }

    if (status === 'pending') {
        return '<span class="mini-button secondary" aria-disabled="true">Request sent</span>';
    }

    return `<button class="mini-button primary icon-button" type="button" data-request-action="send_friend_request" data-user-id="${userId}" aria-label="Add as friend" title="Add as friend">${personPlusIcon}</button>`;
}

function renderDirectoryEntries(users, includeUnseenCount) {
    if (!Array.isArray(users) || users.length === 0) {
        return '';
    }

    return users.map((chatUser) => {
        const userId = Number(chatUser.id);
        const unseenCount = Number(chatUser.unseen_count || 0);
        const avatar = escapeHtml(String(chatUser.username || '').slice(0, 2).toUpperCase());
        const presenceLabel = escapeHtml(chatUser.presence_label || 'Offline');
        const username = escapeHtml(chatUser.username || '');
        const presenceClass = chatUser.is_online ? ' online' : '';
        const countClass = unseenCount > 0 ? '' : ' is-empty';
        const hiddenAttr = unseenCount > 0 ? '' : ' aria-hidden="true"';
        const countMarkup = includeUnseenCount
            ? `<span class="chat-time${countClass}" data-role="unseen-count"${hiddenAttr}>${unseenCount > 0 ? String(unseenCount) : ''}</span>`
            : '';
        const openChatAttrs = chatUser.can_chat
            ? ` role="link" tabindex="0" data-open-chat-url="chat.php?user=${userId}" aria-label="Open chat with ${username}"`
            : '';

        return `
            <div class="chat-item" data-chat-user-id="${userId}"${openChatAttrs}>
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
                <div class="user-row-actions">${friendshipActionMarkup(chatUser)}${countMarkup}</div>
            </div>`;
    }).join('');
}

function renderChatListEntries(users) {
    if (!Array.isArray(users) || users.length === 0) {
        return '';
    }

    return users.map((chatUser) => {
        const userId = Number(chatUser.id);
        const unseenCount = Number(chatUser.unseen_count || 0);
        const avatar = escapeHtml(String(chatUser.username || '').slice(0, 2).toUpperCase());
        const presenceLabel = escapeHtml(chatUser.presence_label || 'Offline');
        const username = escapeHtml(chatUser.username || '');
        const presenceClass = chatUser.is_online ? ' online' : '';
        const countClass = unseenCount > 0 ? '' : ' is-empty';
        const hiddenAttr = unseenCount > 0 ? '' : ' aria-hidden="true"';

        return `
            <a class="chat-item" data-chat-user-id="${userId}" href="chat.php?user=${userId}">
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
                <span class="chat-time${countClass}" data-role="unseen-count"${hiddenAttr}>${unseenCount > 0 ? String(unseenCount) : ''}</span>
            </a>`;
    }).join('');
}

function renderIncomingRequests(requests) {
    if (!friendRequestListEl) {
        return;
    }

    if (!Array.isArray(requests) || requests.length === 0) {
        friendRequestListEl.innerHTML = '';
        return;
    }

    friendRequestListEl.innerHTML = requests.map((request) => {
        const userId = Number(request.sender_id);
        const avatar = escapeHtml(String(request.sender_name || '').slice(0, 2).toUpperCase());
        const presenceLabel = escapeHtml(request.presence_label || 'Offline');
        const senderName = escapeHtml(request.sender_name || 'Unknown');
        const presenceClass = request.is_online ? ' online' : '';
        return `
            <div class="request-card" data-request-user-id="${userId}">
                <div class="avatar">${avatar}</div>
                <div class="request-meta">
                    <strong>${senderName}</strong>
                    <span class="presence-badge">
                        <span class="dot${presenceClass}" aria-hidden="true"></span>
                        <span>${presenceLabel}</span>
                    </span>
                    <p class="request-copy">${senderName} wants to add you as a friend.</p>
                </div>
                <div class="request-actions" aria-label="Friend request actions">
                    <button class="mini-button primary icon-button" type="button" data-request-action="accept_friend_request" data-user-id="${userId}" aria-label="Accept friend request" title="Accept friend request">${acceptIcon}</button>
                    <button class="mini-button danger icon-button" type="button" data-request-action="reject_friend_request" data-user-id="${userId}" aria-label="Reject friend request" title="Reject friend request">${rejectIcon}</button>
                </div>
            </div>`;
    }).join('');
}

async function showFriendRequestNotification(request) {
    if (!request || document.visibilityState === 'visible') {
        return;
    }

    await playNotificationSound();

    if (!('Notification' in window) || Notification.permission !== 'granted') {
        return;
    }

    const registration = await navigator.serviceWorker.getRegistration().catch(() => null);
    if (!registration) {
        return;
    }

    registration.showNotification('New friend request', {
        body: `${request.sender_name || 'Someone'} wants to add you as a friend.`,
        icon: 'icons/icon.svg',
        tag: `friend-request-${request.id || request.sender_id}`,
        renotify: true,
        data: { url: 'index.php' },
    }).catch(() => {
        // Ignore notification errors.
    });
}

async function showUnreadMessageNotification(chatUser, increaseCount) {
    if (!chatUser || increaseCount <= 0) {
        return;
    }

    await playNotificationSound();

    if (document.visibilityState === 'visible' || !('Notification' in window) || Notification.permission !== 'granted') {
        return;
    }

    const registration = await navigator.serviceWorker.getRegistration().catch(() => null);
    if (!registration) {
        return;
    }

    const username = chatUser.username || 'Someone';
    const body = increaseCount === 1
        ? `${username} sent you a new message.`
        : `${username} sent you ${increaseCount} new messages.`;

    registration.showNotification('New message', {
        body,
        icon: 'icons/icon.svg',
        tag: `chat-message-${chatUser.id}`,
        renotify: true,
        data: { url: `chat.php?user=${Number(chatUser.id)}` },
    }).catch(() => {
        // Ignore notification errors.
    });
}

function renderChatSwitcher(users) {
    if (!chatSwitcherListEl) {
        return;
    }

    if (!Array.isArray(users) || users.length === 0) {
        chatSwitcherListEl.innerHTML = `
            <div class="card">
                <h2 class="panel-title">No other users yet</h2>
                <p class="panel-text">Create another account on this network to start chatting.</p>
            </div>`;
        return;
    }

    chatSwitcherListEl.innerHTML = renderDirectoryEntries(users, false);
}

function setChatSwitcherOpen(isOpen) {
    if (!chatSwitcherEl) {
        return;
    }

    chatSwitcherEl.hidden = !isOpen;
    document.body.style.overflow = isOpen ? 'hidden' : '';
}


function openChatFromRow(eventTarget) {
    const row = eventTarget.closest('[data-open-chat-url]');
    if (!row) {
        return false;
    }

    if (eventTarget.closest('a, button, input, textarea, select, label')) {
        return false;
    }

    const url = row.dataset.openChatUrl;
    if (!url) {
        return false;
    }

    window.location.href = url;
    return true;
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
                <p class="panel-text">Start a conversation from the new chat button to see it here.</p>
            </div>`;
        return;
    }

    chatListEl.innerHTML = renderChatListEntries(users);
}

function applyChatListPayload(payload) {
    const chatUsers = Array.isArray(payload?.chat_users) ? payload.chat_users : [];
    const directoryUsers = Array.isArray(payload?.directory_users) ? payload.directory_users : [];
    const incomingRequests = Array.isArray(payload?.incoming_requests) ? payload.incoming_requests : [];
    const nextChatSignature = JSON.stringify(chatUsers);
    const nextDirectorySignature = JSON.stringify(directoryUsers);
    const nextRequestSignature = JSON.stringify(incomingRequests);

    chatUsers.forEach((chatUser) => {
        const userId = String(chatUser.id);
        const nextUnseenCount = Number(chatUser.unseen_count || 0);
        const previousUnseenCount = lastUnseenCounts.get(userId) || 0;

        if (nextUnseenCount > previousUnseenCount) {
            showUnreadMessageNotification(chatUser, nextUnseenCount - previousUnseenCount);
        }

        lastUnseenCounts.set(userId, nextUnseenCount);
    });

    if (nextChatSignature !== chatListSignature) {
        chatListSignature = nextChatSignature;
        renderChatList(chatUsers);
    }

    if (nextDirectorySignature !== directorySignature) {
        directorySignature = nextDirectorySignature;
        renderChatSwitcher(directoryUsers);
    }

    if (nextRequestSignature !== requestSignature) {
        const incomingIds = new Set(incomingRequests.map((request) => String(request.id || request.sender_id)));
        incomingRequests.forEach((request) => {
            const requestId = String(request.id || request.sender_id);
            if (!seenIncomingRequestIds.has(requestId)) {
                showFriendRequestNotification(request);
            }
        });
        seenIncomingRequestIds = incomingIds;
        requestSignature = nextRequestSignature;
        renderIncomingRequests(incomingRequests);
    }
}

async function fetchChatList() {
    const response = await fetch('home_api.php', {
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

    chatListStream = new EventSource('home_stream.php');

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

applyChatListPayload({ chat_users: initialChatUsers, directory_users: initialDirectoryUsers, incoming_requests: initialIncomingRequests });
updateLoginButtonState();
loginUsernameInput?.addEventListener('input', updateLoginButtonState);
loginPasswordInput?.addEventListener('input', updateLoginButtonState);
connectChatListStream();

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('sw.js').catch(() => {
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

chatSwitcherToggle?.addEventListener('click', () => { requestNotificationPermission(); setChatSwitcherOpen(true); });
chatSwitcherClose?.addEventListener('click', () => setChatSwitcherOpen(false));
chatSwitcherEl?.addEventListener('click', (event) => {
    if (event.target === chatSwitcherEl) {
        setChatSwitcherOpen(false);
    }
});
document.addEventListener('keydown', (event) => {
    markUserInteraction();
    if (event.key === 'Escape') {
        setChatSwitcherOpen(false);
        return;
    }

    if ((event.key === 'Enter' || event.key === ' ') && openChatFromRow(event.target)) {
        event.preventDefault();
    }
});

document.addEventListener('click', async (event) => {
    markUserInteraction();
    if (openChatFromRow(event.target)) {
        return;
    }

    const target = event.target.closest('[data-request-action]');
    if (!target) {
        return;
    }

    requestNotificationPermission();

    const action = target.dataset.requestAction;
    const userId = Number(target.dataset.userId || 0);
    if (!action || !userId) {
        return;
    }

    target.disabled = true;

    try {
        const response = await fetch('home_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', Accept: 'application/json' },
            credentials: 'same-origin',
            body: new URLSearchParams({ action, user: String(userId) }),
        });
        const payload = await response.json();

        if (!response.ok || payload.error) {
            throw new Error(payload.error || `Request failed with ${response.status}`);
        }

        applyChatListPayload(payload.payload || payload);
    } catch (error) {
        window.alert(error.message || 'Could not update the friend request.');
    } finally {
        target.disabled = false;
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
