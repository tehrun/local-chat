<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = requireAuth();
$otherUserId = (int) ($_GET['user'] ?? 0);
$otherUser = findUserById($otherUserId);

if ($otherUser === null || $otherUser['id'] === $user['id']) {
    header('Location: /');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'send_text') {
        $error = sendTextMessage((int) $user['id'], $otherUserId, $_POST['body'] ?? '');
        if ($error !== null) {
            $errors[] = $error;
        } else {
            header('Location: /chat.php?user=' . $otherUserId);
            exit;
        }
    }

    if ($action === 'send_voice') {
        $error = sendVoiceMessage((int) $user['id'], $otherUserId, $_FILES['voice_note'] ?? []);
        if ($error !== null) {
            $errors[] = $error;
        } else {
            header('Location: /chat.php?user=' . $otherUserId);
            exit;
        }
    }
}

$messages = conversationMessages((int) $user['id'], $otherUserId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat with <?= e($otherUser['username']) ?></title>
    <style>
        body {
            margin: 0;
            background: #eef2ff;
            font-family: Arial, sans-serif;
            color: #111827;
        }
        .container {
            max-width: 960px;
            margin: 0 auto;
            padding: 24px;
        }
        .card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 12px 40px rgba(15, 23, 42, 0.1);
            padding: 24px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
        }
        .messages {
            display: flex;
            flex-direction: column;
            gap: 14px;
            margin-bottom: 24px;
            max-height: 460px;
            overflow-y: auto;
        }
        .message {
            max-width: 75%;
            padding: 14px;
            border-radius: 14px;
            background: #f3f4f6;
        }
        .mine {
            align-self: flex-end;
            background: #dbeafe;
        }
        textarea, input[type="file"], button {
            width: 100%;
            box-sizing: border-box;
            margin-bottom: 12px;
            border-radius: 10px;
            border: 1px solid #d1d5db;
            padding: 12px;
            font-size: 15px;
        }
        textarea { min-height: 110px; resize: vertical; }
        button {
            border: none;
            background: #2563eb;
            color: white;
            font-weight: bold;
            cursor: pointer;
        }
        .grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
        }
        .alert {
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 12px;
            background: #fee2e2;
            color: #991b1b;
        }
        a { color: #1d4ed8; text-decoration: none; }
        .meta {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 8px;
        }
        @media (max-width: 800px) {
            .grid { grid-template-columns: 1fr; }
            .message { max-width: 100%; }
            .header { flex-direction: column; align-items: start; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <div class="header">
            <div>
                <a href="/">&larr; Back to users</a>
                <h1>Chat with <?= e($otherUser['username']) ?></h1>
                <p>Messages and voice notes are private and automatically deleted after 24 hours.</p>
            </div>
        </div>

        <?php foreach ($errors as $error): ?>
            <div class="alert"><?= e($error) ?></div>
        <?php endforeach; ?>

        <div class="grid">
            <div>
                <div class="messages">
                    <?php if ($messages === []): ?>
                        <div class="message">
                            <div class="meta">No messages yet</div>
                            Start the conversation with a text or voice note.
                        </div>
                    <?php else: ?>
                        <?php foreach ($messages as $message): ?>
                            <div class="message <?= (int) $message['sender_id'] === (int) $user['id'] ? 'mine' : '' ?>">
                                <div class="meta">
                                    <?= e($message['sender_name']) ?> · <?= e(gmdate('Y-m-d H:i:s', strtotime($message['created_at']))) ?> UTC
                                </div>
                                <?php if (!empty($message['body'])): ?>
                                    <div><?= nl2br(e($message['body'])) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($message['audio_path'])): ?>
                                    <audio controls preload="none" src="/media.php?message=<?= (int) $message['id'] ?>"></audio>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div>
                <form method="post">
                    <input type="hidden" name="action" value="send_text">
                    <h3>Send text</h3>
                    <textarea name="body" placeholder="Write a private message..." required></textarea>
                    <button type="submit">Send message</button>
                </form>

                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="send_voice">
                    <h3>Send voice note</h3>
                    <input type="file" name="voice_note" accept="audio/*" required>
                    <button type="submit">Upload voice note</button>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>
