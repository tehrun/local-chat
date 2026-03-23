<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

purgeExpiredMessages();

$errors = [];
$notice = null;
$user = currentUser();
$authMode = (isset($_GET['auth']) && $_GET['auth'] === 'register') ? 'register' : 'login';
$authChallengePrompt = authChallengePrompt();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();

    $action = $_POST['action'] ?? '';

    if ($action === 'register') {
        $authMode = 'register';
        $error = registerUser(
            $_POST['username'] ?? '',
            $_POST['password'] ?? '',
            $_POST['confirm_password'] ?? '',
            $_POST['verification_answer'] ?? ''
        );
        $authChallengePrompt = authChallengePrompt();
        if ($error !== null) {
            $errors[] = $error;
        } else {
            $notice = 'Registration successful. Please sign in.';
            $authMode = 'login';
        }
    }

    if ($action === 'login') {
        $authMode = 'login';
        $error = loginUser($_POST['username'] ?? '', $_POST['password'] ?? '', $_POST['verification_answer'] ?? '');
        $authChallengePrompt = authChallengePrompt();
        if ($error !== null) {
            $errors[] = $error;
        } else {
            header('Location: index.php');
            exit;
        }
    }

    if ($action === 'logout') {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        session_destroy();
        header('Location: index.php');
        exit;
    }
}

