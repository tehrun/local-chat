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

$messages = conversationMessages((int) $user['id'], $otherUserId);
$otherUserTyping = isUserTyping((int) $user['id'], $otherUserId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Chat with <?= e($otherUser['username']) ?></title>
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
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            height: 100vh;
            overflow: hidden;
            font-family: Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
        }
        .app {
            min-height: 100vh;
            height: 100vh;
            display: flex;
            flex-direction: column;
            max-width: 720px;
            margin: 0 auto;
            background: linear-gradient(180deg, #0b141a 0, #0b141a 72px, var(--bg) 72px);
        }
        .chat-shell {
            min-height: 100vh;
            height: 100vh;
            display: flex;
            flex-direction: column;
            background: transparent;
        }
        .topbar {
            position: sticky;
            top: 0;
            z-index: 5;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: calc(14px + env(safe-area-inset-top, 0px)) 16px 14px;
            background: var(--header);
            color: #fff;
            box-shadow: var(--shadow);
        }
        .back-link {
            color: #dff6f1;
            text-decoration: none;
            font-size: 14px;
            white-space: nowrap;
        }
        .topbar-meta {
            min-width: 0;
            flex: 1;
        }
        .topbar-meta h1 {
            margin: 0;
            font-size: 18px;
            line-height: 1.2;
        }
        .topbar-meta p {
            margin: 4px 0 0;
            font-size: 13px;
            color: #d7efe8;
        }
        .conversation {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
            padding: 14px 12px 0;
        }
        .messages {
            flex: 1;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            gap: 10px;
            padding: 6px 4px 18px;
            scroll-behavior: smooth;
            overscroll-behavior: contain;
        }
        .empty-state,
        .message {
            max-width: min(82%, 420px);
            border-radius: 18px;
            padding: 10px 12px;
            box-shadow: 0 2px 6px rgba(17, 27, 33, 0.06);
            word-break: break-word;
        }
        .empty-state {
            max-width: 100%;
            align-self: center;
            background: rgba(255,255,255,0.72);
            color: var(--muted);
            text-align: center;
            margin-top: 16px;
        }
        .message {
            background: var(--theirs);
            align-self: flex-start;
        }
        .message.mine {
            background: var(--mine);
            align-self: flex-end;
        }
        .message-text {
            white-space: pre-wrap;
            line-height: 1.45;
            font-size: 15px;
        }
        .message audio {
            width: min(240px, 100%);
            margin-top: 6px;
        }
        .meta {
            margin-top: 8px;
            font-size: 11px;
            color: var(--muted);
            text-align: right;
        }
        .status-row {
            min-height: 28px;
            display: flex;
            align-items: center;
            padding: 0 4px 10px;
        }
        .typing-pill,
        .recording-pill,
        .hint-pill,
        .error-pill {
            border-radius: 999px;
            padding: 7px 12px;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .typing-pill,
        .hint-pill {
            background: rgba(255, 255, 255, 0.82);
            color: #41525d;
        }
        .recording-pill {
            background: #fee4e2;
            color: var(--danger);
        }
        .error-pill {
            background: #fef3f2;
            color: var(--danger);
        }
        .dot-group { display: inline-flex; gap: 4px; }
        .dot {
            width: 6px;
            height: 6px;
            border-radius: 999px;
            background: currentColor;
            animation: pulse 1.2s infinite ease-in-out;
        }
        .dot:nth-child(2) { animation-delay: 0.15s; }
        .dot:nth-child(3) { animation-delay: 0.3s; }
        @keyframes pulse {
            0%, 80%, 100% { opacity: 0.35; transform: scale(0.8); }
            40% { opacity: 1; transform: scale(1); }
        }
        .composer-wrap {
            position: sticky;
            bottom: 0;
            z-index: 4;
            padding: 10px 12px calc(10px + env(safe-area-inset-bottom, 0px));
            background: linear-gradient(180deg, rgba(239,234,226,0) 0%, rgba(239,234,226,0.96) 18%, rgba(239,234,226,1) 45%);
        }
        .composer {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--composer);
            border-radius: 26px;
            padding: 10px;
            box-shadow: var(--shadow);
        }
        .composer textarea {
            flex: 1;
            border: none;
            background: transparent;
            resize: none;
            min-height: 44px;
            max-height: 110px;
            font: inherit;
            line-height: 1.4;
            color: var(--text);
            padding: 11px 4px 9px;
            outline: none;
        }
        .action-button {
            border: none;
            width: 52px;
            height: 52px;
            flex: 0 0 52px;
            border-radius: 50%;
            background: var(--action);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 22px;
            box-shadow: 0 8px 18px rgba(37, 211, 102, 0.28);
            transition: transform 0.15s ease, background 0.15s ease;
            user-select: none;
            -webkit-user-select: none;
            -webkit-touch-callout: none;
            touch-action: manipulation;
        }
        .action-button:active,
        .action-button.recording {
            transform: scale(0.96);
            background: var(--action-active);
        }
        .action-button:disabled {
            opacity: 0.7;
            cursor: wait;
        }
        .composer-help {
            margin: 8px 6px 0;
            font-size: 12px;
            color: var(--muted);
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
<body>
<div class="app">
    <div class="chat-shell">
        <header class="topbar">
            <a class="back-link" href="/">← Chats</a>
            <div class="topbar-meta">
                <h1><?= e($otherUser['username']) ?></h1>
                <p>Private messages auto-delete after 24 hours.</p>
            </div>
        </header>

        <main class="conversation">
            <div class="messages" id="messages" aria-live="polite"></div>
            <div class="status-row" id="status-row"></div>
        </main>

        <div class="composer-wrap">
            <div class="composer">
                <textarea id="message-body" rows="1" placeholder="Message"></textarea>
                <button id="action-button" class="action-button" type="button" aria-label="Send message or hold to record voice note">🎤</button>
            </div>
            <div class="composer-help" id="composer-help">Type to send text, or hold the button to record a voice note.</div>
        </div>
    </div>
</div>
<script>
const currentUserId = <?= (int) $user['id'] ?>;
const conversationUserId = <?= (int) $otherUserId ?>;
const otherUserName = <?= json_encode($otherUser['username'], JSON_THROW_ON_ERROR) ?>;
const initialMessages = <?= json_encode($messages, JSON_THROW_ON_ERROR) ?>;
const initialTyping = <?= $otherUserTyping ? 'true' : 'false' ?>;
const preferPolling = <?= PHP_SAPI === 'cli-server' ? 'true' : 'false' ?>;
const messagesEl = document.getElementById('messages');
const statusRowEl = document.getElementById('status-row');
const bodyEl = document.getElementById('message-body');
const actionButton = document.getElementById('action-button');
const composerHelpEl = document.getElementById('composer-help');
let renderedSignature = '';
let localMessageCounter = 0;
let typingTimer = null;
let typingActive = false;
let isSending = false;
let mediaRecorder = null;
let mediaStream = null;
let recordingChunks = [];
let recordingStart = 0;
let recordingMode = false;
let pressTimer = null;
let suppressClick = false;
let stream = null;
let streamReconnectTimer = null;
let pollTimer = null;
let statusState = initialTyping ? 'typing' : 'hint';
let statusMessage = initialTyping ? `${otherUserName} is typing…` : 'Hold the button to record. Release to send.';

function escapeHtml(value) {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function autoResizeComposer() {
    bodyEl.style.height = '44px';
    bodyEl.style.height = `${Math.max(44, Math.min(bodyEl.scrollHeight, 110))}px`;
}

function renderStatus() {
    let html = '';

    if (statusState === 'typing') {
        html = `<span class="typing-pill">${escapeHtml(statusMessage)} <span class="dot-group" aria-hidden="true"><span class="dot"></span><span class="dot"></span><span class="dot"></span></span></span>`;
    } else if (statusState === 'recording') {
        html = `<span class="recording-pill">🔴 ${escapeHtml(statusMessage)}</span>`;
    } else if (statusState === 'error') {
        html = `<span class="error-pill">${escapeHtml(statusMessage)}</span>`;
    } else {
        html = `<span class="hint-pill">${escapeHtml(statusMessage)}</span>`;
    }

    statusRowEl.innerHTML = html;
}

function showHint(message) {
    statusState = 'hint';
    statusMessage = message;
    renderStatus();
}

function showError(message) {
    statusState = 'error';
    statusMessage = message;
    renderStatus();
}

function setTypingVisible(isVisible) {
    if (recordingMode) {
        return;
    }

    if (isVisible) {
        statusState = 'typing';
        statusMessage = `${otherUserName} is typing…`;
    } else if (statusState === 'typing') {
        showHint('Type to send text, or hold the button to record a voice note.');
        return;
    }

    renderStatus();
}

function createPendingMessage(body, type) {
    localMessageCounter += 1;
    const now = new Date();
    return {
        id: `pending-${type}-${localMessageCounter}`,
        sender_id: currentUserId,
        recipient_id: conversationUserId,
        sender_name: 'You',
        body,
        audio_path: null,
        created_at: now.toISOString(),
        created_at_label: `${now.toISOString().slice(0, 19).replace('T', ' ')} UTC`,
        pending: true,
    };
}

function upsertMessage(message) {
    const messages = window.__messagesState || [];
    const nextMessages = messages.filter((item) => item.id !== message.id);
    nextMessages.push(message);
    nextMessages.sort((left, right) => {
        const leftTime = Date.parse(left.created_at) || 0;
        const rightTime = Date.parse(right.created_at) || 0;
        if (leftTime === rightTime) {
            return String(left.id).localeCompare(String(right.id));
        }
        return leftTime - rightTime;
    });
    renderMessages(nextMessages);
}

function replacePendingMessage(pendingId, message) {
    const messages = (window.__messagesState || []).map((item) => item.id === pendingId ? message : item);
    renderMessages(messages);
}

function removeMessage(messageId) {
    renderMessages((window.__messagesState || []).filter((item) => item.id !== messageId));
}

function renderMessages(messages) {
    window.__messagesState = messages;
    const signature = JSON.stringify(messages.map((message) => [message.id, message.created_at]));
    if (signature === renderedSignature) {
        return;
    }

    const shouldStickToBottom = messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight < 50;
    renderedSignature = signature;

    if (messages.length === 0) {
        messagesEl.innerHTML = '<div class="empty-state">No messages yet. Say hi or hold the mic button to send a voice note.</div>';
    } else {
        messagesEl.innerHTML = messages.map((message) => {
            const isMine = Number(message.sender_id) === currentUserId;
            const body = message.body
                ? `<div class="message-text">${escapeHtml(message.body).replace(/\n/g, '<br>')}</div>`
                : '';
            const audio = message.audio_path
                ? `<audio controls preload="none" src="/media.php?message=${Number(message.id)}"></audio>`
                : '';
            const pendingLabel = message.pending ? ' · Sending…' : '';

            return `
                <article class="message ${isMine ? 'mine' : ''}">
                    ${body}
                    ${audio}
                    <div class="meta">${escapeHtml(message.sender_name)} · ${escapeHtml(message.created_at_label)}${pendingLabel}</div>
                </article>`;
        }).join('');
    }

    if (shouldStickToBottom || messagesEl.scrollTop === 0) {
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }
}

function updateActionButton() {
    if (recordingMode) {
        actionButton.textContent = '◼';
        actionButton.classList.add('recording');
        actionButton.setAttribute('aria-label', 'Release to send voice note');
        return;
    }

    const hasText = bodyEl.value.trim() !== '';
    actionButton.textContent = hasText ? '➤' : '🎤';
    actionButton.classList.remove('recording');
    actionButton.setAttribute('aria-label', hasText ? 'Send message' : 'Hold to record voice note');
}

function applyConversationPayload(payload) {
    if (Array.isArray(payload.messages)) {
        renderMessages(payload.messages);
    } else if (payload.message) {
        replacePendingMessage(payload.pending_id || '', payload.message);
        upsertMessage(payload.message);
    }
    setTypingVisible(Boolean(payload.typing));
}

async function refreshConversation() {
    try {
        const response = await fetch(`/chat_api.php?action=messages&user=${conversationUserId}`, {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store',
        });
        if (!response.ok) {
            return;
        }
        applyConversationPayload(await response.json());
    } catch (error) {
        // Ignore transient refresh errors.
    }
}

function startPollingConversation() {
    if (pollTimer !== null) {
        return;
    }

    refreshConversation();
    pollTimer = window.setInterval(() => {
        refreshConversation();
    }, 1500);
}

function stopPollingConversation() {
    if (pollTimer !== null) {
        window.clearInterval(pollTimer);
        pollTimer = null;
    }
}

function scheduleStreamReconnect() {
    if (preferPolling) {
        startPollingConversation();
        return;
    }

    if (streamReconnectTimer !== null) {
        return;
    }

    streamReconnectTimer = window.setTimeout(() => {
        streamReconnectTimer = null;
        connectConversationStream();
    }, 1500);
}

function connectConversationStream() {
    if (preferPolling || !window.EventSource) {
        startPollingConversation();
        return;
    }

    stopPollingConversation();

    if (stream) {
        stream.close();
    }

    stream = new EventSource(`/chat_stream.php?user=${conversationUserId}`);
    stream.addEventListener('conversation', (event) => {
        try {
            applyConversationPayload(JSON.parse(event.data));
        } catch (error) {
            // Ignore malformed stream events and keep the connection alive.
        }
    });

    stream.onerror = () => {
        if (stream) {
            stream.close();
            stream = null;
        }
        scheduleStreamReconnect();
    };
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

function clearTypingSoon() {
    clearTimeout(typingTimer);
    typingTimer = setTimeout(() => {
        typingActive = false;
        syncTyping(false);
    }, 3000);
}

function markTyping() {
    if (bodyEl.value.trim() === '') {
        if (typingActive) {
            typingActive = false;
            clearTimeout(typingTimer);
            syncTyping(false);
        }
        return;
    }

    if (!typingActive) {
        typingActive = true;
        syncTyping(true);
    }

    clearTypingSoon();
}

async function sendTextMessage() {
    const body = bodyEl.value.trim();
    if (!body || isSending) {
        return;
    }

    const pendingMessage = createPendingMessage(body, 'text');
    upsertMessage(pendingMessage);
    bodyEl.value = '';
    autoResizeComposer();
    typingActive = false;
    clearTimeout(typingTimer);
    syncTyping(false);
    showHint('Sending message…');
    isSending = true;
    actionButton.disabled = true;

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

        replacePendingMessage(pendingMessage.id, payload.message);
        messagesEl.scrollTop = messagesEl.scrollHeight;
        showHint('Hold the button to record. Release to send.');
    } catch (error) {
        removeMessage(pendingMessage.id);
        bodyEl.value = body;
        autoResizeComposer();
        showError('Could not send message right now. Please try again.');
    } finally {
        isSending = false;
        actionButton.disabled = false;
        updateActionButton();
        bodyEl.focus();
    }
}

function detectAudioMimeType() {
    const candidates = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/ogg', 'audio/mp4', 'audio/mp4;codecs=mp4a.40.2', 'audio/x-m4a'];
    for (const type of candidates) {
        if (window.MediaRecorder && MediaRecorder.isTypeSupported(type)) {
            return type;
        }
    }
    return '';
}

async function startRecording() {
    if (bodyEl.value.trim() !== '' || isSending || recordingMode) {
        return;
    }
    if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
        showError('Voice recording is not supported on this device/browser.');
        return;
    }

    try {
        mediaStream = await navigator.mediaDevices.getUserMedia({ audio: true });
        const mimeType = detectAudioMimeType();
        mediaRecorder = mimeType ? new MediaRecorder(mediaStream, { mimeType }) : new MediaRecorder(mediaStream);
        recordingChunks = [];
        recordingMode = true;
        recordingStart = Date.now();

        mediaRecorder.addEventListener('dataavailable', (event) => {
            if (event.data && event.data.size > 0) {
                recordingChunks.push(event.data);
            }
        });

        mediaRecorder.addEventListener('stop', () => {
            mediaStream?.getTracks().forEach((track) => track.stop());
            mediaStream = null;
        }, { once: true });

        mediaRecorder.start();
        updateActionButton();
        showHint('Recording… keep holding, then release to send.');
        statusState = 'recording';
        statusMessage = 'Recording voice note…';
        renderStatus();
    } catch (error) {
        showError('Microphone permission is required to send a voice note.');
    }
}

async function stopRecordingAndSend() {
    clearTimeout(pressTimer);
    if (!recordingMode || !mediaRecorder) {
        return;
    }

    const recorder = mediaRecorder;
    const durationMs = Date.now() - recordingStart;
    recordingMode = false;
    mediaRecorder = null;
    updateActionButton();

    if (durationMs < 600) {
        recorder.stop();
        recordingChunks = [];
        showHint('Hold a little longer to send a voice note.');
        return;
    }

    statusState = 'recording';
    statusMessage = 'Sending voice note…';
    renderStatus();
    actionButton.disabled = true;
    isSending = true;

    const blob = await new Promise((resolve) => {
        recorder.addEventListener('stop', () => {
            resolve(new Blob(recordingChunks, { type: recorder.mimeType || 'audio/webm' }));
        }, { once: true });
        recorder.stop();
    });

    recordingChunks = [];

    if (!(blob instanceof Blob) || blob.size === 0) {
        isSending = false;
        actionButton.disabled = false;
        showError('The voice note was empty. Please try again.');
        return;
    }

    try {
        const mimeType = blob.type || recorder.mimeType || 'audio/webm';
        const extension = mimeType.includes('ogg') ? 'ogg' : ((mimeType.includes('mp4') || mimeType.includes('m4a')) ? 'm4a' : 'webm');
        const formData = new FormData();
        formData.append('action', 'send_voice');
        formData.append('voice_note', blob, `voice-note.${extension}`);

        const response = await fetch(`/chat_api.php?user=${conversationUserId}`, {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            body: formData,
        });
        const payload = await response.json();

        if (!response.ok) {
            showError(payload.error || 'Could not send voice note.');
            return;
        }

        applyConversationPayload(payload);
        messagesEl.scrollTop = messagesEl.scrollHeight;
        showHint('Voice note sent. Hold again to record another one.');
    } catch (error) {
        showError('Could not send voice note right now. Please try again.');
    } finally {
        isSending = false;
        actionButton.disabled = false;
        updateActionButton();
    }
}

function cancelPendingRecord() {
    clearTimeout(pressTimer);
}

function onPressStart(event) {
    if (bodyEl.value.trim() !== '' || isSending) {
        return;
    }

    event.preventDefault();
    suppressClick = true;
    pressTimer = setTimeout(() => {
        startRecording();
    }, 180);
}

function onPressEnd(event) {
    if (bodyEl.value.trim() !== '') {
        return;
    }

    if (event) {
        event.preventDefault();
    }
    cancelPendingRecord();
    stopRecordingAndSend();
}

bodyEl.addEventListener('input', () => {
    autoResizeComposer();
    updateActionButton();
    markTyping();
    if (statusState !== 'typing') {
        showHint('Tap send to deliver your message.');
    }
});

bodyEl.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        if (bodyEl.value.trim() !== '') {
            sendTextMessage();
        }
    }
});

