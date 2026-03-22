<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = requireAuth();
$groupId = requirePositiveInt($_GET, 'group');
$isGroupConversation = $groupId > 0;
$otherUserId = $isGroupConversation ? 0 : requirePositiveInt($_GET, 'user');
$otherUser = null;
$group = null;
$friendship = null;
$messageBatchSize = 15;
$typingMembers = [];
$availableInviteUsers = allOtherUsers((int) $user['id']);

if ($isGroupConversation) {
    $group = findGroupById($groupId);

    if ($group === null || !canAccessGroupConversation($groupId, (int) $user['id'])) {
        header('Location: index.php');
        exit;
    }

    $canChat = true;
    $messages = groupMessagesPageWithoutMaintenance($groupId, (int) $user['id'], $messageBatchSize);
    $hasMoreMessages = $messages !== [] && groupConversationHasOlderMessagesWithoutMaintenance($groupId, (int) $user['id'], (int) $messages[0]['id']);
    $typingMembers = groupTypingMembersWithoutMaintenance($groupId, (int) $user['id']);
    $initialConversationSignature = groupConversationStateSignature($groupId, (int) $user['id']);
} else {
    $otherUser = findUserById($otherUserId);

    if ($otherUser === null || $otherUser['id'] === $user['id'] || !canAccessConversation((int) $user['id'], $otherUserId)) {
        header('Location: index.php');
        exit;
    }

    $canChat = canUsersChat((int) $user['id'], $otherUserId);
    $friendship = friendshipRecord((int) $user['id'], $otherUserId);
    $messages = conversationMessagesPageWithoutMaintenance((int) $user['id'], $otherUserId, $messageBatchSize);
    $hasMoreMessages = $messages !== [] && conversationHasOlderMessagesWithoutMaintenance((int) $user['id'], $otherUserId, (int) $messages[0]['id']);
    $typingMembers = $canChat && isUserTyping((int) $user['id'], $otherUserId)
        ? [['user_id' => (int) $otherUser['id'], 'username' => (string) $otherUser['username']]]
        : [];
    $initialConversationSignature = conversationStateSignature((int) $user['id'], $otherUserId);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#075e54">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Local Chat">
    <link rel="manifest" href="manifest.json">
    <link rel="icon" href="icons/icon.svg" type="image/svg+xml">
    <meta name="csrf-token" content="<?= e(csrfToken()) ?>">
    <title><?= $isGroupConversation ? e((string) $group['name']) : 'Chat with ' . e($otherUser['username']) ?></title>
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
            --keyboard-offset: 0px;
            --composer-height: 74px;
            --composer-clearance: 6px;
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
            background: var(--bg);
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
        .header-icon-button {
            width: 40px;
            height: 40px;
            flex: 0 0 40px;
            border: none;
            border-radius: 50%;
            color: #dff6f1;
            background: rgba(11, 20, 26, 0.18);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.15s ease, transform 0.15s ease;
        }
        .header-icon-button:hover,
        .header-icon-button:focus-visible {
            background: rgba(11, 20, 26, 0.3);
        }
        .header-icon-button:active {
            transform: scale(0.96);
        }
        .header-icon-button:disabled {
            opacity: 0.65;
            cursor: wait;
        }
        .header-icon-button.hidden {
            visibility: hidden;
            pointer-events: none;
        }
        .header-icon-button svg {
            width: 20px;
            height: 20px;
            stroke: currentColor;
            stroke-width: 2;
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
            position: relative;
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
            padding: 14px 12px 0;
            overflow: hidden;
        }
        .messages {
            flex: 1;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding: 6px 4px calc(var(--composer-height) + var(--composer-clearance) + env(safe-area-inset-bottom, 0px));
            overscroll-behavior: contain;
            scroll-padding-bottom: calc(var(--composer-height) + var(--composer-clearance) - 18px);
        }
        .conversation-actions {
            position: absolute;
            right: 18px;
            bottom: calc(100% + 14px);
            z-index: 2;
            display: flex;
            justify-content: flex-end;
            pointer-events: none;
        }
        .scroll-to-end-button {
            border: 1px solid rgba(7, 94, 84, 0.16);
            width: 52px;
            height: 52px;
            border-radius: 18px;
            background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(232, 247, 243, 0.98));
            color: var(--header);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 14px 28px rgba(17, 27, 33, 0.16);
            cursor: pointer;
            opacity: 0;
            transform: translateY(10px);
            pointer-events: none;
            transition: opacity 0.2s ease, transform 0.2s ease, background 0.15s ease;
        }
        .scroll-to-end-button.visible {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }
        .scroll-to-end-button:hover,
        .scroll-to-end-button:focus-visible {
            background: linear-gradient(180deg, rgba(244,255,251,1), rgba(214, 242, 235, 1));
            border-color: rgba(7, 94, 84, 0.26);
        }
        .scroll-to-end-button svg {
            width: 24px;
            height: 24px;
            stroke: currentColor;
            stroke-width: 2.3;
            fill: none;
            stroke-linecap: round;
            stroke-linejoin: round;
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
        .friendship-card {
            margin: 14px 12px 0;
            border-radius: 18px;
            background: rgba(255,255,255,0.88);
            box-shadow: 0 2px 6px rgba(17, 27, 33, 0.06);
            padding: 14px 16px;
        }
        .friendship-card p {
            margin: 0;
            color: var(--muted);
            line-height: 1.5;
            font-size: 14px;
        }
        .status-row {
            min-height: 0;
            display: flex;
            align-items: center;
            padding: 0 4px 10px;
            flex-wrap: wrap;
        }
        .status-row:empty {
            padding-bottom: 0;
        }
        .typing-pill,
        .recording-pill,
        .hint-pill,
        .error-pill,
        .connecting-pill {
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
        .connecting-pill {
            background: rgba(7, 94, 84, 0.14);
            color: var(--header);
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
            flex-shrink: 0;
            padding: 10px 12px calc(10px + env(safe-area-inset-bottom, 0px));
            padding-bottom: calc(10px + env(safe-area-inset-bottom, 0px) + var(--keyboard-offset));
            transition: padding-bottom 0.2s ease;
            background: linear-gradient(180deg, rgba(239,234,226,0) 0%, rgba(239,234,226,0.96) 18%, rgba(239,234,226,1) 45%);
        }
        .composer-stack {
            position: relative;
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
        .composer-icon-button {
            border: none;
            width: 44px;
            height: 44px;
            flex: 0 0 44px;
            border-radius: 50%;
            background: #fff;
            color: var(--header);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(17, 27, 33, 0.1);
            transition: transform 0.15s ease, background 0.15s ease;
        }
        .composer-icon-button:active,
        .composer-icon-button:focus-visible {
            transform: scale(0.96);
            background: #f5f7fa;
        }
        .composer-icon-button svg {
            width: 22px;
            height: 22px;
            fill: none;
            stroke: currentColor;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .composer-icon-button:disabled {
            opacity: 0.7;
            cursor: wait;
        }
        .message-photo-button {
            display: block;
            border: none;
            padding: 0;
            margin: 2px 0 0;
            background: transparent;
            cursor: zoom-in;
            border-radius: 14px;
        }
        .message-photo-button:focus-visible {
            outline: 2px solid rgba(37, 211, 102, 0.9);
            outline-offset: 3px;
        }
        .message-photo {
            display: block;
            width: min(100%, 260px);
            border-radius: 14px;
            box-shadow: 0 2px 8px rgba(17, 27, 33, 0.12);
            background: rgba(0, 0, 0, 0.04);
        }
        .lightbox {
            position: fixed;
            inset: 0;
            z-index: 30;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: calc(20px + env(safe-area-inset-top, 0px)) 18px calc(20px + env(safe-area-inset-bottom, 0px));
            background: rgba(11, 20, 26, 0.0);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.24s ease, background 0.24s ease;
        }
        .lightbox.is-visible {
            opacity: 1;
            pointer-events: auto;
            background: rgba(11, 20, 26, 0.88);
        }
        .lightbox-inner {
            position: relative;
            width: min(100%, 980px);
            max-height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
            transform: translateY(24px) scale(0.94);
            transition: transform 0.28s cubic-bezier(0.2, 0.8, 0.2, 1);
        }
        .lightbox.is-visible .lightbox-inner {
            transform: translateY(0) scale(1);
        }
        .lightbox-toolbar {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
        }
        .lightbox-button {
            border: none;
            border-radius: 999px;
            background: rgba(255,255,255,0.14);
            color: #fff;
            min-height: 44px;
            padding: 12px 16px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font: inherit;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            transition: transform 0.15s ease, background 0.15s ease;
        }
        .lightbox-button:hover,
        .lightbox-button:focus-visible {
            background: rgba(255,255,255,0.22);
        }
        .lightbox-button:active {
            transform: scale(0.97);
        }
        .lightbox-button svg {
            width: 18px;
            height: 18px;
            stroke: currentColor;
            stroke-width: 2;
            fill: none;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .lightbox-figure {
            margin: 0;
            max-width: 100%;
            max-height: calc(100vh - 120px);
            max-height: calc(100dvh - 120px);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .lightbox-image {
            display: block;
            max-width: 100%;
            max-height: calc(100vh - 120px);
            max-height: calc(100dvh - 120px);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.42);
            object-fit: contain;
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
            <a class="back-link" href="index.php" aria-label="Back to chats">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M15 6l-6 6 6 6"></path>
                </svg>
            </a>
            <div class="topbar-meta">
                <h1><?= $isGroupConversation ? e((string) $group['name']) : e($otherUser['username']) ?></h1>
                <div class="presence-row">
                    <span class="presence-light <?= !$isGroupConversation && !empty($otherUser['is_online']) ? 'online' : '' ?>" id="header-presence-light" aria-hidden="true"></span>
                    <span id="header-presence-label"><?= $isGroupConversation ? e(count(groupMembers((int) $group['id'])) . ' members') : e($otherUser['presence_label'] ?? 'Offline') ?></span>
                </div>
            </div>
            <button
                id="add-group-member-button"
                class="header-icon-button<?= $isGroupConversation ? '' : ' hidden' ?>"
                type="button"
                aria-label="Add user to group"
                title="Add user to group"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M19 8v6"></path>
                    <path d="M22 11h-6"></path>
                </svg>
            </button>
            <button
                id="revoke-friendship-button"
                class="header-icon-button<?= !$isGroupConversation && $friendship !== null && $friendship['status'] === 'accepted' ? '' : ' hidden' ?>"
                type="button"
                aria-label="Revoke friendship"
                title="Revoke friendship"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M15 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M17 11h4"></path>
                </svg>
            </button>
            <button
                id="delete-conversation-button"
                class="header-icon-button<?= $isGroupConversation ? ' hidden' : '' ?>"
                type="button"
                aria-label="Delete messages from your view"
                title="Delete messages from your view"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M4 7h16"></path>
                    <path d="M10 11v6"></path>
                    <path d="M14 11v6"></path>
                    <path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12"></path>
                    <path d="M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"></path>
                </svg>
            </button>
            <button
                id="leave-group-button"
                class="header-icon-button<?= $isGroupConversation ? '' : ' hidden' ?>"
                type="button"
                aria-label="Leave group"
                title="Leave group"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                    <path d="m16 17 5-5-5-5"></path>
                    <path d="M21 12H9"></path>
                </svg>
            </button>
            <button
                id="delete-group-button"
                class="header-icon-button<?= $isGroupConversation && (int) $group['creator_user_id'] === (int) $user['id'] ? '' : ' hidden' ?>"
                type="button"
                aria-label="Delete group"
                title="Delete group"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M4 7h16"></path>
                    <path d="M10 11v6"></path>
                    <path d="M14 11v6"></path>
                    <path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12"></path>
                    <path d="M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"></path>
                </svg>
            </button>
        </header>

        <?php if (!$isGroupConversation && !$canChat): ?>
            <div class="friendship-card">
                <p>
                    <?php if ($friendship !== null && $friendship['status'] === 'pending' && $friendship['request_direction'] === 'outgoing'): ?>
                        Your friend request is pending. <?= e($otherUser['username']) ?> must accept it before you can start chatting.
                    <?php elseif ($friendship !== null && $friendship['status'] === 'pending'): ?>
                        <?= e($otherUser['username']) ?> already sent you a friend request. Go back home to accept it before chatting.
                    <?php else: ?>
                        You need to add <?= e($otherUser['username']) ?> as a friend and wait for acceptance before chatting.
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>

        <main class="conversation">
            <div class="messages" id="messages" aria-live="polite"></div>
        </main>

        <div id="image-lightbox" class="lightbox" aria-hidden="true" hidden>
            <div class="lightbox-inner" role="dialog" aria-modal="true" aria-label="Image viewer">
                <div class="lightbox-toolbar">
                    <a id="lightbox-download" class="lightbox-button" href="#" download>
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M12 3v12"></path>
                            <path d="m7 10 5 5 5-5"></path>
                            <path d="M5 21h14"></path>
                        </svg>
                        <span>Download</span>
                    </a>
                    <button id="lightbox-close" class="lightbox-button" type="button" aria-label="Close full screen image">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M18 6 6 18"></path>
                            <path d="m6 6 12 12"></path>
                        </svg>
                        <span>Close</span>
                    </button>
                </div>
                <figure class="lightbox-figure">
                    <img id="lightbox-image" class="lightbox-image" src="" alt="Full screen shared image">
                </figure>
            </div>
        </div>

        <div class="composer-wrap">
            <div class="status-row" id="status-row"></div>
            <div class="composer-stack">
                <div class="conversation-actions" aria-hidden="false">
                    <button id="scroll-to-end-button" class="scroll-to-end-button" type="button" aria-label="Scroll to latest message" title="Scroll to latest message">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M12 5v11"></path>
                            <path d="m7.5 12 4.5 4.5 4.5-4.5"></path>
                            <path d="M6 19h12"></path>
                        </svg>
                    </button>
                </div>
                <div class="composer">
                    <textarea id="message-body" rows="1" placeholder="Message"<?= $canChat ? '' : ' disabled' ?>></textarea>
                    <input id="image-file-input" type="file" accept="image/*" style="display:none">
                    <input id="voice-file-input" type="file" accept="audio/*" capture="microphone" style="display:none">
                    <button id="image-button" class="composer-icon-button" type="button" aria-label="Upload image or open camera"<?= $canChat ? '' : ' disabled' ?>>
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M4 7.5A2.5 2.5 0 0 1 6.5 5h11A2.5 2.5 0 0 1 20 7.5v9A2.5 2.5 0 0 1 17.5 19h-11A2.5 2.5 0 0 1 4 16.5v-9Z"></path>
                            <path d="m8 15 2.5-2.5L13 15l2.5-3 2.5 3"></path>
                            <circle cx="9" cy="9" r="1.25"></circle>
                        </svg>
                    </button>
                    <button id="action-button" class="action-button" type="button" aria-label="Send message or start voice recording"<?= $canChat ? '' : ' disabled' ?>>
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M12 4.5a3 3 0 0 1 3 3v3.75a3 3 0 0 1-6 0V7.5a3 3 0 0 1 3-3Z"></path>
                            <path d="M18 11.25a6 6 0 0 1-12 0"></path>
                            <path d="M12 17.25V20"></path>
                            <path d="M9.5 20h5"></path>
                        </svg>
                    </button>
                </div>
            </div>
        </div>

    </div>
</div>
<script>

const currentUserId = <?= (int) $user['id'] ?>;
const conversationUserId = <?= (int) $otherUserId ?>;
const groupId = <?= (int) $groupId ?>;
const isGroupConversation = <?= $isGroupConversation ? 'true' : 'false' ?>;
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
const otherUserName = <?= jsonScriptValue($isGroupConversation ? (string) $group['name'] : $otherUser['username']) ?>;
const messageBatchSize = <?= (int) $messageBatchSize ?>;
const initialMessages = <?= jsonScriptValue($messages) ?>;
const initialHasMoreMessages = <?= $hasMoreMessages ? 'true' : 'false' ?>;
const initialTypingMembers = <?= jsonScriptValue($typingMembers) ?>;
const initialCanChat = <?= $canChat ? 'true' : 'false' ?>;
const initialFriendship = <?= jsonScriptValue($friendship) ?>;
const initialGroup = <?= jsonScriptValue($group) ?>;
const initialPresence = <?= !$isGroupConversation && !empty($otherUser['is_online']) ? 'true' : 'false' ?>;
const initialPresenceLabel = <?= jsonScriptValue($isGroupConversation ? count(groupMembers((int) $group['id'])) . ' members' : ($otherUser['presence_label'] ?? 'Offline')) ?>;
const preferPolling = <?= PHP_SAPI === 'cli-server' ? 'true' : 'false' ?>;
const initialConversationSignature = <?= jsonScriptValue($initialConversationSignature) ?>;
const FAST_POLL_INTERVAL_MS = 2500;
const MAX_POLL_INTERVAL_MS = 12000;
const FAST_HOME_POLL_INTERVAL_MS = 4000;
const MAX_HOME_POLL_INTERVAL_MS = 15000;
const AUTO_SCROLL_THRESHOLD_PX = 72;
const SCROLL_TO_END_VISIBILITY_THRESHOLD_PX = 280;
const messagesEl = document.getElementById('messages');
const statusRowEl = document.getElementById('status-row');
const bodyEl = document.getElementById('message-body');
const actionButton = document.getElementById('action-button');
const imageButton = document.getElementById('image-button');
const imageFileInput = document.getElementById('image-file-input');
const voiceFileInput = document.getElementById('voice-file-input');
const headerPresenceLight = document.getElementById('header-presence-light');
const headerPresenceLabel = document.getElementById('header-presence-label');
const deleteConversationButton = document.getElementById('delete-conversation-button');
const revokeFriendshipButton = document.getElementById('revoke-friendship-button');
const addGroupMemberButton = document.getElementById('add-group-member-button');
const leaveGroupButton = document.getElementById('leave-group-button');
const deleteGroupButton = document.getElementById('delete-group-button');
const imageLightbox = document.getElementById('image-lightbox');
const lightboxImage = document.getElementById('lightbox-image');
const lightboxDownload = document.getElementById('lightbox-download');
const lightboxClose = document.getElementById('lightbox-close');
const scrollToEndButton = document.getElementById('scroll-to-end-button');
let lastFocusedElement = null;
let renderedSignature = '';
let localMessageCounter = 0;
let typingTimer = null;
let typingActive = false;
let isSending = false;
let activeUploadCount = 0;
let pendingTextQueue = [];
let textSendInFlight = false;
let mediaRecorder = null;
let mediaStream = null;
let recordingChunks = [];
let recordingStart = 0;
let recordingMode = false;
let stream = null;
let streamReconnectTimer = null;
let pollTimer = null;
let shouldAutoScroll = true;
let initialScrollPending = true;
let readSyncTimer = null;
let conversationSignature = initialConversationSignature;
let conversationPollDelay = FAST_POLL_INTERVAL_MS;
let homePayloadSignature = '';
let homePollTimer = null;
let homePollDelay = FAST_HOME_POLL_INTERVAL_MS;
let streamState = preferPolling ? 'polling' : 'connecting';
let typingMembers = Array.isArray(initialTypingMembers) ? initialTypingMembers : [];
let groupState = initialGroup;
let statusState = initialCanChat && typingMembers.length > 0 ? 'typing' : 'idle';
let statusMessage = typingMembers.length > 0 ? `${typingMembers.map((member) => member.username).join(', ')} typing…` : '';

function conversationApiUrl(action = '') {
    const params = new URLSearchParams();
    if (action) {
        params.set('action', action);
    }
    if (isGroupConversation) {
        params.set('group', String(groupId));
    } else {
        params.set('user', String(conversationUserId));
    }

    return `chat_api.php?${params.toString()}`;
}

function conversationPageUrl() {
    return isGroupConversation ? `chat.php?group=${groupId}` : `chat.php?user=${conversationUserId}`;
}

function conversationStreamUrl() {
    return isGroupConversation ? `chat_stream.php?group=${groupId}` : `chat_stream.php?user=${conversationUserId}`;
}

function clearStatus() {
    statusState = 'idle';
    statusMessage = '';
    renderStatus();
}
let otherUserOnline = initialPresence;
let hasInteracted = false;
let notificationPermissionRequested = false;
let notificationPermissionPromptDismissed = false;
let canChat = initialCanChat;
let suppressActionButtonClick = false;
let friendshipState = initialFriendship;
let hasMoreMessages = initialHasMoreMessages;
let loadingOlderMessages = false;
let lastUnseenCounts = new Map([[String(isGroupConversation ? groupId : conversationUserId), initialMessages.filter((message) =>
    Number(message.sender_id) !== currentUserId && !message.read_at
).length]]);
const webPushPublicKey = <?= jsonScriptValue(webPushPublicKey()) ?>;
const directoryUsersState = <?= jsonScriptValue($availableInviteUsers) ?>;
let pushSubscriptionSyncPromise = null;

function supportsInlineVoiceRecording() {
    return Boolean(navigator.mediaDevices?.getUserMedia && window.MediaRecorder);
}

function supportsCapturedVoiceUpload() {
    return Boolean(voiceFileInput);
}

function supportsImageUpload() {
    return Boolean(imageFileInput);
}

function updatePresence(isOnline, label) {
    if (isGroupConversation) {
        headerPresenceLight.classList.remove('online');
        headerPresenceLabel.textContent = label || `${groupState?.member_count || 0} members`;
        return;
    }

    if (!canChat) {
        actionButton.disabled = true;
    }

    otherUserOnline = Boolean(isOnline);
    headerPresenceLight.classList.toggle('online', otherUserOnline);
    headerPresenceLabel.textContent = label || (otherUserOnline ? 'Online' : 'Offline');
}

function updateFriendshipUi() {
    if (isGroupConversation) {
        canChat = true;
        actionButton.disabled = activeUploadCount > 0;
        imageButton.disabled = activeUploadCount > 0;
        bodyEl.disabled = false;
        return;
    }

    const isAccepted = Boolean(friendshipState && friendshipState.status === 'accepted');
    canChat = isAccepted;
    actionButton.disabled = !canChat || activeUploadCount > 0;
    imageButton.disabled = !canChat || activeUploadCount > 0;
    bodyEl.disabled = !canChat;
    revokeFriendshipButton.classList.toggle('hidden', !isAccepted);

    if (isAccepted) {
        if (statusState !== 'typing' && statusState !== 'recording' && !isSending) {
            clearStatus();
        }
        return;
    }

    if (typingActive) {
        typingActive = false;
        clearTimeout(typingTimer);
    }

    if (!isSending && statusState !== 'recording') {
        showHint('Friendship revoked. Message history is still visible, but sending is disabled.');
    }
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
    if (!webPushPublicKey || !('serviceWorker' in navigator) || !('PushManager' in window) || Notification.permission !== 'granted') {
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
            icon: 'icons/icon.svg',
            tag: `chat-${isGroupConversation ? groupId : conversationUserId}`,
            renotify: true,
            data: { url: conversationPageUrl() },
        }).catch(() => {
            // Ignore notification display errors.
        });
    }
}

async function showUnreadConversationNotification(chatUser, increaseCount) {
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
        data: { url: chatUser.url || `chat.php?user=${Number(chatUser.id)}` },
    }).catch(() => {
        // Ignore notification errors.
    });
}

function applyHomeNotificationPayload(payload) {
    if (typeof payload?.signature === 'string' && payload.signature !== '') {
        homePayloadSignature = payload.signature;
        homePollDelay = FAST_HOME_POLL_INTERVAL_MS;
    }

    const chatUsers = Array.isArray(payload?.chat_users) ? payload.chat_users : [];

    chatUsers.forEach((chatUser) => {
        const userId = String(chatUser.id);
        const nextUnseenCount = Number(chatUser.unseen_count || 0);
        const previousUnseenCount = lastUnseenCounts.get(userId) || 0;

        if (userId !== String(isGroupConversation ? groupId : conversationUserId) && nextUnseenCount > previousUnseenCount) {
            showUnreadConversationNotification(chatUser, nextUnseenCount - previousUnseenCount);
        }

        lastUnseenCounts.set(userId, nextUnseenCount);
    });
}

async function fetchHomeNotificationPayload() {
    const signatureResponse = await fetch('home_api.php?action=signature', {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        cache: 'no-store',
    });

    if (!signatureResponse.ok) {
        throw new Error(`Home signature request failed with ${signatureResponse.status}`);
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
        throw new Error(`Home request failed with ${response.status}`);
    }

    return response.json();
}

function stopHomePolling() {
    if (homePollTimer !== null) {
        window.clearTimeout(homePollTimer);
        homePollTimer = null;
    }
}

function scheduleHomePolling(delay = homePollDelay) {
    stopHomePolling();
    homePollTimer = window.setTimeout(async () => {
        homePollTimer = null;

        try {
            const payload = await fetchHomeNotificationPayload();
            if (payload) {
                applyHomeNotificationPayload(payload);
                homePollDelay = FAST_HOME_POLL_INTERVAL_MS;
            } else {
                homePollDelay = Math.min(MAX_HOME_POLL_INTERVAL_MS, homePollDelay + 2000);
            }
        } catch (error) {
            homePollDelay = Math.min(MAX_HOME_POLL_INTERVAL_MS, homePollDelay + 3000);
        }

        scheduleHomePolling(homePollDelay);
    }, delay);
}

function handleIncomingMessages(previousMessages, nextMessages) {
    const previousIds = new Set(previousMessages.map((message) => String(message.id)));
    const newInboundMessages = nextMessages.filter((message) =>
        !previousIds.has(String(message.id)) && Number(message.sender_id) !== currentUserId
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

function updateKeyboardOffset() {
    const viewport = window.visualViewport;
    if (!viewport) {
        document.documentElement.style.setProperty('--keyboard-offset', '0px');
        updateComposerClearance();
        return;
    }

    const keyboardOffset = Math.max(0, window.innerHeight - viewport.height - viewport.offsetTop);
    document.documentElement.style.setProperty('--keyboard-offset', `${Math.round(keyboardOffset)}px`);
    updateComposerClearance();
}

function updateComposerClearance() {
    const composerEl = document.querySelector('.composer');
    if (!(composerEl instanceof HTMLElement)) {
        return;
    }

    document.documentElement.style.setProperty('--composer-height', `${Math.max(64, composerEl.offsetHeight)}px`);
    document.documentElement.style.setProperty('--composer-clearance', '6px');
}

function renderStatus() {
    let html = '';

    if (statusState === 'typing') {
        html = `<span class="typing-pill">${escapeHtml(statusMessage)} <span class="dot-group" aria-hidden="true"><span class="dot"></span><span class="dot"></span><span class="dot"></span></span></span>`;
    } else if (statusState === 'recording') {
        html = `<span class="recording-pill">🔴 ${escapeHtml(statusMessage)}</span>`;
    } else if (statusState === 'error') {
        html = `<span class="error-pill">${escapeHtml(statusMessage)}</span>`;
    } else if (statusState === 'hint' && statusMessage !== '') {
        html = `<span class="hint-pill">${escapeHtml(statusMessage)}</span>`;
    }

    statusRowEl.innerHTML = html;
    updateComposerClearance();
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

function setTypingVisible(value) {
    if (recordingMode) {
        return;
    }

    const activeTypingMembers = Array.isArray(value)
        ? value
        : (value ? [{ username: otherUserName }] : []);
    typingMembers = activeTypingMembers;

    if (typingMembers.length > 0) {
        statusState = 'typing';
        if (typingMembers.length === 1) {
            statusMessage = `${typingMembers[0].username} is typing…`;
        } else {
            statusMessage = `${typingMembers.map((member) => member.username).join(', ')} are typing…`;
        }
    } else if (statusState === 'typing') {
        clearStatus();
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
        group_id: isGroupConversation ? groupId : null,
        sender_name: 'You',
        body,
        audio_path: null,
        image_path: null,
        delivered_at: null,
        read_at: null,
        created_at: now.toISOString(),
        created_at_label: `${now.toISOString().slice(0, 19).replace('T', ' ')} UTC`,
        pending: true,
    };
}

function isNearBottom() {
    return messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight < AUTO_SCROLL_THRESHOLD_PX;
}

function scrollMessagesToEnd(behavior = 'auto') {
    const latestMessage = messagesEl.lastElementChild;
    const composerWrap = document.querySelector('.composer-wrap');
    const composerHeight = composerWrap instanceof HTMLElement ? composerWrap.offsetHeight : 0;

    messagesEl.scrollTo({ top: messagesEl.scrollHeight + composerHeight, behavior });

    if (latestMessage instanceof HTMLElement) {
        latestMessage.scrollIntoView({ block: 'end', inline: 'nearest', behavior });
        messagesEl.scrollTo({ top: messagesEl.scrollHeight + composerHeight, behavior });
    }

    shouldAutoScroll = true;
    updateScrollToEndButton();
}

function ensureMessagesStayAtEnd() {
    if (!initialScrollPending) {
        return;
    }

    scrollMessagesToEnd();
    requestAnimationFrame(() => scrollMessagesToEnd());
    window.setTimeout(() => scrollMessagesToEnd(), 120);
}

function updateScrollToEndButton() {
    if (!scrollToEndButton) {
        return;
    }

    const distanceFromBottom = messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight;
    const shouldShowButton = distanceFromBottom > SCROLL_TO_END_VISIBILITY_THRESHOLD_PX;
    scrollToEndButton.classList.toggle('visible', shouldShowButton);
    scrollToEndButton.setAttribute('aria-hidden', shouldShowButton ? 'false' : 'true');
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

function mergeMessages(existingMessages, incomingMessages) {
    const merged = new Map();

    [...existingMessages, ...incomingMessages].forEach((message) => {
        merged.set(String(message.id), message);
    });

    return Array.from(merged.values()).sort((left, right) => {
        const leftTime = Date.parse(left.created_at) || 0;
        const rightTime = Date.parse(right.created_at) || 0;
        if (leftTime === rightTime) {
            return String(left.id).localeCompare(String(right.id));
        }
        return leftTime - rightTime;
    });
}

function removeMessage(messageId) {
    renderMessages((window.__messagesState || []).filter((item) => item.id !== messageId));
}


function openImageLightbox(src, filename) {
    if (!src || !imageLightbox || !lightboxImage || !lightboxDownload) {
        return;
    }

    lastFocusedElement = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    lightboxImage.src = src;
    lightboxDownload.href = src;
    lightboxDownload.setAttribute('download', filename || 'chat-image');
    imageLightbox.hidden = false;
    imageLightbox.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';

    requestAnimationFrame(() => {
        imageLightbox.classList.add('is-visible');
        lightboxClose?.focus();
    });
}

function closeImageLightbox() {
    if (!imageLightbox || imageLightbox.hidden) {
        return;
    }

    imageLightbox.classList.remove('is-visible');
    imageLightbox.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';

    window.setTimeout(() => {
        imageLightbox.hidden = true;
        lightboxImage.src = '';
        lightboxDownload.href = '#';
    }, 240);

    if (lastFocusedElement) {
        lastFocusedElement.focus();
    }
}

function renderMessages(messages) {
    const previousMessages = window.__messagesState || [];
    const shouldPinToBottom = shouldAutoScroll || isNearBottom() || initialScrollPending;
    window.__messagesState = messages;
    const signature = JSON.stringify(messages.map((message) => [
        message.id,
        message.created_at,
        message.delivered_at || '',
        message.read_at || '',
        Boolean(message.pending),
        message.body || '',
        message.audio_path || '',
        message.image_path || '',
    ]));
    if (signature === renderedSignature) {
        return;
    }

    renderedSignature = signature;

    if (messages.length === 0) {
        messagesEl.innerHTML = '<div class="empty-state">No messages yet. Say hi, share a photo, or tap the microphone to send a voice note.</div>';
    } else {
        messagesEl.innerHTML = messages.map((message) => {
            const isMine = Number(message.sender_id) === currentUserId;
            const textDirection = detectTextDirection(message.body || '');
            const body = message.body
                ? `<div class="message-text ${textDirection}" dir="${textDirection}">${escapeHtml(message.body).replace(/\n/g, '<br>')}</div>`
                : '';
            const image = message.image_path
                ? `<button class="message-photo-button" type="button" data-image-src="media.php?message=${Number(message.id)}" data-image-download="chat-image-${Number(message.id)}" aria-label="Open shared image full screen"><img class="message-photo" loading="lazy" src="media.php?message=${Number(message.id)}" alt="Shared image"></button>`
                : '';
            const audio = message.audio_path
                ? `<audio controls preload="none" src="media.php?message=${Number(message.id)}"></audio>`
                : '';
            const pendingLabel = message.pending ? ' · Sending…' : '';
            const ticks = renderDeliveryTicks(message);

            return `
                <article class="message ${isMine ? 'mine' : ''}">
                    ${body}
                    ${image}
                    ${audio}
                    <div class="meta"><span class="meta-label">${escapeHtml(message.sender_name)} · ${escapeHtml(message.created_at_label)}${pendingLabel}</span>${ticks}</div>
                </article>`;
        }).join('');

        messagesEl.querySelectorAll('img').forEach((imageEl) => {
            imageEl.addEventListener('load', () => {
                if (shouldAutoScroll || isNearBottom()) {
                    scrollMessagesToEnd();
                }
            }, { once: true });
        });

        messagesEl.querySelectorAll('audio').forEach((audioEl) => {
            audioEl.addEventListener('loadedmetadata', () => {
                if (shouldAutoScroll || isNearBottom()) {
                    scrollMessagesToEnd();
                }
            }, { once: true });
        });
    }

    if (shouldPinToBottom) {
        scrollMessagesToEnd();
    } else {
        updateScrollToEndButton();
    }

    handleIncomingMessages(previousMessages, messages);
    updateScrollToEndButton();
    ensureMessagesStayAtEnd();
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

function keepComposerFocused(force = false) {
    if (!canChat || bodyEl.disabled) {
        return;
    }

    if (!force && document.activeElement === bodyEl) {
        return;
    }

    requestAnimationFrame(() => {
        bodyEl.focus({ preventScroll: true });
        updateKeyboardOffset();
    });
}

function scheduleComposerFocusRestore() {
    keepComposerFocused(true);
    window.setTimeout(() => keepComposerFocused(true), 0);
    window.setTimeout(() => keepComposerFocused(true), 80);
}

function preserveComposerFocus(event) {
    if (!(event.target instanceof HTMLElement)) {
        return;
    }

    if (bodyEl.disabled || document.activeElement !== bodyEl) {
        return;
    }

    event.preventDefault();
}

function sendTextMessageFromActionPress(event) {
    const isPointerEvent = typeof PointerEvent !== 'undefined' && event instanceof PointerEvent;
    const isTouchLikePointer = isPointerEvent && event.pointerType !== 'mouse';
    const isTouchEvent = typeof TouchEvent !== 'undefined' && event instanceof TouchEvent;

    if (!isTouchLikePointer && !isTouchEvent) {
        return;
    }

    if (suppressActionButtonClick || bodyEl.disabled || document.activeElement !== bodyEl || bodyEl.value.trim() === '') {
        return;
    }

    event.preventDefault();
    suppressActionButtonClick = true;
    markUserInteraction();
    sendTextMessage();
    actionButton.blur();
    scheduleComposerFocusRestore();
}

function applyConversationPayload(payload, options = {}) {
    const { appendHistory = false } = options;
    const composerWasFocused = document.activeElement === bodyEl;

    if (typeof payload.signature === 'string' && payload.signature !== '') {
        conversationSignature = payload.signature;
        if (preferPolling) {
            conversationPollDelay = FAST_POLL_INTERVAL_MS;
        }
    }
    if (Object.prototype.hasOwnProperty.call(payload, 'has_more_messages')) {
        hasMoreMessages = Boolean(payload.has_more_messages);
    }
    if (Array.isArray(payload.messages)) {
        const nextMessages = appendHistory
            ? mergeMessages(payload.messages, window.__messagesState || [])
            : (payload.messages.length === 0 ? [] : mergeMessages(window.__messagesState || [], payload.messages));
        renderMessages(nextMessages);
        const unreadCount = nextMessages.filter((message) =>
            Number(message.sender_id) !== currentUserId && !message.read_at
        ).length;
        lastUnseenCounts.set(String(isGroupConversation ? groupId : conversationUserId), unreadCount);
    } else if (payload.message) {
        replacePendingMessage(payload.pending_id || '', payload.message);
        upsertMessage(payload.message);
    }
    if (payload.group) {
        groupState = payload.group;
        updatePresence(false, `${groupState.member_count || 0} members`);
        deleteGroupButton?.classList.toggle('hidden', !Boolean(groupState.can_delete));
    }
    if (payload.presence) {
        updatePresence(payload.presence.is_online, payload.presence.label);
    }
    if (Object.prototype.hasOwnProperty.call(payload, 'can_chat')) {
        canChat = Boolean(payload.can_chat);
    }
    if (Object.prototype.hasOwnProperty.call(payload, 'friendship')) {
        friendshipState = payload.friendship;
        updateFriendshipUi();
    }
    setTypingVisible(isGroupConversation ? (payload.typing_members || []) : (Boolean(payload.typing) && canChat));

    if (composerWasFocused) {
        keepComposerFocused(true);
    }
}

async function refreshConversation() {
    try {
        const signatureResponse = await fetch(conversationApiUrl('signature'), {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store',
        });
        if (!signatureResponse.ok) {
            return null;
        }

        const signaturePayload = await signatureResponse.json();
        if (typeof signaturePayload.signature !== 'string' || signaturePayload.signature === conversationSignature) {
            return false;
        }

        const response = await fetch(`${conversationApiUrl('messages')}&limit=${messageBatchSize}`, {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store',
        });
        if (!response.ok) {
            return null;
        }

        applyConversationPayload(await response.json());
        syncReadStateSoon();
        return true;
    } catch (error) {
        // Ignore transient refresh errors.
        return null;
    }
}


async function loadOlderMessages() {
    if (loadingOlderMessages || !hasMoreMessages) {
        return;
    }

    const currentMessages = window.__messagesState || [];
    const oldestMessage = currentMessages[0];
    const oldestId = Number(oldestMessage?.id || 0);
    if (!oldestId) {
        hasMoreMessages = false;
        return;
    }

    loadingOlderMessages = true;
    const previousScrollHeight = messagesEl.scrollHeight;

    try {
        const response = await fetch(`${conversationApiUrl('messages')}&limit=${messageBatchSize}&before=${oldestId}`, {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store',
        });
        if (!response.ok) {
            return;
        }

        applyConversationPayload(await response.json(), { appendHistory: true });
        requestAnimationFrame(() => {
            const nextScrollHeight = messagesEl.scrollHeight;
            messagesEl.scrollTop += nextScrollHeight - previousScrollHeight;
        });
    } catch (error) {
        // Ignore transient history loading errors.
    } finally {
        loadingOlderMessages = false;
    }
}

function scheduleConversationPoll(delay = conversationPollDelay) {
    stopPollingConversation();
    pollTimer = window.setTimeout(async () => {
        pollTimer = null;
        const didChange = await refreshConversation();

        if (didChange === true) {
            conversationPollDelay = FAST_POLL_INTERVAL_MS;
        } else if (didChange === false) {
            conversationPollDelay = Math.min(MAX_POLL_INTERVAL_MS, conversationPollDelay + 1500);
        } else {
            conversationPollDelay = Math.min(MAX_POLL_INTERVAL_MS, conversationPollDelay + 2500);
        }

        if (document.visibilityState === 'visible' && preferPolling) {
            scheduleConversationPoll(conversationPollDelay);
        }
    }, delay);
}

async function syncReadState() {
    if (document.visibilityState !== 'visible') {
        return;
    }

    const hasUnreadInbound = (window.__messagesState || []).some((message) =>
        Number(message.sender_id) !== currentUserId && !message.read_at
    );

    if (!hasUnreadInbound) {
        return;
    }

    try {
        const response = await fetch(conversationApiUrl(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken },
            body: new URLSearchParams({ action: 'read', csrf_token: csrfToken }),
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

    streamState = 'polling';
    renderStatus();
    scheduleConversationPoll(0);
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
    streamState = 'connecting';
    renderStatus();

    if (stream) {
        stream.close();
    }

    stream = new EventSource(conversationStreamUrl());
    stream.addEventListener('open', () => {
        streamState = 'connected';
        renderStatus();
    });
    stream.addEventListener('conversation', (event) => {
        streamState = 'connected';
        renderStatus();
        try {
            applyConversationPayload(JSON.parse(event.data));
        } catch (error) {
            // Ignore malformed stream events and keep the connection alive.
        }
    });

    stream.onerror = () => {
        streamState = 'connecting';
        renderStatus();
        if (stream) {
            stream.close();
            stream = null;
        }
        scheduleStreamReconnect();
    };
}

async function syncTyping(isTyping) {
    try {
        await fetch(conversationApiUrl(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken },
            body: new URLSearchParams({ action: 'typing', typing: String(isTyping), csrf_token: csrfToken }),
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
    if (!canChat) {
        return;
    }

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

async function flushPendingTextQueue() {
    if (textSendInFlight || pendingTextQueue.length === 0) {
        return;
    }

    textSendInFlight = true;
    isSending = true;
    updateFriendshipUi();

    while (pendingTextQueue.length > 0) {
        const nextMessage = pendingTextQueue[0];

        try {
            const response = await fetch(conversationApiUrl(), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken },
                body: new URLSearchParams({ action: 'send_text', body: nextMessage.body, csrf_token: csrfToken }),
            });
            const payload = await response.json();

            if (!response.ok) {
                removeMessage(nextMessage.pendingId);
                pendingTextQueue.shift();
                bodyEl.value = nextMessage.body;
                autoResizeComposer();
                updateComposerDirection();
                keepComposerFocused(true);
                showError(payload.error || 'Could not send message.');
                continue;
            }

            replacePendingMessage(nextMessage.pendingId, payload.message);
            pendingTextQueue.shift();
            if (nextMessage.shouldPinToBottom) {
                scrollMessagesToEnd();
            }
            clearStatus();
        } catch (error) {
            showError('Could not send message right now. Please try again.');
            break;
        }
    }

    textSendInFlight = false;
    isSending = pendingTextQueue.length > 0;
    updateFriendshipUi();
    updateActionButton();
}

function sendTextMessage() {
    const composerWasFocused = document.activeElement === bodyEl;
    const body = bodyEl.value.trim();
    if (!canChat) {
        showError('Friendship revoked. You cannot send new messages until you are friends again.');
        return;
    }
    if (!body) {
        return;
    }

    const shouldPinToBottom = isNearBottom();
    const pendingMessage = createPendingMessage(body, 'text');
    pendingTextQueue.push({
        body,
        pendingId: pendingMessage.id,
        shouldPinToBottom,
    });
    upsertMessage(pendingMessage);
    shouldAutoScroll = shouldPinToBottom;
    bodyEl.value = '';
    autoResizeComposer();
    updateComposerClearance();
    updateKeyboardOffset();
    updateComposerDirection();
    typingActive = false;
    clearTimeout(typingTimer);
    syncTyping(false);
    showHint(pendingTextQueue.length > 1 ? 'Queued messages are sending…' : 'Sending message…');
    updateActionButton();

    if (composerWasFocused) {
        scheduleComposerFocusRestore();
    }

    flushPendingTextQueue();
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
        formData.append('csrf_token', csrfToken);
        formData.append('voice_note', blob, filename);

        const response = await fetch(conversationApiUrl(), {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrfToken },
            body: formData,
        });
        const payload = await response.json();

        if (!response.ok) {
            showError(payload.error || 'Could not send voice note.');
            return false;
        }

        applyConversationPayload(payload);
        scrollMessagesToEnd();
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
    activeUploadCount += 1;
    isSending = true;
    updateFriendshipUi();

    try {
        await uploadVoiceBlob(file, file.name || 'voice-note');
    } finally {
        activeUploadCount = Math.max(0, activeUploadCount - 1);
        isSending = textSendInFlight || pendingTextQueue.length > 0 || activeUploadCount > 0;
        voiceFileInput.value = '';
        updateFriendshipUi();
        updateActionButton();
    }
}

async function uploadImageFile(file) {
    if (!(file instanceof File) || file.size === 0) {
        showError('Please choose an image to send.');
        return false;
    }

    try {
        const formData = new FormData();
        formData.append('action', 'send_image');
        formData.append('csrf_token', csrfToken);
        formData.append('image_file', file, file.name || 'photo.jpg');

        const response = await fetch(conversationApiUrl(), {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrfToken },
            body: formData,
        });
        const payload = await response.json();

        if (!response.ok) {
            showError(payload.error || 'Could not send image.');
            return false;
        }

        applyConversationPayload(payload);
        scrollMessagesToEnd();
        showHint('Image sent.');
        return true;
    } catch (error) {
        showError('Could not send image right now. Please try again.');
        return false;
    }
}

async function sendSelectedImageFile(file) {
    if (!canChat) {
        showError('Friendship revoked. You cannot send new messages until you are friends again.');
        return;
    }
    if (!(file instanceof File) || file.size === 0) {
        showError('Please choose an image to send.');
        return;
    }

    showHint('Uploading image…');
    activeUploadCount += 1;
    isSending = true;
    updateFriendshipUi();

    try {
        await uploadImageFile(file);
    } finally {
        activeUploadCount = Math.max(0, activeUploadCount - 1);
        isSending = textSendInFlight || pendingTextQueue.length > 0 || activeUploadCount > 0;
        imageFileInput.value = '';
        updateFriendshipUi();
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
    if (!canChat) {
        showError('Friendship revoked. You cannot send new messages until you are friends again.');
        return;
    }
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
    activeUploadCount += 1;
    isSending = true;
    updateFriendshipUi();

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
        activeUploadCount = Math.max(0, activeUploadCount - 1);
        isSending = textSendInFlight || pendingTextQueue.length > 0 || activeUploadCount > 0;
        updateFriendshipUi();
        showError('The voice note was empty. Please try again.');
        return;
    }

    try {
        const mimeType = blob.type || recorder.mimeType || 'audio/webm';
        const extension = mimeType.includes('ogg') ? 'ogg' : ((mimeType.includes('mp4') || mimeType.includes('m4a')) ? 'm4a' : 'webm');
        await uploadVoiceBlob(blob, `voice-note.${extension}`);
    } finally {
        activeUploadCount = Math.max(0, activeUploadCount - 1);
        isSending = textSendInFlight || pendingTextQueue.length > 0 || activeUploadCount > 0;
        updateFriendshipUi();
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

imageButton.addEventListener('click', () => {
    markUserInteraction();
    if (!canChat || isSending || !supportsImageUpload()) {
        return;
    }
    imageFileInput.click();
});

imageFileInput.addEventListener('change', async () => {
    markUserInteraction();
    const [file] = imageFileInput.files || [];
    if (file) {
        await sendSelectedImageFile(file);
    }
});

voiceFileInput.addEventListener('change', async () => {
    markUserInteraction();
    const [file] = voiceFileInput.files || [];
    if (file) {
        await sendSelectedVoiceFile(file);
    }
});

actionButton.addEventListener('pointerdown', preserveComposerFocus);
actionButton.addEventListener('pointerdown', sendTextMessageFromActionPress);
actionButton.addEventListener('mousedown', preserveComposerFocus);
actionButton.addEventListener('touchstart', preserveComposerFocus, { passive: false });
actionButton.addEventListener('touchstart', sendTextMessageFromActionPress, { passive: false });
imageButton.addEventListener('pointerdown', preserveComposerFocus);
imageButton.addEventListener('mousedown', preserveComposerFocus);
imageButton.addEventListener('touchstart', preserveComposerFocus, { passive: false });

window.visualViewport?.addEventListener('resize', updateKeyboardOffset);
window.visualViewport?.addEventListener('scroll', updateKeyboardOffset);
window.addEventListener('resize', updateComposerClearance);
bodyEl.addEventListener('focus', updateKeyboardOffset);
bodyEl.addEventListener('blur', () => {
    window.setTimeout(updateKeyboardOffset, 150);
});

bodyEl.addEventListener('input', () => {
    markUserInteraction();
    autoResizeComposer();
    updateComposerClearance();
    updateComposerDirection();
    updateActionButton();
    markTyping();
    if (statusState === 'error' && bodyEl.value.trim() !== '') {
        clearStatus();
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

messagesEl.addEventListener('click', (event) => {
    const trigger = event.target instanceof Element ? event.target.closest('[data-image-src]') : null;
    if (!trigger) {
        return;
    }

    event.preventDefault();
    openImageLightbox(trigger.getAttribute('data-image-src') || '', trigger.getAttribute('data-image-download') || 'chat-image');
});

messagesEl.addEventListener('scroll', () => {
    shouldAutoScroll = isNearBottom();
    updateScrollToEndButton();
    if (messagesEl.scrollTop <= 24) {
        loadOlderMessages();
    }
    if (shouldAutoScroll) {
        syncReadStateSoon();
    }
});

scrollToEndButton?.addEventListener('click', () => {
    scrollMessagesToEnd('smooth');
    syncReadStateSoon();
});

deleteConversationButton?.addEventListener('click', async () => {
    markUserInteraction();
    if (isSending || activeUploadCount > 0) {
        return;
    }

    const confirmed = window.confirm(isGroupConversation
        ? 'Delete all messages in this group for your account only?'
        : `Delete all messages in this private chat for your account only? ${otherUserName} will still keep their copy.`);
    if (!confirmed) {
        return;
    }

    deleteConversationButton.disabled = true;

    try {
        const response = await fetch(conversationApiUrl(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken },
            body: new URLSearchParams({ action: 'delete_conversation', csrf_token: csrfToken }),
        });
        const payload = await response.json();

        if (!response.ok) {
            showError(payload.error || 'Could not delete messages right now.');
            return;
        }

        applyConversationPayload(payload.payload || payload);
        shouldAutoScroll = true;
        scrollMessagesToEnd();
        showHint(isGroupConversation
            ? 'Messages deleted only for your account. New group messages will still appear here.'
            : 'Messages deleted only for your account. New messages will still appear here.');
    } catch (error) {
        showError('Could not delete messages right now. Please try again.');
    } finally {
        deleteConversationButton.disabled = false;
    }
});

revokeFriendshipButton?.addEventListener('click', async () => {
    markUserInteraction();
    if (!friendshipState || friendshipState.status !== 'accepted' || isSending) {
        return;
    }

    const confirmed = window.confirm(`Revoke friendship with ${otherUserName}? Existing messages will stay, but both of you will not be able to send new messages.`);
    if (!confirmed) {
        return;
    }

    revokeFriendshipButton.disabled = true;

    try {
        const response = await fetch(conversationApiUrl(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken },
            body: new URLSearchParams({ action: 'revoke_friendship', csrf_token: csrfToken }),
        });
        const payload = await response.json();

        if (!response.ok) {
            showError(payload.error || 'Could not revoke friendship right now.');
            return;
        }

        applyConversationPayload(payload.payload || payload);
        showHint('Friendship revoked. Message history is still visible, but sending is disabled.');
    } catch (error) {
        showError('Could not revoke friendship right now. Please try again.');
    } finally {
        revokeFriendshipButton.disabled = false;
    }
});

addGroupMemberButton?.addEventListener('click', async () => {
    markUserInteraction();
    const username = window.prompt('Invite which user? Enter their username exactly.');
    if (username === null) {
        return;
    }

    const candidate = directoryUsersState.find((entry) => String(entry.username || '').toLowerCase() === username.trim().toLowerCase());
    if (!candidate) {
        window.alert('User not found.');
        return;
    }

    addGroupMemberButton.disabled = true;
    try {
        const response = await fetch('home_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', Accept: 'application/json', 'X-CSRF-Token': csrfToken },
            body: new URLSearchParams({ action: 'invite_group_member', group: String(groupId), user: String(candidate.id), csrf_token: csrfToken }),
        });
        const payload = await response.json();
        if (!response.ok || payload.error) {
            throw new Error(payload.error || 'Could not add that user right now.');
        }

        applyConversationPayload(payload.payload || payload);
        showHint(`${candidate.username} was added to the group.`);
    } catch (error) {
        showError(error.message || 'Could not add that user right now.');
    } finally {
        addGroupMemberButton.disabled = false;
    }
});

leaveGroupButton?.addEventListener('click', async () => {
    markUserInteraction();
    if (!window.confirm('Leave this group?')) {
        return;
    }

    leaveGroupButton.disabled = true;
    try {
        const response = await fetch(conversationApiUrl(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken },
            body: new URLSearchParams({ action: 'leave_group', csrf_token: csrfToken }),
        });
        const payload = await response.json();
        if (!response.ok || payload.error) {
            throw new Error(payload.error || 'Could not leave the group right now.');
        }

        window.location.href = 'index.php';
    } catch (error) {
        showError(error.message || 'Could not leave the group right now.');
    } finally {
        leaveGroupButton.disabled = false;
    }
});

deleteGroupButton?.addEventListener('click', async () => {
    markUserInteraction();
    if (!window.confirm(`Delete the group "${otherUserName}" for everyone? This cannot be undone.`)) {
        return;
    }

    deleteGroupButton.disabled = true;
    try {
        const response = await fetch(conversationApiUrl(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken },
            body: new URLSearchParams({ action: 'delete_group', csrf_token: csrfToken }),
        });
        const payload = await response.json();
        if (!response.ok || payload.error) {
            throw new Error(payload.error || 'Could not delete the group right now.');
        }

        window.location.href = 'index.php';
    } catch (error) {
        showError(error.message || 'Could not delete the group right now.');
    } finally {
        deleteGroupButton.disabled = false;
    }
});

actionButton.addEventListener('click', async (event) => {
    event.preventDefault();
    if (suppressActionButtonClick) {
        suppressActionButtonClick = false;
        return;
    }

    markUserInteraction();
    const composerWasFocused = document.activeElement === bodyEl;
    if (bodyEl.value.trim() !== '') {
        sendTextMessage();
        if (composerWasFocused) {
            actionButton.blur();
            scheduleComposerFocusRestore();
        }
        return;
    }

    if (isSending) {
        return;
    }

    await toggleRecording();
});

lightboxClose?.addEventListener('click', () => {
    closeImageLightbox();
});

imageLightbox?.addEventListener('click', (event) => {
    if (event.target === imageLightbox) {
        closeImageLightbox();
    }
});

document.addEventListener('keydown', (event) => {
    markUserInteraction();
    if (event.key === 'Escape' && imageLightbox && !imageLightbox.hidden) {
        closeImageLightbox();
    }
}, { passive: true });
document.addEventListener('click', markUserInteraction, { passive: true });

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

window.addEventListener('beforeunload', () => {
    if (stream) {
        stream.close();
    }
    stopPollingConversation();
    stopHomePolling();
    if (typingActive && navigator.sendBeacon) {
        const data = new URLSearchParams({ action: 'typing', typing: 'false', csrf_token: csrfToken });
        navigator.sendBeacon(conversationApiUrl(), data);
    }
});

autoResizeComposer();
updateComposerClearance();
updateKeyboardOffset();
updateComposerDirection();
updateActionButton();
renderMessages(initialMessages);
updateScrollToEndButton();
window.addEventListener('load', () => {
    ensureMessagesStayAtEnd();
    initialScrollPending = false;
}, { once: true });
updatePresence(initialPresence, initialPresenceLabel);
updateFriendshipUi();
renderStatus();
connectConversationStream();
syncReadStateSoon();
applyHomeNotificationPayload({
    chat_users: [{
        id: isGroupConversation ? groupId : conversationUserId,
        unseen_count: lastUnseenCounts.get(String(isGroupConversation ? groupId : conversationUserId)) || 0,
        url: conversationPageUrl(),
        name: otherUserName,
    }],
});
if (isGroupConversation) {
    applyConversationPayload({ group: initialGroup, typing_members: initialTypingMembers });
}
scheduleHomePolling();


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
