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
            --menu-surface: rgba(255, 255, 255, 0.98);
            --menu-hover: rgba(7, 94, 84, 0.08);
            --keyboard-offset: 0px;
            --composer-height: 74px;
            --composer-clearance: 6px;
            --composer-wrap-start: rgba(239, 234, 226, 0);
            --composer-wrap-end: rgba(239, 234, 226, 1);
        }
        :root[data-theme="dark"] {
            color-scheme: dark;
            --bg: #0b141a;
            --panel: #111b21;
            --header: #202c33;
            --composer: #202c33;
            --mine: #144d37;
            --theirs: #202c33;
            --text: #e9edef;
            --muted: #8696a0;
            --action: #25d366;
            --action-active: #1da851;
            --danger: #f97066;
            --shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
            --menu-surface: rgba(17, 27, 33, 0.98);
            --menu-hover: rgba(255, 255, 255, 0.08);
            --composer-wrap-start: rgba(11, 20, 26, 0);
            --composer-wrap-end: rgba(11, 20, 26, 1);
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
            background: var(--bg);
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
        .header-menu {
            position: relative;
            flex: 0 0 auto;
        }
        .header-menu-panel {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            min-width: 216px;
            padding: 8px;
            border-radius: 18px;
            background: var(--menu-surface);
            box-shadow: 0 20px 40px rgba(17, 27, 33, 0.24);
            display: flex;
            flex-direction: column;
            gap: 4px;
            opacity: 0;
            transform: translateY(-8px) scale(0.98);
            transform-origin: top right;
            pointer-events: none;
            transition: opacity 0.18s ease, transform 0.18s ease;
        }
        .header-menu-panel.is-open {
            opacity: 1;
            transform: translateY(0) scale(1);
            pointer-events: auto;
        }
        .header-menu-item {
            width: 100%;
            border: none;
            border-radius: 14px;
            background: transparent;
            color: var(--text);
            padding: 12px 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            font-weight: 700;
            text-align: left;
            cursor: pointer;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .header-menu-item:hover,
        .header-menu-item:focus-visible {
            background: var(--menu-hover);
        }
        .header-menu-item.danger {
            color: var(--danger);
        }
        .header-menu-item:disabled {
            opacity: 0.65;
            cursor: wait;
        }
        .header-menu-item.hidden {
            display: none;
        }
        .header-menu-item svg {
            width: 18px;
            height: 18px;
            stroke: currentColor;
            stroke-width: 2;
            fill: none;
            stroke-linecap: round;
            stroke-linejoin: round;
            flex-shrink: 0;
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
        .header-members-trigger {
            display: inline-flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 0;
            width: 100%;
            min-width: 0;
            padding: 0;
            border: none;
            background: transparent;
            color: inherit;
            font: inherit;
            text-align: left;
            cursor: pointer;
        }
        .header-members-trigger.hidden {
            display: block;
            cursor: default;
        }
        .header-members-trigger:focus-visible {
            outline: 2px solid rgba(255,255,255,0.7);
            outline-offset: 6px;
            border-radius: 12px;
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
        .message-row {
            max-width: min(88%, 460px);
            word-break: break-word;
        }
        .empty-state {
            max-width: 100%;
            align-self: center;
            background: rgba(255,255,255,0.72);
            color: var(--muted);
            text-align: center;
            margin-top: 16px;
            border-radius: 18px;
            padding: 10px 12px;
            box-shadow: 0 2px 6px rgba(17, 27, 33, 0.06);
        }
        .message-row {
            display: flex;
            align-self: flex-start;
            position: relative;
        }
        .message-row.mine {
            align-self: flex-end;
        }
        .message-row.private-audio {
            width: min(88%, 460px);
        }
        .message-row.private-audio .message {
            width: 100%;
        }
        .message {
            background: var(--theirs);
            border-radius: 18px;
            padding: 10px 12px;
            box-shadow: 0 2px 6px rgba(17, 27, 33, 0.06);
            min-width: 0;
        }
        .message-reactions {
            position: absolute;
            bottom: -10px;
            left: 10px;
            display: inline-flex;
            align-items: center;
            gap: 2px;
            border-radius: 999px;
            padding: 2px 6px;
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid rgba(17, 27, 33, 0.14);
            box-shadow: 0 2px 6px rgba(17, 27, 33, 0.12);
            font-size: 13px;
            line-height: 1;
            color: #111b21;
            cursor: pointer;
        }
        .message-row.mine .message-reactions {
            right: 10px;
            left: auto;
            background: rgba(220, 248, 198, 0.95);
        }
        .reaction-picker {
            position: fixed;
            z-index: 30;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.98);
            box-shadow: 0 14px 30px rgba(17, 27, 33, 0.2);
            border: 1px solid rgba(17, 27, 33, 0.12);
        }
        .reaction-picker[hidden] {
            display: none;
        }
        .reaction-picker button {
            width: 32px;
            height: 32px;
            border: none;
            border-radius: 50%;
            background: transparent;
            font-size: 18px;
            line-height: 1;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .reaction-picker button.is-active {
            background: rgba(37, 211, 102, 0.22);
        }
        .reaction-picker button.reaction-remove {
            font-size: 14px;
            color: var(--danger);
        }
        .message-row.mine .message {
            background: var(--mine);
        }
        .message-sender {
            margin: 0 0 4px;
            font-size: 13px;
            font-weight: 700;
            color: #0f766e;
        }
        .message-row.mine .message-sender {
            color: #0b5d54;
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
        .message-text.muted {
            color: var(--muted);
            font-style: italic;
        }
        .message audio {
            width: 100%;
            margin-top: 6px;
        }
        .meta {
            margin-top: 8px;
            font-size: 11px;
            color: var(--muted);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 4px;
            text-align: left;
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
            background: linear-gradient(180deg, var(--composer-wrap-start) 0%, color-mix(in srgb, var(--composer-wrap-end) 96%, transparent) 18%, var(--composer-wrap-end) 45%);
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
        .attachment-menu-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }
        .attachment-menu {
            position: absolute;
            right: 0;
            bottom: calc(100% + 10px);
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 150px;
            padding: 10px;
            border-radius: 18px;
            background: var(--menu-surface);
            box-shadow: var(--shadow);
            z-index: 3;
        }
        .attachment-menu[hidden] {
            display: none;
        }
        .attachment-menu-option {
            border: none;
            background: transparent;
            color: var(--text);
            border-radius: 12px;
            padding: 10px 12px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font: inherit;
            cursor: pointer;
            text-align: left;
        }
        .attachment-menu-option:hover,
        .attachment-menu-option:focus-visible {
            background: var(--menu-hover);
        }
        .attachment-menu-option:disabled {
            opacity: 0.55;
            cursor: not-allowed;
        }
        .attachment-menu-option svg {
            width: 18px;
            height: 18px;
            stroke: currentColor;
            stroke-width: 2;
            fill: none;
            stroke-linecap: round;
            stroke-linejoin: round;
            flex-shrink: 0;
        }
        .attachment-menu-option-label {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .attachment-menu-option-label strong {
            font-size: 14px;
            font-weight: 700;
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
        .attachment-trigger {
            width: 34px;
            height: 34px;
            flex: 0 0 34px;
            border-radius: 12px;
            background: transparent;
            color: var(--muted);
            box-shadow: none;
        }
        .attachment-trigger:active,
        .attachment-trigger:focus-visible {
            background: transparent;
        }
        .attachment-trigger svg {
            width: 20px;
            height: 20px;
            transform: rotate(90deg);
            transform-origin: 50% 50%;
        }
        .message-file {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 10px;
            padding: 12px 14px;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.12);
            color: inherit;
            text-decoration: none;
        }
        .message-file-icon {
            font-size: 1.1rem;
            line-height: 1;
        }
        .message-file-copy {
            display: flex;
            flex-direction: column;
            gap: 2px;
            min-width: 0;
        }
        .message-file-copy strong,
        .message-file-copy span {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .message-file-copy span {
            opacity: 0.72;
            font-size: 0.78rem;
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
            background: rgba(0, 0, 0, 0.92);
        }
        .lightbox-inner {
            position: relative;
            width: min(100%, 980px);
            max-height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
            padding: 18px;
            border-radius: 28px;
            background: #fff;
            color: #111b21;
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
            background: #111b21;
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
            background: #24313a;
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
        .member-picker[hidden] {
            display: none;
        }
        .member-picker {
            position: fixed;
            inset: 0;
            z-index: 40;
            background: rgba(0, 0, 0, 0.86);
            display: flex;
            align-items: flex-end;
            justify-content: center;
            padding: 16px;
        }
        .member-picker-panel {
            width: min(100%, 420px);
            max-height: min(72vh, 560px);
            background: #fff;
            color: #111b21;
            border-radius: 24px;
            box-shadow: var(--shadow);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .member-picker-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 18px 18px 12px;
        }
        .member-picker-header h2 {
            margin: 0;
            font-size: 18px;
        }
        .member-picker-close {
            border: none;
            background: #dfe5e7;
            color: #244047;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            cursor: pointer;
        }
        .member-picker-search {
            padding: 0 18px 14px;
        }
        .member-picker-search input {
            width: 100%;
            border: 1px solid #cdd5d9;
            border-radius: 14px;
            padding: 12px 14px;
            font-size: 14px;
            background: #fff;
            color: var(--text);
        }
        .member-picker-list {
            overflow-y: auto;
            padding: 0 14px 14px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .member-picker-empty {
            margin: 0 18px 18px;
            padding: 14px 16px;
            border-radius: 16px;
            background: #fff;
            color: var(--muted);
            font-size: 14px;
            text-align: center;
        }
        .member-picker-item {
            width: 100%;
            border: none;
            border-radius: 18px;
            background: #fff;
            padding: 12px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            text-align: left;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(17, 27, 33, 0.06);
        }
        .member-picker-item:disabled {
            opacity: 0.65;
            cursor: wait;
        }
        .member-picker-copy {
            min-width: 0;
        }
        .member-picker-copy strong {
            display: block;
            font-size: 15px;
        }
        .member-picker-copy span {
            display: block;
            margin-top: 4px;
            font-size: 13px;
            color: var(--muted);
        }
        .member-role-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 6px 10px;
            background: rgba(7, 94, 84, 0.12);
            color: var(--header);
            font-size: 12px;
            font-weight: 700;
            text-transform: capitalize;
            flex-shrink: 0;
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
                <?php if ($isGroupConversation): ?>
                    <button id="group-members-button" class="header-members-trigger" type="button" aria-haspopup="dialog" aria-controls="group-members-modal">
                        <h1 id="header-title"><?= e((string) $group['name']) ?></h1>
                        <div class="presence-row">
                            <span class="presence-light" id="header-presence-light" aria-hidden="true"></span>
                            <span id="header-presence-label"><?= e(count(groupMembers((int) $group['id'])) . ' members') ?></span>
                        </div>
                    </button>
                <?php else: ?>
                    <div id="group-members-button" class="header-members-trigger hidden">
                        <h1 id="header-title"><?= e($otherUser['username']) ?></h1>
                        <div class="presence-row">
                            <span class="presence-light <?= !empty($otherUser['is_online']) ? 'online' : '' ?>" id="header-presence-light" aria-hidden="true"></span>
                            <span id="header-presence-label">Offline</span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="header-menu">
                <button
                    id="header-menu-button"
                    class="header-icon-button"
                    type="button"
                    aria-label="Conversation actions"
                    aria-expanded="false"
                    aria-controls="header-menu-panel"
                    title="Conversation actions"
                >
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <circle cx="12" cy="5" r="1.8" fill="currentColor" stroke="none"></circle>
                        <circle cx="12" cy="12" r="1.8" fill="currentColor" stroke="none"></circle>
                        <circle cx="12" cy="19" r="1.8" fill="currentColor" stroke="none"></circle>
                    </svg>
                </button>
                <div id="header-menu-panel" class="header-menu-panel" role="menu" aria-label="Conversation actions" hidden>
                    <button
                        id="rename-group-button"
                        class="header-menu-item<?= $isGroupConversation && (int) $group['creator_user_id'] === (int) $user['id'] ? '' : ' hidden' ?>"
                        type="button"
                        role="menuitem"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M12 20h9"></path>
                            <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"></path>
                        </svg>
                        <span>Edit group name</span>
                    </button>
                    <button
                        id="add-group-member-button"
                        class="header-menu-item<?= $isGroupConversation ? '' : ' hidden' ?>"
                        type="button"
                        role="menuitem"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                            <path d="M19 8v6"></path>
                            <path d="M22 11h-6"></path>
                        </svg>
                        <span>Add user</span>
                    </button>
                    <button
                        id="revoke-friendship-button"
                        class="header-menu-item danger<?= !$isGroupConversation && $friendship !== null && $friendship['status'] === 'accepted' ? '' : ' hidden' ?>"
                        type="button"
                        role="menuitem"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M15 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                            <path d="M17 11h4"></path>
                        </svg>
                        <span>Revoke friendship</span>
                    </button>
                    <button
                        id="delete-conversation-button"
                        class="header-menu-item danger<?= $isGroupConversation ? ' hidden' : '' ?>"
                        type="button"
                        role="menuitem"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M4 7h16"></path>
                            <path d="M10 11v6"></path>
                            <path d="M14 11v6"></path>
                            <path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12"></path>
                            <path d="M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"></path>
                        </svg>
                        <span>Delete messages</span>
                    </button>
                    <button
                        id="leave-group-button"
                        class="header-menu-item<?= $isGroupConversation ? '' : ' hidden' ?>"
                        type="button"
                        role="menuitem"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                            <path d="m16 17 5-5-5-5"></path>
                            <path d="M21 12H9"></path>
                        </svg>
                        <span>Leave group</span>
                    </button>
                    <button
                        id="delete-group-button"
                        class="header-menu-item danger<?= $isGroupConversation && (int) $group['creator_user_id'] === (int) $user['id'] ? '' : ' hidden' ?>"
                        type="button"
                        role="menuitem"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M4 7h16"></path>
                            <path d="M10 11v6"></path>
                            <path d="M14 11v6"></path>
                            <path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12"></path>
                            <path d="M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"></path>
                        </svg>
                        <span>Delete group</span>
                    </button>
                </div>
            </div>
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

        <div id="member-picker" class="member-picker" aria-hidden="true" hidden>
            <div class="member-picker-panel" role="dialog" aria-modal="true" aria-labelledby="member-picker-title">
                <div class="member-picker-header">
                    <h2 id="member-picker-title">Add friends</h2>
                    <button class="member-picker-close" id="member-picker-close" type="button" aria-label="Close member picker">×</button>
                </div>
                <div class="member-picker-search">
                    <input id="member-picker-search-input" type="search" placeholder="Search friends by name" autocomplete="off" aria-label="Search friends by name">
                </div>
                <div class="member-picker-list" id="member-picker-list"></div>
                <p class="member-picker-empty" id="member-picker-empty" hidden>No friends are available to add right now.</p>
            </div>
        </div>


        <div id="group-members-modal" class="member-picker" aria-hidden="true" hidden>
            <div class="member-picker-panel" role="dialog" aria-modal="true" aria-labelledby="group-members-title">
                <div class="member-picker-header">
                    <h2 id="group-members-title">Group members</h2>
                    <button class="member-picker-close" id="group-members-close" type="button" aria-label="Close group members">×</button>
                </div>
                <div class="member-picker-list" id="group-members-list"></div>
                <p class="member-picker-empty" id="group-members-empty" hidden>No members found.</p>
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
                    <input id="file-input" type="file" style="display:none">
                    <input id="image-file-input" type="file" accept="image/*" style="display:none">
                    <input id="voice-file-input" type="file" accept="audio/*" capture="microphone" style="display:none">
                    <div class="attachment-menu-wrap">
                        <button id="attachment-button" class="composer-icon-button attachment-trigger" type="button" aria-label="Open attachment options" aria-expanded="false" aria-controls="attachment-menu"<?= $canChat ? '' : ' disabled' ?>>
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M21.44 11.05 12.25 20.24a6 6 0 0 1-8.49-8.49l9.9-9.9a4.5 4.5 0 1 1 6.36 6.36l-9.9 9.9a3 3 0 0 1-4.24-4.24l8.48-8.49"></path>
                            </svg>
                        </button>
                        <div id="attachment-menu" class="attachment-menu" hidden>
                            <button id="attachment-gallery-option" class="attachment-menu-option" type="button">
                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                    <path d="M4 7.5A2.5 2.5 0 0 1 6.5 5h11A2.5 2.5 0 0 1 20 7.5v9A2.5 2.5 0 0 1 17.5 19h-11A2.5 2.5 0 0 1 4 16.5v-9Z"></path>
                                    <path d="m8 15 2.5-2.5L13 15l2.5-3 2.5 3"></path>
                                    <circle cx="9" cy="9" r="1.25"></circle>
                                </svg>
                                <span class="attachment-menu-option-label">
                                    <strong>Gallery</strong>
                                </span>
                            </button>
                            <button id="attachment-document-option" class="attachment-menu-option" type="button">
                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                    <path d="M14 3H7.5A2.5 2.5 0 0 0 5 5.5v13A2.5 2.5 0 0 0 7.5 21h9a2.5 2.5 0 0 0 2.5-2.5V8Z"></path>
                                    <path d="M14 3v5h5"></path>
                                    <path d="M9 13h6"></path>
                                    <path d="M9 17h4"></path>
                                </svg>
                                <span class="attachment-menu-option-label">
                                    <strong>Document</strong>
                                </span>
                            </button>
                        </div>
                    </div>
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
const currentUsername = <?= jsonScriptValue((string) $user['username']) ?>;
const conversationUserId = <?= (int) $otherUserId ?>;
const groupId = <?= (int) $groupId ?>;
const isGroupConversation = <?= $isGroupConversation ? 'true' : 'false' ?>;
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
let conversationDisplayName = <?= jsonScriptValue($isGroupConversation ? (string) $group['name'] : $otherUser['username']) ?>;
const messageBatchSize = <?= (int) $messageBatchSize ?>;
const initialMessages = <?= jsonScriptValue($messages) ?>;
const initialHasMoreMessages = <?= $hasMoreMessages ? 'true' : 'false' ?>;
const initialTypingMembers = <?= jsonScriptValue($typingMembers) ?>;
const initialCanChat = <?= $canChat ? 'true' : 'false' ?>;
const initialFriendship = <?= jsonScriptValue($friendship) ?>;
const initialGroup = <?= jsonScriptValue($group) ?>;
const initialPresence = <?= !$isGroupConversation && !empty($otherUser['is_online']) ? 'true' : 'false' ?>;
const initialPresenceUpdatedAt = <?= jsonScriptValue($isGroupConversation ? null : ($otherUser['presence_updated_at'] ?? null)) ?>;
const preferPolling = <?= PHP_SAPI === 'cli-server' ? 'true' : 'false' ?>;
const initialConversationSignature = <?= jsonScriptValue($initialConversationSignature) ?>;
const FAST_POLL_INTERVAL_MS = 2500;
const MAX_POLL_INTERVAL_MS = 12000;
const FAST_HOME_POLL_INTERVAL_MS = 4000;
const MAX_HOME_POLL_INTERVAL_MS = 15000;
const AUTO_SCROLL_THRESHOLD_PX = 72;
const SCROLL_TO_END_VISIBILITY_THRESHOLD_PX = 280;
const POPULAR_REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];
const messagesEl = document.getElementById('messages');
const statusRowEl = document.getElementById('status-row');
const bodyEl = document.getElementById('message-body');
const actionButton = document.getElementById('action-button');
const attachmentButton = document.getElementById('attachment-button');
const attachmentMenu = document.getElementById('attachment-menu');
const attachmentGalleryOption = document.getElementById('attachment-gallery-option');
const attachmentDocumentOption = document.getElementById('attachment-document-option');
const fileInput = document.getElementById('file-input');
const imageFileInput = document.getElementById('image-file-input');
const voiceFileInput = document.getElementById('voice-file-input');
const headerPresenceLight = document.getElementById('header-presence-light');
const headerPresenceLabel = document.getElementById('header-presence-label');
const headerTitle = document.getElementById('header-title');
const headerMenuButton = document.getElementById('header-menu-button');
const headerMenuPanel = document.getElementById('header-menu-panel');
const deleteConversationButton = document.getElementById('delete-conversation-button');
const revokeFriendshipButton = document.getElementById('revoke-friendship-button');
const addGroupMemberButton = document.getElementById('add-group-member-button');
const leaveGroupButton = document.getElementById('leave-group-button');
const deleteGroupButton = document.getElementById('delete-group-button');
const renameGroupButton = document.getElementById('rename-group-button');
const themeStorageKey = 'localchat:theme';
const rootEl = document.documentElement;

function applyTheme(theme) {
    const nextTheme = theme === 'dark' ? 'dark' : 'light';
    rootEl.setAttribute('data-theme', nextTheme);
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', nextTheme === 'dark' ? '#202c33' : '#075e54');
}

(function loadStoredTheme() {
    try {
        const storedTheme = window.localStorage.getItem(themeStorageKey);
        if (storedTheme === 'dark' || storedTheme === 'light') {
            applyTheme(storedTheme);
            return;
        }
    } catch (error) {
        // Ignore storage access errors.
    }
    applyTheme('light');
})();
const memberPickerEl = document.getElementById('member-picker');
const memberPickerClose = document.getElementById('member-picker-close');
const memberPickerListEl = document.getElementById('member-picker-list');
const memberPickerSearchInput = document.getElementById('member-picker-search-input');
const memberPickerEmptyEl = document.getElementById('member-picker-empty');
const groupMembersButton = document.getElementById('group-members-button');
const groupMembersModal = document.getElementById('group-members-modal');
const groupMembersClose = document.getElementById('group-members-close');
const groupMembersListEl = document.getElementById('group-members-list');
const groupMembersEmptyEl = document.getElementById('group-members-empty');
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
let reactionPickerEl = null;
let reactionPickerMessageId = 0;
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

function setHeaderMenuOpen(isOpen) {
    if (!headerMenuButton || !headerMenuPanel) {
        return;
    }

    headerMenuButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    if (isOpen) {
        headerMenuPanel.hidden = false;
        requestAnimationFrame(() => {
            headerMenuPanel.classList.add('is-open');
        });
        return;
    }

    headerMenuPanel.classList.remove('is-open');
    if (!isOpen) {
        window.setTimeout(() => {
            if (!headerMenuPanel.classList.contains('is-open')) {
                headerMenuPanel.hidden = true;
            }
        }, 180);
    }
}

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

function availableGroupInviteCandidates() {
    const memberIds = new Set(Array.isArray(groupState?.members) ? groupState.members.map((member) => String(member.user_id)) : []);
    const query = String(memberPickerSearchInput?.value || '').trim().toLowerCase();

    return directoryUsersState.filter((user) => {
        if (!user || !user.can_chat || memberIds.has(String(user.id))) {
            return false;
        }

        if (query === '') {
            return true;
        }

        return String(user.username || '').toLowerCase().includes(query);
    });
}

function renderMemberPicker() {
    if (!memberPickerListEl || !memberPickerEmptyEl) {
        return;
    }

    const candidates = availableGroupInviteCandidates();
    memberPickerListEl.innerHTML = candidates.map((user) => `
        <button class="member-picker-item" type="button" data-group-invite-user-id="${Number(user.id)}">
            <div class="member-picker-copy">
                <strong>${escapeHtml(user.username || '')}</strong>
                <span>${escapeHtml(user.presence_label || 'Friend')}</span>
            </div>
            <span class="mini-button primary">Add</span>
        </button>
    `).join('');

    const query = String(memberPickerSearchInput?.value || '').trim();
    memberPickerEmptyEl.textContent = query === ''
        ? 'Only your friends who are not already in this group appear here.'
        : 'No friends match your search.';
    memberPickerEmptyEl.hidden = candidates.length > 0;
}

function renderGroupMembers() {
    if (!groupMembersListEl || !groupMembersEmptyEl) {
        return;
    }

    const members = Array.isArray(groupState?.members) ? [...groupState.members] : [];
    members.sort((left, right) => {
        if (String(left.role || '') === 'creator' && String(right.role || '') !== 'creator') {
            return -1;
        }
        if (String(left.role || '') !== 'creator' && String(right.role || '') === 'creator') {
            return 1;
        }
        return String(left.username || '').localeCompare(String(right.username || ''));
    });

    groupMembersListEl.innerHTML = members.map((member) => `
        <div class="member-picker-item">
            <div class="member-picker-copy">
                <strong>${escapeHtml(member.username || '')}</strong>
                <span>${escapeHtml(member.presence_label || 'Member')}</span>
            </div>
            ${String(member.role || '') === 'creator' ? '<span class="member-role-chip">Creator</span>' : ''}
        </div>
    `).join('');

    groupMembersEmptyEl.hidden = members.length > 0;
}

function setGroupMembersOpen(isOpen) {
    if (!groupMembersModal) {
        return;
    }

    groupMembersModal.hidden = !isOpen;
    groupMembersModal.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
    document.body.style.overflow = isOpen ? 'hidden' : '';

    if (isOpen) {
        renderGroupMembers();
        groupMembersClose?.focus();
    }
}

function setMemberPickerOpen(isOpen) {
    if (!memberPickerEl) {
        return;
    }

    memberPickerEl.hidden = !isOpen;
    memberPickerEl.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
    document.body.style.overflow = isOpen ? 'hidden' : '';

    if (isOpen) {
        renderMemberPicker();
        memberPickerSearchInput?.focus();
    } else if (memberPickerSearchInput) {
        memberPickerSearchInput.value = '';
    }
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

function supportsFileUpload() {
    return Boolean(fileInput);
}

function supportsImageUpload() {
    return Boolean(imageFileInput);
}

function isAttachmentMenuOpen() {
    return attachmentMenu instanceof HTMLElement && !attachmentMenu.hidden;
}

function setAttachmentMenuOpen(isOpen) {
    if (!(attachmentMenu instanceof HTMLElement) || !(attachmentButton instanceof HTMLButtonElement)) {
        return;
    }

    const nextOpen = Boolean(isOpen) && !attachmentButton.disabled;
    attachmentMenu.hidden = !nextOpen;
    attachmentButton.setAttribute('aria-expanded', nextOpen ? 'true' : 'false');
}

function parseIsoTimestamp(value) {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? null : date;
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
    const timeLabel = new Intl.DateTimeFormat(undefined, {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).format(date);

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

function updatePresence(isOnline, updatedAt = null) {
    if (isGroupConversation) {
        headerPresenceLight.classList.remove('online');
        headerPresenceLabel.textContent = `${groupState?.member_count || 0} members`;
        return;
    }

    if (!canChat) {
        actionButton.disabled = true;
    }

    otherUserOnline = Boolean(isOnline);
    headerPresenceLight.classList.toggle('online', otherUserOnline);
    headerPresenceLabel.textContent = formatPresenceLabel(otherUserOnline, updatedAt);
}

function updateFriendshipUi() {
    if (isGroupConversation) {
        canChat = true;
        actionButton.disabled = activeUploadCount > 0;
        attachmentButton.disabled = activeUploadCount > 0;
        attachmentGalleryOption.disabled = activeUploadCount > 0;
        attachmentDocumentOption.disabled = activeUploadCount > 0;
        if (activeUploadCount > 0) {
            setAttachmentMenuOpen(false);
        }
        bodyEl.disabled = false;
        return;
    }

    const isAccepted = Boolean(friendshipState && friendshipState.status === 'accepted');
    canChat = isAccepted;
    actionButton.disabled = !canChat || activeUploadCount > 0;
    attachmentButton.disabled = !canChat || activeUploadCount > 0;
    attachmentGalleryOption.disabled = !canChat || activeUploadCount > 0;
    attachmentDocumentOption.disabled = !canChat || activeUploadCount > 0;
    if (!canChat || activeUploadCount > 0) {
        setAttachmentMenuOpen(false);
    }
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
    const body = message.body
        ? message.body.slice(0, 120)
        : (message.image_path ? 'Sent you an image' : (message.file_path ? 'Sent you a file' : (message.audio_path ? 'Sent you a voice note' : (message.attachment_expired ? 'Sent an attachment that has expired' : 'New message'))));

    if (registration) {
        registration.showNotification(conversationDisplayName, {
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
        : (value ? [{ username: conversationDisplayName }] : []);
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

function formatHumanTimestamp(value) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return escapeHtml(String(value || ''));
    }

    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const targetDay = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    const diffDays = Math.round((targetDay.getTime() - today.getTime()) / 86400000);
    const timeLabel = new Intl.DateTimeFormat(undefined, {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).format(date);

    if (diffDays === 0) {
        return `Today ${timeLabel}`;
    }

    if (diffDays === -1) {
        return `Yesterday ${timeLabel}`;
    }

    if (diffDays === 1) {
        return `Tomorrow ${timeLabel}`;
    }

    const dateLabel = new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        year: date.getFullYear() === now.getFullYear() ? undefined : 'numeric',
    }).format(date);

    return `${dateLabel} ${timeLabel}`;
}

function renderMessageReactions(message) {
    if (!Array.isArray(message.reactions) || message.reactions.length === 0) {
        return '';
    }

    const uniqueByUser = new Map();
    message.reactions.forEach((reaction) => {
        const userId = Number(reaction?.user_id || 0);
        const emoji = String(reaction?.emoji || '').trim();
        if (!userId || emoji === '') {
            return;
        }
        uniqueByUser.set(String(userId), emoji);
    });

    if (uniqueByUser.size === 0) {
        return '';
    }

    let emojiLabel = '';
    if (isGroupConversation) {
        const grouped = new Map();
        Array.from(uniqueByUser.values()).forEach((emoji) => {
            grouped.set(emoji, (grouped.get(emoji) || 0) + 1);
        });
        emojiLabel = Array.from(grouped.entries())
            .sort((left, right) => right[1] - left[1])
            .slice(0, 3)
            .map(([emoji, total]) => `${escapeHtml(emoji)} ${total > 1 ? total : ''}`.trim())
            .join(' ');
    } else {
        const emojis = Array.from(uniqueByUser.values()).slice(0, 3);
        emojiLabel = emojis.map((emoji) => escapeHtml(emoji)).join(' ');
    }

    return `<div class="message-reactions" aria-label="Reactions">${emojiLabel}</div>`;
}

async function setMessageReaction(messageId, emoji) {
    if (!Number(messageId)) {
        return;
    }

    try {
        const response = await fetch(conversationApiUrl(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken },
            body: new URLSearchParams({
                action: 'react',
                message_id: String(Number(messageId)),
                emoji: String(emoji || ''),
                csrf_token: csrfToken,
            }),
        });

        const payload = await response.json();
        if (!response.ok) {
            showError(payload?.error || 'Unable to save reaction.');
            return;
        }

        if (typeof payload.signature === 'string' && payload.signature !== '') {
            conversationSignature = payload.signature;
        }
        await refreshConversation();
    } catch (error) {
        showError('Unable to save reaction right now.');
    }
}

function ensureReactionPicker() {
    if (reactionPickerEl) {
        return reactionPickerEl;
    }

    const picker = document.createElement('div');
    picker.className = 'reaction-picker';
    picker.hidden = true;
    picker.setAttribute('role', 'menu');
    picker.setAttribute('aria-label', 'Choose a reaction');
    document.body.appendChild(picker);

    document.addEventListener('pointerdown', (event) => {
        if (!(event.target instanceof Node) || !reactionPickerEl || reactionPickerEl.hidden) {
            return;
        }
        if (reactionPickerEl.contains(event.target)) {
            return;
        }
        hideReactionPicker();
    });

    window.addEventListener('scroll', () => hideReactionPicker(), true);
    window.addEventListener('resize', () => hideReactionPicker());

    reactionPickerEl = picker;
    return picker;
}

function hideReactionPicker() {
    if (!reactionPickerEl) {
        return;
    }
    reactionPickerEl.hidden = true;
    reactionPickerEl.innerHTML = '';
    reactionPickerMessageId = 0;
}

function showReactionPicker(anchorEl, messageId, existingEmoji = '') {
    if (!(anchorEl instanceof HTMLElement) || !messageId) {
        return;
    }

    const picker = ensureReactionPicker();
    const options = [...POPULAR_REACTIONS];
    if (existingEmoji && !options.includes(existingEmoji)) {
        options.unshift(existingEmoji);
    }

    picker.innerHTML = options.map((emoji) => `
        <button type="button" data-emoji="${escapeHtml(emoji)}" class="${emoji === existingEmoji ? 'is-active' : ''}" aria-label="React ${escapeHtml(emoji)}">${escapeHtml(emoji)}</button>
    `).join('') + (existingEmoji ? '<button type="button" class="reaction-remove" data-emoji="" aria-label="Remove reaction">✕</button>' : '');

    picker.querySelectorAll('button[data-emoji]').forEach((buttonEl) => {
        buttonEl.addEventListener('click', () => {
            const emoji = String(buttonEl.getAttribute('data-emoji') || '');
            if (!reactionPickerMessageId) {
                return;
            }
            setMessageReaction(reactionPickerMessageId, emoji);
            hideReactionPicker();
        });
    });

    const anchorRect = anchorEl.getBoundingClientRect();
    picker.hidden = false;
    reactionPickerMessageId = messageId;

    requestAnimationFrame(() => {
        const pickerRect = picker.getBoundingClientRect();
        const minLeft = 8;
        const maxLeft = window.innerWidth - pickerRect.width - 8;
        const centeredLeft = anchorRect.left + (anchorRect.width / 2) - (pickerRect.width / 2);
        const left = Math.max(minLeft, Math.min(maxLeft, centeredLeft));
        const top = Math.max(8, anchorRect.top - pickerRect.height - 8);
        picker.style.left = `${left}px`;
        picker.style.top = `${top}px`;
    });
}

function reactionUserDisplayName(userId, message) {
    if (Number(userId) === currentUserId) {
        return `${currentUsername} (You)`;
    }
    if (!isGroupConversation) {
        return conversationDisplayName;
    }
    if (Number(message?.sender_id || 0) === Number(userId) && message?.sender_name) {
        return String(message.sender_name);
    }
    const memberName = Array.isArray(groupState?.members)
        ? String((groupState.members.find((member) => Number(member?.user_id || 0) === Number(userId))?.username) || '')
        : '';
    return memberName || `User #${Number(userId)}`;
}

function showReactionDetails(messageId) {
    const message = (window.__messagesState || []).find((item) => Number(item.id) === Number(messageId));
    if (!message || !Array.isArray(message.reactions) || message.reactions.length === 0) {
        return;
    }

    const lines = message.reactions.map((reaction) => {
        const userId = Number(reaction?.user_id || 0);
        const emoji = String(reaction?.emoji || '').trim();
        if (!userId || !emoji) {
            return '';
        }
        return `${emoji}  ${reactionUserDisplayName(userId, message)}`;
    }).filter(Boolean);

    if (lines.length === 0) {
        return;
    }

    window.alert(`Reactions\n\n${lines.join('\n')}`);
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
    hideReactionPicker();
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
        message.file_path || '',
        message.file_name || '',
        Boolean(message.attachment_expired),
        (Array.isArray(message.reactions) ? message.reactions : []).map((reaction) => `${Number(reaction?.user_id || 0)}:${String(reaction?.emoji || '')}`).join('|'),
    ]));
    if (signature === renderedSignature) {
        return;
    }

    renderedSignature = signature;

    if (messages.length === 0) {
        messagesEl.innerHTML = '<div class="empty-state">No messages yet. Say hi, share a file, share a photo, or tap the microphone to send a voice note.</div>';
    } else {
        messagesEl.innerHTML = messages.map((message) => {
            const isMine = Number(message.sender_id) === currentUserId;
            const shouldShowSender = isGroupConversation && !isMine;
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
            const file = message.file_path
                ? `<a class="message-file" href="media.php?message=${Number(message.id)}" download="${escapeHtml(message.file_name || `shared-file-${Number(message.id)}`)}"><span class="message-file-icon">📎</span><span class="message-file-copy"><strong>${escapeHtml(message.file_name || `shared-file-${Number(message.id)}`)}</strong><span>Download file</span></span></a>`
                : '';
            const expiredAttachment = message.attachment_expired
                ? '<div class="message-text muted" dir="auto">Attachment expired.</div>'
                : '';
            const pendingLabel = message.pending ? ' · Sending…' : '';
            const ticks = renderDeliveryTicks(message);
            const senderLabel = shouldShowSender
                ? `<div class="message-sender">${escapeHtml(message.sender_name)}</div>`
                : '';
            const timeLabel = formatHumanTimestamp(message.created_at);
            const reactions = renderMessageReactions(message);
            const myReaction = Array.isArray(message.reactions)
                ? String((message.reactions.find((reaction) => Number(reaction?.user_id) === currentUserId)?.emoji) || '')
                : '';

            const rowClasses = ['message-row'];
            if (isMine) {
                rowClasses.push('mine');
            }
            if (!isGroupConversation && message.audio_path) {
                rowClasses.push('private-audio');
            }

            return `
                <article class="${rowClasses.join(' ')}" data-message-id="${Number(message.id)}" data-sender-id="${Number(message.sender_id)}" data-my-reaction="${escapeHtml(myReaction)}">
                    <div class="message">
                        ${senderLabel}
                        ${body}
                        ${image}
                        ${audio}
                        ${file}
                        ${expiredAttachment}
                        <div class="meta"><span class="meta-label">${escapeHtml(timeLabel)}${pendingLabel}</span>${ticks}</div>
                    </div>
                    ${reactions}
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

        messagesEl.querySelectorAll('.message-row[data-message-id]').forEach((rowEl) => {
            const canReactToRow = () => {
                const senderId = Number(rowEl.getAttribute('data-sender-id') || 0);
                return senderId > 0 && senderId !== currentUserId;
            };
            const openReactionPickerFromTap = (event) => {
                if (!(event.target instanceof HTMLElement)) {
                    return;
                }
                if (event.target.closest('a, button, audio, input, textarea, label')) {
                    return;
                }
                if (event.target.closest('.message-reactions')) {
                    return;
                }
                if (!canReactToRow()) {
                    return;
                }

                const messageId = Number(rowEl.getAttribute('data-message-id') || 0);
                if (!messageId) {
                    return;
                }
                const existingEmoji = String(rowEl.getAttribute('data-my-reaction') || '');
                showReactionPicker(rowEl, messageId, existingEmoji);
            };

            rowEl.addEventListener('click', openReactionPickerFromTap);
            rowEl.addEventListener('contextmenu', (event) => {
                if (!canReactToRow()) {
                    return;
                }
                event.preventDefault();
                const messageId = Number(rowEl.getAttribute('data-message-id') || 0);
                if (!messageId) {
                    return;
                }
                const existingEmoji = String(rowEl.getAttribute('data-my-reaction') || '');
                showReactionPicker(rowEl, messageId, existingEmoji);
            });
            rowEl.querySelector('.message-reactions')?.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                const messageId = Number(rowEl.getAttribute('data-message-id') || 0);
                if (!messageId) {
                    return;
                }
                showReactionDetails(messageId);
            });
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
        conversationDisplayName = String(groupState.name || conversationDisplayName);
        if (headerTitle) {
            headerTitle.textContent = conversationDisplayName;
        }
        document.title = conversationDisplayName;
        updatePresence(false, `${groupState.member_count || 0} members`);
        deleteGroupButton?.classList.toggle('hidden', !Boolean(groupState.can_delete));
        renameGroupButton?.classList.toggle('hidden', !Boolean(groupState.can_rename));
    }
    if (payload.presence) {
        updatePresence(payload.presence.is_online, payload.presence.updated_at || null);
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

async function uploadSharedFile(file) {
    if (!(file instanceof File) || file.size === 0) {
        showError('Please choose a file to share.');
        return false;
    }

    if (file.size > 10 * 1024 * 1024) {
        showError('Files must be 10MB or smaller.');
        return false;
    }

    try {
        const formData = new FormData();
        formData.append('action', 'send_file');
        formData.append('csrf_token', csrfToken);
        formData.append('shared_file', file, file.name || 'shared-file');

        const response = await fetch(conversationApiUrl(), {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrfToken },
            body: formData,
        });
        const payload = await response.json();

        if (!response.ok) {
            showError(payload.error || 'Could not send file.');
            return false;
        }

        applyConversationPayload(payload);
        scrollMessagesToEnd();
        showHint('File sent.');
        return true;
    } catch (error) {
        showError('Could not send file right now. Please try again.');
        return false;
    }
}

async function sendSelectedFile(file) {
    if (!canChat) {
        showError('Friendship revoked. You cannot send new messages until you are friends again.');
        return;
    }

    if (!(file instanceof File) || file.size === 0) {
        showError('Please choose a file to share.');
        return;
    }

    showHint('Uploading file…');
    activeUploadCount += 1;
    isSending = true;
    updateFriendshipUi();

    try {
        await uploadSharedFile(file);
    } finally {
        activeUploadCount = Math.max(0, activeUploadCount - 1);
        isSending = textSendInFlight || pendingTextQueue.length > 0 || activeUploadCount > 0;
        fileInput.value = '';
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

attachmentButton.addEventListener('click', () => {
    markUserInteraction();
    if (!canChat || isSending || (!supportsFileUpload() && !supportsImageUpload())) {
        return;
    }
    setAttachmentMenuOpen(!isAttachmentMenuOpen());
});

attachmentDocumentOption.addEventListener('click', () => {
    markUserInteraction();
    if (!canChat || isSending || !supportsFileUpload()) {
        return;
    }
    setAttachmentMenuOpen(false);
    fileInput.click();
});

fileInput.addEventListener('change', async () => {
    markUserInteraction();
    const [file] = fileInput.files || [];
    if (file) {
        await sendSelectedFile(file);
    }
});

attachmentGalleryOption.addEventListener('click', () => {
    markUserInteraction();
    if (!canChat || isSending || !supportsImageUpload()) {
        return;
    }
    setAttachmentMenuOpen(false);
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
attachmentButton.addEventListener('pointerdown', preserveComposerFocus);
attachmentDocumentOption.addEventListener('pointerdown', preserveComposerFocus);
attachmentGalleryOption.addEventListener('pointerdown', preserveComposerFocus);
attachmentButton.addEventListener('mousedown', preserveComposerFocus);
attachmentDocumentOption.addEventListener('mousedown', preserveComposerFocus);
attachmentGalleryOption.addEventListener('mousedown', preserveComposerFocus);
attachmentButton.addEventListener('touchstart', preserveComposerFocus, { passive: false });
attachmentDocumentOption.addEventListener('touchstart', preserveComposerFocus, { passive: false });
attachmentGalleryOption.addEventListener('touchstart', preserveComposerFocus, { passive: false });

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

headerMenuButton?.addEventListener('click', (event) => {
    event.preventDefault();
    const isOpen = headerMenuButton.getAttribute('aria-expanded') === 'true';
    setHeaderMenuOpen(!isOpen);
});

deleteConversationButton?.addEventListener('click', async () => {
    markUserInteraction();
    setHeaderMenuOpen(false);
    if (isSending || activeUploadCount > 0) {
        return;
    }

    const confirmed = window.confirm(isGroupConversation
        ? 'Delete all messages in this group for your account only?'
        : `Delete all messages in this private chat for your account only? ${conversationDisplayName} will still keep their copy.`);
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
    setHeaderMenuOpen(false);
    if (!friendshipState || friendshipState.status !== 'accepted' || isSending) {
        return;
    }

    const confirmed = window.confirm(`Revoke friendship with ${conversationDisplayName}? Existing messages will stay, but both of you will not be able to send new messages.`);
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

groupMembersButton?.addEventListener('click', () => {
    if (!isGroupConversation) {
        return;
    }

    setHeaderMenuOpen(false);
    setGroupMembersOpen(true);
});

groupMembersClose?.addEventListener('click', () => setGroupMembersOpen(false));
groupMembersModal?.addEventListener('click', (event) => {
    if (event.target === groupMembersModal) {
        setGroupMembersOpen(false);
    }
});

addGroupMemberButton?.addEventListener('click', async () => {
    markUserInteraction();
    setHeaderMenuOpen(false);
    setMemberPickerOpen(true);
});

memberPickerClose?.addEventListener('click', () => setMemberPickerOpen(false));
memberPickerEl?.addEventListener('click', (event) => {
    if (event.target === memberPickerEl) {
        setMemberPickerOpen(false);
    }
});
memberPickerSearchInput?.addEventListener('input', () => {
    renderMemberPicker();
});
memberPickerListEl?.addEventListener('click', async (event) => {
    const target = event.target instanceof Element ? event.target.closest('[data-group-invite-user-id]') : null;
    if (!target) {
        return;
    }

    const userId = Number(target.getAttribute('data-group-invite-user-id') || 0);
    const candidate = directoryUsersState.find((entry) => Number(entry.id) === userId);
    if (!candidate || !userId) {
        return;
    }

    target.setAttribute('disabled', 'disabled');
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
        setMemberPickerOpen(false);
        showHint(`${candidate.username} was added to the group.`);
    } catch (error) {
        target.removeAttribute('disabled');
        showError(error.message || 'Could not add that user right now.');
    }
});

leaveGroupButton?.addEventListener('click', async () => {
    markUserInteraction();
    setHeaderMenuOpen(false);
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

renameGroupButton?.addEventListener('click', async () => {
    markUserInteraction();
    setHeaderMenuOpen(false);
    const nextName = window.prompt('Edit group name', conversationDisplayName);
    if (nextName === null) {
        return;
    }

    renameGroupButton.disabled = true;
    try {
        const response = await fetch(conversationApiUrl(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken },
            body: new URLSearchParams({ action: 'rename_group', name: nextName, csrf_token: csrfToken }),
        });
        const payload = await response.json();
        if (!response.ok || payload.error) {
            throw new Error(payload.error || 'Could not rename the group right now.');
        }

        applyConversationPayload(payload.payload || payload);
        showHint('Group name updated.');
    } catch (error) {
        showError(error.message || 'Could not rename the group right now.');
    } finally {
        renameGroupButton.disabled = false;
    }
});

deleteGroupButton?.addEventListener('click', async () => {
    markUserInteraction();
    setHeaderMenuOpen(false);
    if (!window.confirm(`Delete the group "${conversationDisplayName}" for everyone? This cannot be undone.`)) {
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
    if (event.key === 'Escape' && groupMembersModal && !groupMembersModal.hidden) {
        setGroupMembersOpen(false);
        return;
    }
    if (event.key === 'Escape' && memberPickerEl && !memberPickerEl.hidden) {
        setMemberPickerOpen(false);
        return;
    }
    if (event.key === 'Escape' && headerMenuButton?.getAttribute('aria-expanded') === 'true') {
        setHeaderMenuOpen(false);
        return;
    }
    if (event.key === 'Escape' && imageLightbox && !imageLightbox.hidden) {
        closeImageLightbox();
    }
}, { passive: true });
document.addEventListener('click', (event) => {
    if (!headerMenuPanel || !headerMenuButton) {
        return;
    }

    const target = event.target;
    if (target instanceof Node && (headerMenuPanel.contains(target) || headerMenuButton.contains(target))) {
        return;
    }

    setHeaderMenuOpen(false);
});
document.addEventListener('click', markUserInteraction, { passive: true });

document.addEventListener('click', (event) => {
    if (!isAttachmentMenuOpen()) {
        return;
    }
    const target = event.target;
    if (target instanceof Node && (attachmentMenu.contains(target) || attachmentButton.contains(target))) {
        return;
    }
    setAttachmentMenuOpen(false);
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        setAttachmentMenuOpen(false);
    }
});


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
updatePresence(initialPresence, initialPresenceUpdatedAt);
updateFriendshipUi();
renderStatus();
connectConversationStream();
syncReadStateSoon();
applyHomeNotificationPayload({
    chat_users: [{
        id: isGroupConversation ? groupId : conversationUserId,
        unseen_count: lastUnseenCounts.get(String(isGroupConversation ? groupId : conversationUserId)) || 0,
        url: conversationPageUrl(),
        name: conversationDisplayName,
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