actionButton.addEventListener('click', (event) => {
    if (suppressClick) {
        suppressClick = false;
        if (bodyEl.value.trim() === '') {
            event.preventDefault();
            return;
        }
    }

    if (bodyEl.value.trim() !== '') {
        sendTextMessage();
    }
});

actionButton.addEventListener('pointerdown', onPressStart);
actionButton.addEventListener('pointerup', onPressEnd);
actionButton.addEventListener('pointercancel', onPressEnd);
actionButton.addEventListener('pointerleave', () => {
    if (recordingMode) {
        stopRecordingAndSend();
    } else {
        cancelPendingRecord();
    }
});

window.addEventListener('beforeunload', () => {
    if (stream) {
        stream.close();
    }
    stopPollingConversation();
    if (typingActive && navigator.sendBeacon) {
        const data = new URLSearchParams({ action: 'typing', typing: 'false' });
        navigator.sendBeacon(`/chat_api.php?user=${conversationUserId}`, data);
    }
});

autoResizeComposer();
updateActionButton();
renderMessages(initialMessages);
renderStatus();
connectConversationStream();

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
        if (preferPolling) {
            startPollingConversation();
        } else if (!stream) {
            refreshConversation();
            connectConversationStream();
        }
        return;
    }

    if (preferPolling) {
        stopPollingConversation();
    }
});
</script>
</body>
</html>
