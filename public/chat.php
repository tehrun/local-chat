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
    <meta name="theme-color" content="#075e54">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Local Chat">
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" href="/icons/icon.svg" type="image/svg+xml">
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
            min-height: 100dvh;
            height: 100vh;
            height: 100dvh;
            overflow: hidden;
            font-family: Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
        }
        .app {
            min-height: 100vh;
            min-height: 100dvh;
            height: 100vh;
            height: 100dvh;
            display: flex;
            flex-direction: column;
            max-width: 720px;
            margin: 0 auto;
            background: linear-gradient(180deg, #0b141a 0, #0b141a 72px, var(--bg) 72px);
        }
        .chat-shell {
            min-height: 100vh;
            min-height: 100dvh;
            height: 100vh;
            height: 100dvh;
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
            width: 40px;
            height: 40px;
            flex: 0 0 40px;
            border-radius: 50%;
            color: #dff6f1;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(11, 20, 26, 0.18);
            transition: background 0.15s ease, transform 0.15s ease;
        }
        .back-link:hover,
        .back-link:focus-visible {
            background: rgba(11, 20, 26, 0.3);
        }
        .back-link:active {
            transform: scale(0.96);
        }
        .back-link svg {
            width: 22px;
            height: 22px;
            stroke: currentColor;
            stroke-width: 2.4;
            fill: none;
            stroke-linecap: round;
            stroke-linejoin: round;
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
        .presence-row {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 6px;
            font-size: 13px;
            color: #d7efe8;
        }
        .presence-light {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #98a2b3;
            box-shadow: 0 0 0 3px rgba(255,255,255,0.14);
            flex-shrink: 0;
        }
        .presence-light.online {
            background: #25d366;
            box-shadow: 0 0 0 3px rgba(37, 211, 102, 0.22);
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
        .message-text.rtl {
            direction: rtl;
            text-align: right;
        }
        .message-text.ltr {
            direction: ltr;
            text-align: left;
        }
        .message audio {
            width: min(240px, 100%);
            margin-top: 6px;
        }
        .meta {
            margin-top: 8px;
            font-size: 11px;
            color: var(--muted);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 4px;
            text-align: right;
        }
        .meta-label {
            min-width: 0;
        }
        .delivery-ticks {
            display: inline-flex;
            align-items: center;
            margin-left: 2px;
            color: #8696a0;
            line-height: 1;
        }
        .delivery-ticks.read {
            color: #53bdeb;
        }
        .delivery-ticks svg {
            width: 14px;
            height: 10px;
            display: block;
        }
        .status-row {
            min-height: 28px;
            display: flex;
            align-items: center;
            padding: 0 4px 10px;
            flex-wrap: wrap;
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
            direction: ltr;
            text-align: left;
            unicode-bidi: plaintext;
        }
        .composer textarea.rtl {
            direction: rtl;
            text-align: right;
        }
        .composer textarea.ltr {
            direction: ltr;
            text-align: left;
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
            box-shadow: 0 8px 18px rgba(37, 211, 102, 0.28);
            transition: transform 0.15s ease, background 0.15s ease;
            user-select: none;
            -webkit-user-select: none;
            -webkit-touch-callout: none;
            touch-action: manipulation;
        }
        .action-button svg {
            width: 24px;
            height: 24px;
            fill: none;
            stroke: currentColor;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
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

        .floating-chat-launcher {
            position: fixed;
            right: max(18px, calc(env(safe-area-inset-right, 0px) + 18px));
            bottom: max(94px, calc(env(safe-area-inset-bottom, 0px) + 94px));
            z-index: 8;
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
        .chat-switcher[hidden] {
            display: none;
        }
        .chat-switcher {
            position: fixed;
            inset: 0;
            z-index: 12;
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
    <div class="chat-shell">
        <header class="topbar">
            <a class="back-link" href="/" aria-label="Back to chats">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M15 6l-6 6 6 6"></path>
                </svg>
            </a>
            <div class="topbar-meta">
                <h1><?= e($otherUser['username']) ?></h1>
                <div class="presence-row">
                    <span class="presence-light <?= !empty($otherUser['is_online']) ? 'online' : '' ?>" id="header-presence-light" aria-hidden="true"></span>
                    <span id="header-presence-label"><?= e($otherUser['presence_label'] ?? 'Offline') ?></span>
                </div>
            </div>
        </header>

        <main class="conversation">
            <div class="messages" id="messages" aria-live="polite"></div>
        </main>

        <div class="composer-wrap">
            <div class="status-row" id="status-row"></div>
            <div class="composer">
                <textarea id="message-body" rows="1" placeholder="Message"></textarea>
                <input id="voice-file-input" type="file" accept="audio/*,video/webm,video/ogg,video/mp4" capture style="display:none">
                <button id="action-button" class="action-button" type="button" aria-label="Send message or start voice recording">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M12 4.5a3 3 0 0 1 3 3v3.75a3 3 0 0 1-6 0V7.5a3 3 0 0 1 3-3Z"></path>
                        <path d="M18 11.25a6 6 0 0 1-12 0"></path>
                        <path d="M12 17.25V20"></path>
                        <path d="M9.5 20h5"></path>
                    </svg>
                </button>
            </div>
        </div>

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
    </div>
</div>
<script>

const currentUserId = <?= (int) $user['id'] ?>;
const conversationUserId = <?= (int) $otherUserId ?>;
const otherUserName = <?= json_encode($otherUser['username'], JSON_THROW_ON_ERROR) ?>;
const initialMessages = <?= json_encode($messages, JSON_THROW_ON_ERROR) ?>;
const initialTyping = <?= $otherUserTyping ? 'true' : 'false' ?>;
const initialPresence = <?= !empty($otherUser['is_online']) ? 'true' : 'false' ?>;
const initialPresenceLabel = <?= json_encode($otherUser['presence_label'] ?? 'Offline', JSON_THROW_ON_ERROR) ?>;
const preferPolling = <?= PHP_SAPI === 'cli-server' ? 'true' : 'false' ?>;
const initialChatUsers = <?= json_encode(chattedUsers((int) $user['id']), JSON_THROW_ON_ERROR) ?>;
const messagesEl = document.getElementById('messages');
const statusRowEl = document.getElementById('status-row');
const bodyEl = document.getElementById('message-body');
const actionButton = document.getElementById('action-button');
const voiceFileInput = document.getElementById('voice-file-input');
const headerPresenceLight = document.getElementById('header-presence-light');
const headerPresenceLabel = document.getElementById('header-presence-label');
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
let stream = null;
let streamReconnectTimer = null;
let pollTimer = null;
let shouldAutoScroll = true;
let readSyncTimer = null;
let statusState = initialTyping ? 'typing' : 'hint';
let statusMessage = initialTyping ? `${otherUserName} is typing…` : 'Type a message or tap the microphone for a voice note.';
let otherUserOnline = initialPresence;
let hasInteracted = false;
let notificationPermissionRequested = false;

const chatSwitcherToggle = document.getElementById('chat-switcher-toggle');
const chatSwitcherEl = document.getElementById('chat-switcher');
const chatSwitcherListEl = document.getElementById('chat-switcher-list');
const chatSwitcherClose = document.getElementById('chat-switcher-close');

renderChatSwitcher(initialChatUsers);

function renderChatSwitcher(users) {
    if (!chatSwitcherListEl) {
        return;
    }

    const filteredUsers = Array.isArray(users)
        ? users.filter((chatUser) => Number(chatUser.id) !== conversationUserId)
        : [];

    if (filteredUsers.length === 0) {
        chatSwitcherListEl.innerHTML = '<div class="empty-state">You only have this private chat right now.</div>';
        return;
    }

    chatSwitcherListEl.innerHTML = filteredUsers.map((chatUser) => {
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
    chatSwitcherEl.hidden = !isOpen;
}

function supportsInlineVoiceRecording() {
    return Boolean(navigator.mediaDevices?.getUserMedia && window.MediaRecorder);
}

function supportsCapturedVoiceUpload() {
    return Boolean(voiceFileInput);
}

function updatePresence(isOnline, label) {
    otherUserOnline = Boolean(isOnline);
    headerPresenceLight.classList.toggle('online', otherUserOnline);
    headerPresenceLabel.textContent = label || (otherUserOnline ? 'Online' : 'Offline');
}

function markUserInteraction() {
    hasInteracted = true;

    if (notificationPermissionRequested || !('Notification' in window) || Notification.permission !== 'default') {
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

        const startAt = audioContext.currentTime + 0.01;
        const notes = [880, 1174.66, 1567.98];

        notes.forEach((frequency, index) => {
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            const noteStart = startAt + (index * 0.11);
            const noteEnd = noteStart + 0.22;

            oscillator.type = 'sine';
            oscillator.frequency.setValueAtTime(frequency, noteStart);

            gainNode.gain.setValueAtTime(0.0001, noteStart);
            gainNode.gain.exponentialRampToValueAtTime(0.12, noteStart + 0.03);
            gainNode.gain.exponentialRampToValueAtTime(0.0001, noteEnd);

            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            oscillator.start(noteStart);
            oscillator.stop(noteEnd);
        });

        window.setTimeout(() => {
            audioContext.close().catch(() => {
                // Ignore audio context close errors.
            });
        }, 700);
    } catch (error) {
        // Ignore audio playback errors.
    }
}

async function showMessageNotification(message) {
    if (!message || Number(message.sender_id) === currentUserId) {
        return;
    }

    await playNotificationSound();

    if (document.visibilityState === 'visible' || !('Notification' in window) || Notification.permission !== 'granted') {
        return;
    }

    const registration = await navigator.serviceWorker.getRegistration().catch(() => null);
    const body = message.body ? message.body.slice(0, 120) : 'Sent you a voice note';

    if (registration) {
        registration.showNotification(otherUserName, {
            body,
            icon: '/icons/icon.svg',
            tag: `chat-${conversationUserId}`,
            renotify: true,
            data: { url: `/chat.php?user=${conversationUserId}` },
        }).catch(() => {
            // Ignore notification display errors.
        });
    }
}

function handleIncomingMessages(previousMessages, nextMessages) {
    const previousIds = new Set(previousMessages.map((message) => String(message.id)));
    const newInboundMessages = nextMessages.filter((message) =>
        !previousIds.has(String(message.id)) && Number(message.sender_id) === conversationUserId
    );

    if (newInboundMessages.length === 0) {
        return;
    }

    const newestInboundMessage = newInboundMessages[newInboundMessages.length - 1];
    showMessageNotification(newestInboundMessage);
}

const buttonIcons = {
    send: `
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M4 12 19 5l-3.5 14-4.5-5-7-.5Z"></path>
            <path d="M10.5 13.5 19 5"></path>
        </svg>`,
    mic: `
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M12 4.5a3 3 0 0 1 3 3v3.75a3 3 0 0 1-6 0V7.5a3 3 0 0 1 3-3Z"></path>
            <path d="M18 11.25a6 6 0 0 1-12 0"></path>
            <path d="M12 17.25V20"></path>
            <path d="M9.5 20h5"></path>
        </svg>`,
    stop: `
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <rect x="7" y="7" width="10" height="10" rx="1.5"></rect>
        </svg>`,
};

function escapeHtml(value) {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function detectTextDirection(value) {
    const text = String(value || '').trim();
    if (!text) {
        return 'ltr';
    }

    const firstStrongCharacter = text.match(/[\u0591-\u07FF\uFB1D-\uFDFD\uFE70-\uFEFCA-Za-z]/);
    if (!firstStrongCharacter) {
        return 'ltr';
    }

    const rtlPattern = /[\u0591-\u07FF\uFB1D-\uFDFD\uFE70-\uFEFC]/;
    return rtlPattern.test(firstStrongCharacter[0]) ? 'rtl' : 'ltr';
}

function updateComposerDirection() {
    const direction = detectTextDirection(bodyEl.value);
    bodyEl.dir = direction;
    bodyEl.classList.toggle('rtl', direction === 'rtl');
    bodyEl.classList.toggle('ltr', direction !== 'rtl');
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
        showHint('Type a message or tap the microphone for a voice note.');
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
        delivered_at: null,
        read_at: null,
        created_at: now.toISOString(),
        created_at_label: `${now.toISOString().slice(0, 19).replace('T', ' ')} UTC`,
        pending: true,
    };
}

function isNearBottom() {
    return messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight < 50;
}

function deliveryState(message) {
    if (!message || Number(message.sender_id) !== currentUserId || message.pending) {
        return '';
    }

    if (message.read_at) {
        return 'read';
    }

    if (message.delivered_at) {
        return 'delivered';
    }

    return 'sent';
}

function renderDeliveryTicks(message) {
    const state = deliveryState(message);
    if (state === '') {
        return '';
    }

    const singleTick = `
        <svg viewBox="0 0 16 10" aria-hidden="true" focusable="false">
            <path d="M1.6 5.4 4.9 8.4 14.4 1.6" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"></path>
        </svg>`;
    const doubleTick = `
        <svg viewBox="0 0 16 10" aria-hidden="true" focusable="false">
            <path d="M1.1 5.4 4.4 8.4 8.8 3.8" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"></path>
            <path d="M5.8 5.4 9.1 8.4 14.4 1.6" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"></path>
        </svg>`;
    const icon = state === 'sent' ? singleTick : doubleTick;

    return `<span class="delivery-ticks ${state === 'read' ? 'read' : ''}" aria-label="${state}">${icon}</span>`;
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
    const previousMessages = window.__messagesState || [];
    window.__messagesState = messages;
    const signature = JSON.stringify(messages.map((message) => [
        message.id,
        message.created_at,
        message.delivered_at || '',
        message.read_at || '',
        Boolean(message.pending),
        message.body || '',
        message.audio_path || '',
    ]));
    if (signature === renderedSignature) {
        return;
    }

    const wasNearBottom = isNearBottom();
    renderedSignature = signature;

    if (messages.length === 0) {
        messagesEl.innerHTML = '<div class="empty-state">No messages yet. Say hi or tap the microphone to send a voice note.</div>';
    } else {
        messagesEl.innerHTML = messages.map((message) => {
            const isMine = Number(message.sender_id) === currentUserId;
            const textDirection = detectTextDirection(message.body || '');
            const body = message.body
                ? `<div class="message-text ${textDirection}" dir="${textDirection}">${escapeHtml(message.body).replace(/\n/g, '<br>')}</div>`
                : '';
            const audio = message.audio_path
                ? `<audio controls preload="none" src="/media.php?message=${Number(message.id)}"></audio>`
                : '';
            const pendingLabel = message.pending ? ' · Sending…' : '';
            const ticks = renderDeliveryTicks(message);

            return `
                <article class="message ${isMine ? 'mine' : ''}">
                    ${body}
                    ${audio}
                    <div class="meta"><span class="meta-label">${escapeHtml(message.sender_name)} · ${escapeHtml(message.created_at_label)}${pendingLabel}</span>${ticks}</div>
                </article>`;
        }).join('');
    }

    if (shouldAutoScroll || wasNearBottom) {
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    handleIncomingMessages(previousMessages, messages);
}

function updateActionButton() {
    if (recordingMode) {
        actionButton.innerHTML = buttonIcons.stop;
        actionButton.classList.add('recording');
        actionButton.setAttribute('aria-label', 'Stop recording and send voice note');
        return;
    }

    const hasText = bodyEl.value.trim() !== '';
    actionButton.innerHTML = hasText ? buttonIcons.send : buttonIcons.mic;
    actionButton.classList.remove('recording');
    if (hasText) {
        actionButton.setAttribute('aria-label', 'Send message');
    } else if (supportsInlineVoiceRecording()) {
        actionButton.setAttribute('aria-label', 'Start voice recording');
    } else {
        actionButton.setAttribute('aria-label', 'Record or upload a voice note');
    }
}

function applyConversationPayload(payload) {
    if (Array.isArray(payload.messages)) {
        renderMessages(payload.messages);
    } else if (payload.message) {
        replacePendingMessage(payload.pending_id || '', payload.message);
        upsertMessage(payload.message);
    }
    if (payload.presence) {
        updatePresence(payload.presence.is_online, payload.presence.label);
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
        syncReadStateSoon();
    } catch (error) {
        // Ignore transient refresh errors.
    }
}

async function syncReadState() {
    if (document.visibilityState !== 'visible') {
        return;
    }

    const hasUnreadInbound = (window.__messagesState || []).some((message) =>
        Number(message.sender_id) === conversationUserId && !message.read_at
    );

    if (!hasUnreadInbound) {
        return;
    }

    try {
        const response = await fetch(`/chat_api.php?user=${conversationUserId}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
            body: new URLSearchParams({ action: 'read' }),
        });

        if (!response.ok) {
            return;
        }

        applyConversationPayload(await response.json());
    } catch (error) {
        // Ignore transient read sync errors.
    }
}

function syncReadStateSoon() {
    if (readSyncTimer !== null) {
        window.clearTimeout(readSyncTimer);
    }

    readSyncTimer = window.setTimeout(() => {
        readSyncTimer = null;
        syncReadState();
    }, 120);
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
    updateComposerDirection();
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
        shouldAutoScroll = true;
        showHint('Message sent.');
    } catch (error) {
        removeMessage(pendingMessage.id);
        bodyEl.value = body;
        autoResizeComposer();
        updateComposerDirection();
        showError('Could not send message right now. Please try again.');
    } finally {
        isSending = false;
        actionButton.disabled = false;
        updateActionButton();
        bodyEl.focus();
    }
}

function detectAudioMimeType() {
    const candidates = [
        'audio/webm;codecs=opus',
        'audio/webm',
        'audio/ogg;codecs=opus',
        'audio/ogg',
        'audio/mp4',
        'audio/mp4;codecs=mp4a.40.2',
        'audio/x-m4a',
        'video/webm;codecs=opus',
        'video/webm',
        'video/ogg;codecs=opus',
        'video/ogg',
    ];

    for (const type of candidates) {
        if (window.MediaRecorder && typeof MediaRecorder.isTypeSupported === 'function' && MediaRecorder.isTypeSupported(type)) {
            return type;
        }
    }

    return '';
}

function createMediaRecorder(stream) {
    const preferredType = detectAudioMimeType();
    const candidateOptions = preferredType ? [{ mimeType: preferredType }, {}] : [{}];
    let lastError = null;

    for (const options of candidateOptions) {
        try {
            return new MediaRecorder(stream, options);
        } catch (error) {
            lastError = error;
        }
    }

    throw lastError || new Error('MediaRecorder could not be started.');
}

async function uploadVoiceBlob(blob, filename) {
    if (!(blob instanceof Blob) || blob.size === 0) {
        showError('The voice note was empty. Please try again.');
        return false;
    }

    try {
        const formData = new FormData();
        formData.append('action', 'send_voice');
        formData.append('voice_note', blob, filename);

        const response = await fetch(`/chat_api.php?user=${conversationUserId}`, {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            body: formData,
        });
        const payload = await response.json();

        if (!response.ok) {
            showError(payload.error || 'Could not send voice note.');
            return false;
        }

        applyConversationPayload(payload);
        messagesEl.scrollTop = messagesEl.scrollHeight;
        shouldAutoScroll = true;
        showHint('Voice note sent.');
        return true;
    } catch (error) {
        showError('Could not send voice note right now. Please try again.');
        return false;
    }
}

async function sendSelectedVoiceFile(file) {
    if (!(file instanceof File) || file.size === 0) {
        showError('Please choose a voice note to send.');
        return;
    }

    showHint('Uploading voice note…');
    actionButton.disabled = true;
    isSending = true;

    try {
        await uploadVoiceBlob(file, file.name || 'voice-note');
    } finally {
        isSending = false;
        actionButton.disabled = false;
        voiceFileInput.value = '';
        updateActionButton();
    }
}

async function openVoiceFallbackPicker() {
    if (!supportsCapturedVoiceUpload() || isSending) {
        showError('Voice capture is not available on this device/browser.');
        return;
    }

    showHint('Choose or record a voice note, then send it here.');
    voiceFileInput.click();
}

async function startRecording() {
    if (bodyEl.value.trim() !== '' || isSending || recordingMode) {
        return;
    }
    if (!supportsInlineVoiceRecording()) {
        await openVoiceFallbackPicker();
        return;
    }

    try {
        mediaStream = await navigator.mediaDevices.getUserMedia({
            audio: {
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true,
            },
        });
        mediaRecorder = createMediaRecorder(mediaStream);
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

        mediaRecorder.start(250);
        updateActionButton();
        showHint('Recording… tap the button again to send your voice note.');
        statusState = 'recording';
        statusMessage = 'Recording voice note…';
        renderStatus();
    } catch (error) {
        const message = error instanceof Error && error.message
            ? error.message
            : 'Microphone permission is required to send a voice note.';
        showError(`Could not start the in-app recorder. ${message}`);
    }
}

async function stopRecordingAndSend() {
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
        showHint('Recording was too short. Tap the microphone and speak a little longer.');
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
        if (typeof recorder.requestData === 'function' && recorder.state === 'recording') {
            recorder.requestData();
        }
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
        await uploadVoiceBlob(blob, `voice-note.${extension}`);
    } finally {
        isSending = false;
        actionButton.disabled = false;
        updateActionButton();
    }
}

async function toggleRecording() {
    if (recordingMode) {
        await stopRecordingAndSend();
        return;
    }

    if (!supportsInlineVoiceRecording()) {
        await openVoiceFallbackPicker();
        return;
    }

    await startRecording();
}

voiceFileInput.addEventListener('change', async () => {
    markUserInteraction();
    const [file] = voiceFileInput.files || [];
    if (file) {
        await sendSelectedVoiceFile(file);
    }
});

bodyEl.addEventListener('input', () => {
    markUserInteraction();
    autoResizeComposer();
    updateComposerDirection();
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

messagesEl.addEventListener('scroll', () => {
    shouldAutoScroll = isNearBottom();
    if (shouldAutoScroll) {
        syncReadStateSoon();
    }
});

actionButton.addEventListener('click', async () => {
    markUserInteraction();
    if (bodyEl.value.trim() !== '') {
        sendTextMessage();
        return;
    }

    if (isSending) {
        return;
    }

    await toggleRecording();
});

document.addEventListener('click', markUserInteraction, { passive: true });
document.addEventListener('keydown', markUserInteraction, { passive: true });

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // Ignore service worker registration errors.
        });
    });
}

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
updateComposerDirection();
updateActionButton();
renderMessages(initialMessages);
updatePresence(initialPresence, initialPresenceLabel);
renderStatus();
connectConversationStream();
syncReadStateSoon();

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

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
        syncReadStateSoon();
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