$user = currentUser();
$users = $user ? allOtherUsers((int) $user['id']) : [];
$chatUsers = $user ? combinedChatList((int) $user['id']) : [];
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
    <meta name="csrf-token" content="<?= e(csrfToken()) ?>">
    <link rel="stylesheet" href="assets/app.css">
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
            background: var(--bg);
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
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }
        .intro-bubble[hidden] {
            display: none;
        }
        .intro-bubble-copy {
            min-width: 0;
        }
        .intro-dismiss {
            border: none;
            border-radius: 999px;
            background: rgba(7, 94, 84, 0.12);
            color: var(--header);
            width: 32px;
            height: 32px;
            padding: 0;
            font-size: 20px;
            line-height: 1;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .intro-dismiss:hover {
            background: rgba(7, 94, 84, 0.18);
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
            padding: 12px 14px;
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
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .chat-copy strong {
            font-size: 16px;
            line-height: 1.35;
            display: block;
        }
        .chat-copy-head {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 0;
        }
        .chat-name-row {
            min-width: 0;
            flex: 1;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .chat-name {
            min-width: 0;
            flex: 1;
        }
        .chat-type-chip {
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            border-radius: 999px;
            background: #e4e7ec;
            color: #475467;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.02em;
            flex-shrink: 0;
        }
        .chat-last-time {
            flex-shrink: 0;
            font-size: 12px;
            line-height: 1;
            font-weight: 600;
            color: var(--muted);
            white-space: nowrap;
        }
        .chat-last-time.is-empty {
            visibility: hidden;
        }
        .chat-preview-row {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }
        .chat-preview {
            min-width: 0;
            flex: 1;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.35;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .chat-time {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 22px;
            height: 22px;
            padding: 0 7px;
            border-radius: 999px;
            background: var(--action);
            color: #fff;
            font-size: 11px;
            font-weight: 700;
            line-height: 1;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .chat-time.is-empty {
            display: none;
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
        .chat-switcher-search {
            padding: 0 18px 14px;
        }
        .chat-switcher-search input {
            margin-top: 0;
        }
        .chat-switcher-actions {
            padding: 0 18px 14px;
            display: flex;
            justify-content: flex-end;
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
        .chat-switcher-empty {
            margin: 0 18px 18px;
            padding: 14px 16px;
            border-radius: 16px;
            background: #fff;
            color: var(--muted);
            font-size: 14px;
            text-align: center;
        }
        @media (min-width: 721px) {
            .app {
                min-height: 100vh;
                box-shadow: var(--shadow);
            }
        }
    </style>
</head>
<body data-page="home">
<div class="app">
    <div class="shell">
        <header class="topbar">
            <div class="topbar-copy">
                <span class="topbar-eyebrow">Private messaging, refined</span>
                <h1>Local Chat</h1>
                <p>Simple private conversations on your local network.</p>
            </div>
            <div class="topbar-actions">
                <?php if ($user !== null): ?>
                    <span class="user-chip"><?= e($user['username']) ?></span>
                    <button class="install-button" id="install-app-button" type="button" hidden>Install app</button>
                    <form method="post">
                        <input type="hidden" name="action" value="logout">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
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

            <div class="card intro-bubble" id="welcome-message" data-dismiss-key="localchat:welcome-dismissed">
                <div class="intro-bubble-copy">
                    <h2 class="panel-title">Welcome</h2>
                    <p class="panel-text">Send text messages and voice notes, see who is online, and keep chat history for 7 days while uploaded photos, files, and voice notes expire after 24 hours.</p>
                </div>
                <button class="intro-dismiss" id="welcome-dismiss-button" type="button" aria-label="Dismiss welcome message">&times;</button>
            </div>

            <?php if ($user === null): ?>
                <section class="auth-grid">
                    <div class="card">
                        <?php if ($authMode === 'register'): ?>
                            <h2 class="panel-title">Create account</h2>
                            <form method="post">
                                <input type="hidden" name="action" value="register">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <label>
                                    Username
                                    <input type="text" name="username" minlength="3" required>
                                </label>
                                <label>
                                    Password
                                    <input type="password" name="password" minlength="6" required>
                                </label>
                                <label>
                                    Confirm password
                                    <input type="password" name="confirm_password" minlength="6" required>
                                </label>
                                <label>
                                    Verification: solve <?= e($authChallengePrompt) ?>
                                    <input type="text" name="verification_answer" inputmode="numeric" pattern="-?[0-9]+" autocomplete="off" required>
                                </label>
                                <button class="primary" type="submit">Register</button>
                            </form>
                            <p class="auth-switch">Already have an account? <a href="index.php">Sign in</a></p>
                        <?php else: ?>
                            <h2 class="panel-title">Sign in</h2>
                            <form method="post" id="login-form">
                                <input type="hidden" name="action" value="login">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <label>
                                    Username
                                    <input type="text" name="username" required data-login-field="username">
                                </label>
                                <label>
                                    Password
                                    <input type="password" name="password" required data-login-field="password">
                                </label>
                                <label>
                                    Verification: solve <?= e($authChallengePrompt) ?>
                                    <input type="text" name="verification_answer" inputmode="numeric" pattern="-?[0-9]+" autocomplete="off" required>
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
                            <a class="chat-item" data-chat-user-id="<?= (int) $chatUser['id'] ?>" href="<?= e((string) ($chatUser['url'] ?? ('chat.php?user=' . (int) $chatUser['id']))) ?>">
                                <div class="avatar"><?= e(strtoupper(substr((string) ($chatUser['name'] ?? $chatUser['username']), 0, 2))) ?></div>
                                <div class="chat-copy">
                                    <div class="chat-copy-head">
                                        <span class="chat-name-row">
                                            <strong class="chat-name"><?= e((string) ($chatUser['name'] ?? $chatUser['username'])) ?></strong>
                                            <?php if (!empty($chatUser['is_group'])): ?>
                                                <span class="chat-type-chip">Group</span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="chat-last-time<?= ($chatUser['chat_list_time'] ?? '') !== '' ? '' : ' is-empty' ?>" data-role="chat-time"><?= e($chatUser['chat_list_time'] ?? '') ?></span>
                                    </div>
                                    <div class="chat-preview-row">
                                        <span class="chat-preview" data-role="chat-preview"><?= e($chatUser['chat_list_preview'] ?? 'Start chatting') ?></span>
                                        <span class="chat-time<?= $unseenCount > 0 ? '' : ' is-empty' ?>" data-role="unseen-count"<?= $unseenCount > 0 ? '' : ' aria-hidden="true"' ?>><?= $unseenCount > 0 ? $unseenCount : '' ?></span>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <div class="helper-row">
                <span class="dot"></span>
                <span>Now installable on supported devices and browsers.</span>
            </div>

            <?php if ($user !== null): ?>
                <div class="card product-note">
                    <h2 class="panel-title">Smooth app-style experience</h2>
                    <p class="panel-text">Live updates, premium overlays, and richer feedback now make the chat list feel closer to an installed mobile app.</p>
                </div>
            <?php endif; ?>
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
                <h2 id="chat-switcher-title">Search users</h2>
                <button class="chat-switcher-close" id="chat-switcher-close" type="button" aria-label="Close user list">×</button>
            </div>
            <div class="chat-switcher-actions">
                <button class="mini-button secondary" id="create-group-button" type="button">Create group</button>
            </div>
            <div class="chat-switcher-search">
                <input id="chat-switcher-search-input" type="search" placeholder="Search users by name" autocomplete="off" aria-label="Search users by name">
            </div>
            <div class="chat-switcher-list" id="chat-switcher-list"></div>
            <p class="chat-switcher-empty" id="chat-switcher-empty" hidden>No users match your search.</p>
        </div>
    </div>
<?php endif; ?>
<script>
const currentUserId = <?= $user !== null ? (int) $user['id'] : 'null' ?>;
const initialChatUsers = <?= jsonScriptValue($chatUsers) ?>;
const initialDirectoryUsers = <?= jsonScriptValue($users) ?>;
const initialIncomingRequests = <?= jsonScriptValue($incomingRequests) ?>;
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
const webPushPublicKey = <?= jsonScriptValue($user !== null ? webPushPublicKey() : null) ?>;
const initialHomeSignature = <?= jsonScriptValue($user !== null ? chatListStateSignature((int) $user['id']) : '') ?>;
const preferPolling = <?= PHP_SAPI === 'cli-server' ? 'true' : 'false' ?>;
const chatListEl = document.getElementById('chat-list');
const friendRequestListEl = document.getElementById('friend-request-list');
let chatListStream = null;
let chatListReconnectTimer = null;
let chatListPollTimer = null;
let homePayloadSignature = initialHomeSignature;
let chatListSignature = '';
let directorySignature = '';
let requestSignature = '';
let seenIncomingRequestIds = new Set(initialIncomingRequests.map((request) => String(request.id || request.sender_id)));
let deferredInstallPrompt = null;
const installButton = document.getElementById('install-app-button');
const welcomeMessage = document.getElementById('welcome-message');
const welcomeDismissButton = document.getElementById('welcome-dismiss-button');
const welcomeDismissStorageKey = welcomeMessage?.dataset.dismissKey || 'localchat:welcome-dismissed';
const FAST_HOME_POLL_INTERVAL_MS = 4000;
const MAX_HOME_POLL_INTERVAL_MS = 15000;
const STREAM_FALLBACK_POLL_INTERVAL_MS = 12000;
let homePollDelay = FAST_HOME_POLL_INTERVAL_MS;

const chatSwitcherToggle = document.getElementById('chat-switcher-toggle');
const chatSwitcherEl = document.getElementById('chat-switcher');
const chatSwitcherListEl = document.getElementById('chat-switcher-list');
const chatSwitcherClose = document.getElementById('chat-switcher-close');
const chatSwitcherSearchInput = document.getElementById('chat-switcher-search-input');
const chatSwitcherEmptyEl = document.getElementById('chat-switcher-empty');
const createGroupButton = document.getElementById('create-group-button');
const loginForm = document.getElementById('login-form');
const loginSubmitButton = document.getElementById('login-submit');
const loginUsernameInput = loginForm?.querySelector('[data-login-field="username"]') || null;
const loginPasswordInput = loginForm?.querySelector('[data-login-field="password"]') || null;

let notificationPermissionRequested = false;
let notificationPermissionPromptDismissed = false;
let hasInteracted = false;
let lastUnseenCounts = new Map(initialChatUsers.map((chatUser) => [String(chatUser.id), Number(chatUser.unseen_count || 0)]));
let pushSubscriptionSyncPromise = null;
let directoryUsersState = Array.isArray(initialDirectoryUsers) ? [...initialDirectoryUsers] : [];
let lastFriendshipStates = new Map(initialDirectoryUsers.map((chatUser) => [String(chatUser.id), {
    status: String(chatUser.friendship_status || 'none'),
    direction: chatUser.request_direction || null,
    username: String(chatUser.username || ''),
}]));

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

function parseIsoTimestamp(value) {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? null : date;
}

function formatClockTime(value) {
    const date = parseIsoTimestamp(value);
    if (!date) {
        return '';
    }

    return new Intl.DateTimeFormat(undefined, {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).format(date);
}

function formatPresenceLabel(isOnline, updatedAt) {
    if (isOnline) {
        return 'Online';
    }

    const date = parseIsoTimestamp(updatedAt);
    if (!date) {
        return 'Offline';
    }

    const now = new Date();
    const targetDay = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const diffDays = Math.round((targetDay.getTime() - today.getTime()) / 86400000);
    const timeLabel = formatClockTime(updatedAt);

    if (diffDays === 0) {
        return `Last seen today at ${timeLabel}`;
    }

    if (diffDays === -1) {
        return `Last seen yesterday at ${timeLabel}`;
    }

    const dateLabel = new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        year: date.getFullYear() === now.getFullYear() ? undefined : 'numeric',
    }).format(date);

    return `Last seen ${dateLabel} at ${timeLabel}`;
}

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
    Notification.requestPermission()
        .then((permission) => {
            if (permission === 'granted') {
                ensurePushSubscription();
            }
        })
        .catch(() => {
            // Ignore notification permission errors.
        });
}

function base64UrlToUint8Array(value) {
    if (!value) {
        return new Uint8Array();
    }

    const normalized = value.replace(/-/g, '+').replace(/_/g, '/');
    const padded = normalized + '='.repeat((4 - (normalized.length % 4)) % 4);
    const raw = window.atob(padded);

    return Uint8Array.from(raw, (char) => char.charCodeAt(0));
}

async function syncPushSubscriptionWithServer(subscription) {
    const params = new URLSearchParams({
        action: 'save_push_subscription',
        csrf_token: csrfToken,
        subscription: JSON.stringify(subscription),
    });

    await fetch('home_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body: params.toString(),
        credentials: 'same-origin',
    });
}

async function syncServiceWorkerPushConfig() {
    if (!('serviceWorker' in navigator) || !webPushPublicKey || !csrfToken) {
        return;
    }

    const registration = await navigator.serviceWorker.ready;
    const worker = registration.active || registration.waiting || registration.installing;
    worker?.postMessage({
        type: 'push-config',
        publicKey: webPushPublicKey,
        csrfToken,
    });
}

async function ensurePushSubscription() {
    if (!currentUserId || !webPushPublicKey || !('serviceWorker' in navigator) || !('PushManager' in window) || Notification.permission !== 'granted') {
        return null;
    }

    if (pushSubscriptionSyncPromise) {
        return pushSubscriptionSyncPromise;
    }

    pushSubscriptionSyncPromise = (async () => {
        const registration = await navigator.serviceWorker.ready;
        let subscription = await registration.pushManager.getSubscription();

        if (!subscription) {
            subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: base64UrlToUint8Array(webPushPublicKey),
            });
        }

        await syncPushSubscriptionWithServer(subscription.toJSON());
        await syncServiceWorkerPushConfig();

        return subscription;
    })().catch(() => null).finally(() => {
        pushSubscriptionSyncPromise = null;
    });

    return pushSubscriptionSyncPromise;
}

async function playNotificationSound(repetitions = 1) {
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

        const beepCount = Math.max(1, Number.isFinite(repetitions) ? Math.floor(repetitions) : 1);
        const firstStartAt = audioContext.currentTime + 0.01;
        const gapSeconds = 0.28;

        for (let index = 0; index < beepCount; index += 1) {
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            const startAt = firstStartAt + (index * gapSeconds);
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
        }

        window.setTimeout(() => {
            audioContext.close().catch(() => {
                // Ignore audio context close errors.
            });
        }, Math.max(400, Math.ceil((beepCount * gapSeconds * 1000) + 250)));
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
        const presenceLabel = escapeHtml(formatPresenceLabel(Boolean(chatUser.is_online), chatUser.presence_updated_at || null));
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
                        <strong class="chat-name">${username}</strong>
                    </div>
                    <div class="chat-preview-row">
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
        const displayName = String(chatUser.name || chatUser.username || '');
        const avatar = escapeHtml(displayName.slice(0, 2).toUpperCase());
        const username = escapeHtml(displayName);
        const preview = escapeHtml(chatUser.chat_list_preview || 'Start chatting');
        const chatTime = escapeHtml(formatClockTime(chatUser.last_message_at || chatUser.chat_list_time || ''));
        const countClass = unseenCount > 0 ? '' : ' is-empty';
        const hiddenAttr = unseenCount > 0 ? '' : ' aria-hidden="true"';
        const href = escapeHtml(chatUser.url || `chat.php?user=${userId}`);

        return `
            <a class="chat-item" data-chat-user-id="${userId}" href="${href}">
                <div class="avatar">${avatar}</div>
                <div class="chat-copy">
                    <div class="chat-copy-head">
                        <span class="chat-name-row">
                            <strong class="chat-name">${username}</strong>
                            ${chatUser.is_group ? '<span class="chat-type-chip">Group</span>' : ''}
                        </span>
                        <span class="chat-last-time${chatTime ? '' : ' is-empty'}" data-role="chat-time">${chatTime}</span>
                    </div>
                    <div class="chat-preview-row">
                        <span class="chat-preview" data-role="chat-preview">${preview}</span>
                        <span class="chat-time${countClass}" data-role="unseen-count"${hiddenAttr}>${unseenCount > 0 ? String(unseenCount) : ''}</span>
                    </div>
                </div>
            </a>`;
    }).join('');
}

