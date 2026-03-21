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
$otherUserTyping = isUserTyping((int) $user['id'], $otherUserId);
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
            margin-bottom: 12px;
            max-height: 460px;
            overflow-y: auto;
            padding-right: 6px;
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
        button:disabled {
            opacity: 0.7;
            cursor: wait;
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
        .status {
            min-height: 24px;
            margin-bottom: 12px;
            color: #4b5563;
            font-size: 14px;
        }
        .typing {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #eef2ff;
            color: #3730a3;
            border-radius: 999px;
            padding: 8px 12px;
        }
        .dot-group {
            display: inline-flex;
            gap: 4px;
        }
        .dot {
            width: 6px;
            height: 6px;
            border-radius: 999px;
            background: #3730a3;
            animation: pulse 1.2s infinite ease-in-out;
        }
        .dot:nth-child(2) { animation-delay: 0.15s; }
        .dot:nth-child(3) { animation-delay: 0.3s; }
        @keyframes pulse {
            0%, 80%, 100% { opacity: 0.35; transform: scale(0.85); }
            40% { opacity: 1; transform: scale(1); }
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
                <div class="messages" id="messages" aria-live="polite"></div>
                <div class="status" id="typing-indicator" <?= $otherUserTyping ? '' : 'hidden' ?>>
                    <span class="typing">
                        <?= e($otherUser['username']) ?> is typing
                        <span class="dot-group" aria-hidden="true">
                            <span class="dot"></span>
                            <span class="dot"></span>
                            <span class="dot"></span>
                        </span>
                    </span>
                </div>
            </div>
            <div>
                <div class="alert" id="text-error" hidden></div>
                <form method="post" id="text-form">
                    <input type="hidden" name="action" value="send_text">
                    <h3>Send text</h3>
                    <textarea name="body" id="message-body" placeholder="Write a private message..." required></textarea>
                    <button type="submit" id="send-button">Send message</button>
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
<script>
const currentUserId = <?= (int) $user['id'] ?>;
const otherUserName = <?= json_encode($otherUser['username'], JSON_THROW_ON_ERROR) ?>;
const conversationUserId = <?= (int) $otherUserId ?>;
const initialMessages = <?= json_encode($messages, JSON_THROW_ON_ERROR) ?>;
const initialTyping = <?= $otherUserTyping ? 'true' : 'false' ?>;
const messagesEl = document.getElementById('messages');
const typingEl = document.getElementById('typing-indicator');
const form = document.getElementById('text-form');
const bodyEl = document.getElementById('message-body');
const sendButton = document.getElementById('send-button');
const errorEl = document.getElementById('text-error');
let renderedSignature = '';
let pollTimer = null;
let typingTimer = null;
let typingActive = false;
let latestFetchId = 0;

function escapeHtml(value) {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function renderMessages(messages) {
    const signature = JSON.stringify(messages.map((message) => [message.id, message.created_at]));
    if (signature === renderedSignature) {
        return;
    }

    const shouldStickToBottom = messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight < 40;
    renderedSignature = signature;

    if (messages.length === 0) {
        messagesEl.innerHTML = `
            <div class="message">
                <div class="meta">No messages yet</div>
                Start the conversation with a text or voice note.
            </div>`;
    } else {
        messagesEl.innerHTML = messages.map((message) => {
            const isMine = Number(message.sender_id) === currentUserId;
            const body = message.body
                ? `<div>${escapeHtml(message.body).replace(/\n/g, '<br>')}</div>`
                : '';
            const audio = message.audio_path
                ? `<audio controls preload="none" src="/media.php?message=${Number(message.id)}"></audio>`
                : '';

            return `
                <div class="message ${isMine ? 'mine' : ''}">
                    <div class="meta">${escapeHtml(message.sender_name)} · ${escapeHtml(message.created_at_label)}</div>
                    ${body}
                    ${audio}
                </div>`;
        }).join('');
    }

    if (shouldStickToBottom || messagesEl.scrollTop === 0) {
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }
}

function setTypingVisible(isVisible) {
    typingEl.hidden = !isVisible;
}

function showError(message) {
    errorEl.hidden = !message;
    errorEl.textContent = message || '';
}

async function fetchConversation() {
    const fetchId = ++latestFetchId;
    try {
        const response = await fetch(`/chat_api.php?action=messages&user=${conversationUserId}`, {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store',
        });
        if (!response.ok) {
            return;
        }
        const payload = await response.json();
        if (fetchId !== latestFetchId) {
            return;
        }
        renderMessages(payload.messages || []);
        setTypingVisible(Boolean(payload.typing));
    } catch (error) {
        // Ignore transient polling errors.
    }
}

async function syncTyping(isTyping) {
    try {
        await fetch(`/chat_api.php?user=${conversationUserId}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
            body: new URLSearchParams({ action: 'typing', typing: String(isTyping) }),
        });
    } catch (error) {
        // Ignore transient typing sync errors.
    }
}

function markTyping() {
    if (!typingActive) {
        typingActive = true;
        syncTyping(true);
    }

    clearTimeout(typingTimer);
    typingTimer = setTimeout(() => {
        typingActive = false;
        syncTyping(false);
    }, 3000);
}

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    showError('');

    const body = bodyEl.value.trim();
    if (!body) {
        showError('Message cannot be empty.');
        return;
    }

    sendButton.disabled = true;

    try {
        const response = await fetch(`/chat_api.php?user=${conversationUserId}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
            body: new URLSearchParams({ action: 'send_text', body }),
        });
        const payload = await response.json();

        if (!response.ok) {
            showError(payload.error || 'Could not send message.');
            return;
        }

        bodyEl.value = '';
        typingActive = false;
        clearTimeout(typingTimer);
        syncTyping(false);
        renderMessages(payload.messages || []);
        setTypingVisible(Boolean(payload.typing));
        messagesEl.scrollTop = messagesEl.scrollHeight;
    } catch (error) {
        showError('Could not send message right now. Please try again.');
    } finally {
        sendButton.disabled = false;
        bodyEl.focus();
    }
});

bodyEl.addEventListener('input', () => {
    if (bodyEl.value.trim() === '') {
        if (typingActive) {
            typingActive = false;
            clearTimeout(typingTimer);
            syncTyping(false);
        }
        return;
    }
    markTyping();
});

window.addEventListener('beforeunload', () => {
    if (typingActive && navigator.sendBeacon) {
        const data = new URLSearchParams({ action: 'typing', typing: 'false' });
        navigator.sendBeacon(`/chat_api.php?user=${conversationUserId}`, data);
    }
});

renderMessages(initialMessages);
setTypingVisible(initialTyping);
pollTimer = setInterval(fetchConversation, 2000);
fetchConversation();
</script>
</body>
</html>
