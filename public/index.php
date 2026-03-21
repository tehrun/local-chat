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
        }
        .user-chip {
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255,255,255,0.14);
            font-size: 13px;
            color: #fff;
            white-space: nowrap;
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
            max-width: 88%;
            background: var(--mine);
            margin-left: auto;
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
        .chat-copy {
            min-width: 0;
            flex: 1;
        }
        .chat-copy strong {
            display: block;
            margin-bottom: 4px;
            font-size: 16px;
        }
        .chat-copy span {
            display: block;
            color: var(--muted);
            font-size: 14px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .chat-time {
            color: var(--muted);
            font-size: 12px;
            white-space: nowrap;
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
            background: var(--composer);
            padding: 13px 14px;
            font: inherit;
            color: var(--text);
        }
        button.primary {
            width: 100%;
            border: none;
            border-radius: 14px;
            background: var(--action);
            color: #fff;
            padding: 14px;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 8px 18px rgba(37, 211, 102, 0.28);
        }
        button.secondary {
            width: 100%;
            border: none;
            border-radius: 14px;
            background: #0b141a;
            color: #fff;
            padding: 14px;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }
        .helper-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: auto;
            padding: 0 4px;
            color: var(--muted);
            font-size: 12px;
        }
        .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--action);
            flex-shrink: 0;
        }
        @media (min-width: 721px) {
            .app {
                box-shadow: var(--shadow);
            }
            .auth-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
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
                <p><?= $user ? 'Pick a conversation and keep chatting.' : 'Sign in to start chatting in the same app-style layout.' ?></p>
            </div>
            <?php if ($user !== null): ?>
                <div class="topbar-actions">
                    <span class="user-chip"><?= e($user['username']) ?></span>
                    <form method="post" style="margin:0;">
                        <input type="hidden" name="action" value="logout">
                        <button class="logout-button" type="submit">Logout</button>
                    </form>
                </div>
            <?php endif; ?>
        </header>

        <main class="content">
            <section class="card intro-bubble">
                <h2 class="panel-title"><?= $user ? 'Your chats' : 'Welcome to Local Chat' ?></h2>
                <p class="panel-text"><?= $user ? 'The home screen now matches the chat experience, while the conversation page stays the same.' : 'Create an account or sign in from a screen that looks like the real chat app, not a separate landing page.' ?></p>
            </section>

            <?php if ($loginRequired): ?>
                <div class="alerts"><div class="alert error">Please sign in before opening a conversation.</div></div>
            <?php endif; ?>

            <?php if ($errors !== [] || $notice !== null): ?>
                <div class="alerts">
                    <?php foreach ($errors as $error): ?>
                        <div class="alert error"><?= e($error) ?></div>
                    <?php endforeach; ?>
                    <?php if ($notice !== null): ?>
                        <div class="alert notice"><?= e($notice) ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($user === null): ?>
                <section class="card">
                    <h2 class="panel-title">Preview</h2>
                    <p class="panel-text">Once you sign in, this screen becomes your chat list and each conversation opens in the existing messenger view.</p>
                    <div class="chat-list" style="margin-top: 14px;">
                        <div class="chat-item">
                            <div class="avatar">LC</div>
                            <div class="chat-copy">
                                <strong>Private messaging</strong>
                                <span>Text, typing status, and hold-to-record voice notes.</span>
                            </div>
                            <span class="chat-time">24h</span>
                        </div>
                        <div class="chat-item">
                            <div class="avatar">🔒</div>
                            <div class="chat-copy">
                                <strong>Auto-cleanup</strong>
                                <span>Messages are removed automatically after 24 hours.</span>
                            </div>
                            <span class="chat-time">TTL</span>
                        </div>
                    </div>
                </section>

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
                <section class="chat-list">
                    <?php if ($users === []): ?>
                        <div class="card">
                            <h2 class="panel-title">No chats yet</h2>
                            <p class="panel-text">There are no other users yet. Create another account in a second browser session to start a private chat.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($users as $chatUser): ?>
                            <a class="chat-item" href="/chat.php?user=<?= (int) $chatUser['id'] ?>">
                                <div class="avatar"><?= e(strtoupper(substr((string) $chatUser['username'], 0, 2))) ?></div>
                                <div class="chat-copy">
                                    <strong><?= e($chatUser['username']) ?></strong>
                                    <span>Tap to open your private conversation.</span>
                                </div>
                                <span class="chat-time">Chat</span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <div class="helper-row">
                <span class="dot"></span>
                <span>Chat layout stays mobile-first and compatible with the current conversation screen.</span>
            </div>
        </main>
    </div>
</div>
</body>
</html>
