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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Local Chat</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Arial, sans-serif;
        }
        body {
            margin: 0;
            background: #f4f7fb;
            color: #1f2937;
        }
        .container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 24px;
        }
        .hero, .card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 14px 40px rgba(15, 23, 42, 0.08);
            padding: 24px;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
        }
        h1, h2, h3 { margin-top: 0; }
        input, button {
            width: 100%;
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid #d1d5db;
            box-sizing: border-box;
            margin-bottom: 12px;
            font-size: 15px;
        }
        button {
            background: #2563eb;
            color: white;
            border: none;
            cursor: pointer;
            font-weight: 700;
        }
        button.secondary { background: #111827; }
        .alert {
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 16px;
        }
        .alert.error { background: #fee2e2; color: #991b1b; }
        .alert.notice { background: #dcfce7; color: #166534; }
        .user-list a {
            display: block;
            text-decoration: none;
            padding: 14px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            color: #111827;
            margin-bottom: 10px;
        }
        .muted { color: #6b7280; }
        .pill {
            display: inline-block;
            background: #e0e7ff;
            color: #3730a3;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="hero">
        <span class="pill">PHP + SQLite</span>
        <h1>Local Chat</h1>
        <p>Private one-to-one chat with text and voice notes. All messages self-delete after 24 hours using a lightweight SQLite database.</p>
    </div>

    <?php if ($loginRequired): ?>
        <div class="alert error">Please sign in before opening a conversation.</div>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endforeach; ?>

    <?php if ($notice !== null): ?>
        <div class="alert notice"><?= e($notice) ?></div>
    <?php endif; ?>

    <?php if ($user === null): ?>
        <div class="grid" style="margin-top: 20px;">
            <div class="card">
                <h2>Create an account</h2>
                <form method="post">
                    <input type="hidden" name="action" value="register">
                    <label>
                        <span>Username</span>
                        <input type="text" name="username" minlength="3" required>
                    </label>
                    <label>
                        <span>Password</span>
                        <input type="password" name="password" minlength="6" required>
                    </label>
                    <button type="submit">Register</button>
                </form>
            </div>
            <div class="card">
                <h2>Sign in</h2>
                <form method="post">
                    <input type="hidden" name="action" value="login">
                    <label>
                        <span>Username</span>
                        <input type="text" name="username" required>
                    </label>
                    <label>
                        <span>Password</span>
                        <input type="password" name="password" required>
                    </label>
                    <button class="secondary" type="submit">Login</button>
                </form>
            </div>
        </div>
    <?php else: ?>
        <div class="grid" style="margin-top: 20px; align-items: start;">
            <div class="card">
                <h2>Welcome, <?= e($user['username']) ?></h2>
                <p class="muted">Choose another registered user to start a private chat.</p>
                <form method="post">
                    <input type="hidden" name="action" value="logout">
                    <button class="secondary" type="submit">Logout</button>
                </form>
            </div>
            <div class="card user-list">
                <h2>Available users</h2>
                <?php if ($users === []): ?>
                    <p class="muted">No other users yet. Create another account in a second browser session to test private chat.</p>
                <?php else: ?>
                    <?php foreach ($users as $chatUser): ?>
                        <a href="/chat.php?user=<?= (int) $chatUser['id'] ?>">
                            <strong><?= e($chatUser['username']) ?></strong><br>
                            <span class="muted">Open private chat</span>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