function filteredDirectoryUsers() {
    const query = String(chatSwitcherSearchInput?.value || '').trim().toLowerCase();
    if (query === '') {
        return [];
    }

    return directoryUsersState.filter((chatUser) => String(chatUser.username || '').toLowerCase().includes(query));
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
        const presenceLabel = escapeHtml(formatPresenceLabel(Boolean(request.is_online), request.presence_updated_at || null));
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


async function showFriendRequestResponseNotification(update) {
    if (!update || document.visibilityState === 'visible') {
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

    const recipientName = update.recipient_name || update.username || 'Someone';
    const accepted = update.status === 'accepted';

    registration.showNotification(accepted ? 'Friend request accepted' : 'Friend request rejected', {
        body: accepted
            ? `${recipientName} accepted your friend request.`
            : `${recipientName} rejected your friend request.`,
        icon: 'icons/icon.svg',
        tag: `friend-request-response-${update.id || update.recipient_id || recipientName}`,
        renotify: true,
        data: { url: accepted && update.recipient_id ? `chat.php?user=${Number(update.recipient_id)}` : 'index.php' },
    }).catch(() => {
        // Ignore notification errors.
    });
}

async function showUnreadMessageNotification(chatUser, increaseCount) {
    if (!chatUser || increaseCount <= 0) {
        return;
    }

    if (document.visibilityState === 'visible') {
        return;
    }

    await playNotificationSound(increaseCount);

    if (!('Notification' in window) || Notification.permission !== 'granted') {
        return;
    }

    const registration = await navigator.serviceWorker.getRegistration().catch(() => null);
    if (!registration) {
        return;
    }

    const username = chatUser.name || chatUser.username || 'Someone';
    const body = increaseCount === 1
        ? `${username} sent you a new message.`
        : `${username} sent you ${increaseCount} new messages.`;
    const destinationUrl = chatUser.url || `chat.php?user=${Number(chatUser.id)}`;

    registration.showNotification('New message', {
        body,
        icon: 'icons/icon.svg',
        tag: `chat-message-${chatUser.id}`,
        renotify: true,
        data: { url: destinationUrl },
    }).catch(() => {
        // Ignore notification errors.
    });
}

function renderChatSwitcher(users) {
    if (!chatSwitcherListEl) {
        return;
    }

    const hasUsers = Array.isArray(users) && users.length > 0;
    const searchQuery = String(chatSwitcherSearchInput?.value || '').trim();

    if (!hasUsers) {
        chatSwitcherListEl.innerHTML = `
            <div class="card">
                <h2 class="panel-title">No other users yet</h2>
                <p class="panel-text">Create another account on this network to start chatting.</p>
            </div>`;
        if (chatSwitcherEmptyEl) {
            chatSwitcherEmptyEl.hidden = true;
        }
        return;
    }

    const visibleUsers = filteredDirectoryUsers();
    chatSwitcherListEl.innerHTML = renderDirectoryEntries(visibleUsers, true);
    if (chatSwitcherEmptyEl) {
        chatSwitcherEmptyEl.textContent = searchQuery === ''
            ? 'Search by name to find and add a user.'
            : 'No users match your search.';
        chatSwitcherEmptyEl.hidden = visibleUsers.length > 0;
    }
}

function setChatSwitcherOpen(isOpen) {
    if (!chatSwitcherEl) {
        return;
    }

    chatSwitcherEl.hidden = !isOpen;
    document.body.style.overflow = isOpen ? 'hidden' : '';
    if (isOpen) {
        renderChatSwitcher(directoryUsersState);
        chatSwitcherSearchInput?.focus();
    } else if (chatSwitcherSearchInput) {
        chatSwitcherSearchInput.value = '';
    }
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
    if (typeof payload?.signature === 'string' && payload.signature !== '') {
        homePayloadSignature = payload.signature;
        if (preferPolling) {
            homePollDelay = FAST_HOME_POLL_INTERVAL_MS;
        }
    }

    const chatUsers = Array.isArray(payload?.chat_users) ? payload.chat_users : [];
    const directoryUsers = Array.isArray(payload?.directory_users) ? payload.directory_users : [];
    const incomingRequests = Array.isArray(payload?.incoming_requests) ? payload.incoming_requests : [];
    const nextChatSignature = JSON.stringify(chatUsers);
    const nextDirectorySignature = JSON.stringify(directoryUsers);
    const nextRequestSignature = JSON.stringify(incomingRequests);
    let totalNewMessages = 0;

    chatUsers.forEach((chatUser) => {
        const userId = String(chatUser.id);
        const nextUnseenCount = Number(chatUser.unseen_count || 0);
        const previousUnseenCount = lastUnseenCounts.get(userId) || 0;

        if (nextUnseenCount > previousUnseenCount) {
            const increaseCount = nextUnseenCount - previousUnseenCount;
            totalNewMessages += increaseCount;
            showUnreadMessageNotification(chatUser, increaseCount);
        }

        lastUnseenCounts.set(userId, nextUnseenCount);
    });

    if (document.visibilityState === 'visible' && totalNewMessages > 0) {
        playNotificationSound(totalNewMessages);
    }

    if (nextChatSignature !== chatListSignature) {
        chatListSignature = nextChatSignature;
        renderChatList(chatUsers);
    }

    if (nextDirectorySignature !== directorySignature) {
        directoryUsers.forEach((chatUser) => {
            const userId = String(chatUser.id);
            const previousState = lastFriendshipStates.get(userId);
            const nextState = {
                status: String(chatUser.friendship_status || 'none'),
                direction: chatUser.request_direction || null,
                username: String(chatUser.username || ''),
                recipient_id: Number(chatUser.id || 0),
            };

            if (
                previousState
                && previousState.status === 'pending'
                && previousState.direction === 'outgoing'
                && (nextState.status === 'accepted' || nextState.status === 'rejected')
            ) {
                showFriendRequestResponseNotification({
                    id: userId,
                    recipient_id: nextState.recipient_id,
                    recipient_name: nextState.username,
                    status: nextState.status,
                });
            }

            lastFriendshipStates.set(userId, nextState);
        });

        directorySignature = nextDirectorySignature;
        directoryUsersState = directoryUsers;
        renderChatSwitcher(directoryUsersState);
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
    const signatureResponse = await fetch('home_api.php?action=signature', {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        cache: 'no-store',
    });

    if (!signatureResponse.ok) {
        throw new Error(`Chat list signature request failed with ${signatureResponse.status}`);
    }

    const signaturePayload = await signatureResponse.json();
    if (typeof signaturePayload.signature !== 'string' || signaturePayload.signature === homePayloadSignature) {
        return null;
    }

    const response = await fetch('home_api.php', {
        headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken },
        credentials: 'same-origin',
        cache: 'no-store',
    });

    if (!response.ok) {
        throw new Error(`Chat list request failed with ${response.status}`);
    }

    return response.json();
}

function stopChatListPolling() {
    window.clearTimeout(chatListPollTimer);
    chatListPollTimer = null;
}

function scheduleChatListPoll(delay = homePollDelay) {
    stopChatListPolling();
    chatListPollTimer = window.setTimeout(async () => {
        chatListPollTimer = null;

        try {
            const payload = await fetchChatList();
            if (payload) {
                applyChatListPayload(payload);
                homePollDelay = FAST_HOME_POLL_INTERVAL_MS;
            } else {
                homePollDelay = Math.min(MAX_HOME_POLL_INTERVAL_MS, homePollDelay + 2000);
            }
        } catch (error) {
            homePollDelay = Math.min(MAX_HOME_POLL_INTERVAL_MS, homePollDelay + 3000);
        }

        if (currentUserId) {
            scheduleChatListPoll(preferPolling ? homePollDelay : STREAM_FALLBACK_POLL_INTERVAL_MS);
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

    scheduleChatListPoll(STREAM_FALLBACK_POLL_INTERVAL_MS);
}

if (welcomeMessage) {
    try {
        if (window.localStorage.getItem(welcomeDismissStorageKey) === '1') {
            welcomeMessage.hidden = true;
        }
    } catch (error) {
        // Ignore storage access errors.
    }
}

welcomeDismissButton?.addEventListener('click', () => {
    welcomeMessage.hidden = true;
    try {
        window.localStorage.setItem(welcomeDismissStorageKey, '1');
    } catch (error) {
        // Ignore storage access errors.
    }
});

applyChatListPayload({
    chat_users: initialChatUsers,
    directory_users: initialDirectoryUsers,
    incoming_requests: initialIncomingRequests,
    signature: initialHomeSignature,
});
updateLoginButtonState();
loginUsernameInput?.addEventListener('input', updateLoginButtonState);
loginPasswordInput?.addEventListener('input', updateLoginButtonState);
connectChatListStream();

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('sw.js')
            .then(() => {
                syncServiceWorkerPushConfig().catch(() => {
                    // Ignore service worker config sync errors.
                });
                if (Notification.permission === 'granted') {
                    ensurePushSubscription();
                }
            })
            .catch(() => {
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
chatSwitcherSearchInput?.addEventListener('input', () => {
    renderChatSwitcher(directoryUsersState);
});
createGroupButton?.addEventListener('click', async () => {
    markUserInteraction();
    const name = window.prompt('Name your new group');
    if (name === null) {
        return;
    }

    try {
        const response = await fetch('home_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', Accept: 'application/json', 'X-CSRF-Token': csrfToken },
            credentials: 'same-origin',
            body: new URLSearchParams({ action: 'create_group', name, csrf_token: csrfToken }),
        });
        const payload = await response.json();

        if (!response.ok || payload.error) {
            throw new Error(payload.error || `Request failed with ${response.status}`);
        }

        applyChatListPayload(payload.payload || payload);
        setChatSwitcherOpen(false);
    } catch (error) {
        window.alert(error.message || 'Could not create the group.');
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
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', Accept: 'application/json', 'X-CSRF-Token': csrfToken },
            credentials: 'same-origin',
            body: new URLSearchParams({ action, user: String(userId), csrf_token: csrfToken }),
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
<script src="assets/app.js"></script>
</body>
</html>
