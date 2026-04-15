const homeBootstrap = JSON.parse(document.getElementById('home-bootstrap-data')?.textContent || '{}');
const currentUserId = homeBootstrap.currentUserId ?? null;
const initialChatUsers = homeBootstrap.initialChatUsers ?? [];
const initialDirectoryUsers = homeBootstrap.initialDirectoryUsers ?? [];
const initialIncomingRequests = homeBootstrap.initialIncomingRequests ?? [];
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
const webPushPublicKey = homeBootstrap.webPushPublicKey ?? null;
const initialHomeSignature = homeBootstrap.initialHomeSignature ?? '';
const preferPolling = Boolean(homeBootstrap.preferPolling);
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

const themeStorageKey = 'localchat:theme';
const rootEl = document.documentElement;
const settingsMenuButton = document.getElementById('settings-menu-button');
const settingsMenuPanel = document.getElementById('settings-menu-panel');
const openProfileModalButton = document.getElementById('open-profile-modal-button');
const profileModal = document.getElementById('profile-modal');
const profileModalCloseButton = document.getElementById('profile-modal-close');
const profileModalCancelButton = document.getElementById('profile-modal-cancel');
const profileModalForm = profileModal?.querySelector('form') || null;
const profileAvatarChooseButton = document.getElementById('profile-avatar-choose');
const profileAvatarFileInput = document.getElementById('profile-avatar-file-input');
const profileAvatarPreview = document.getElementById('profile-avatar-preview');
const profileAvatarFileName = document.getElementById('profile-avatar-file-name');
const profileModalSaveButton = document.getElementById('profile-modal-save');
const profileAvatarRemoveButton = document.getElementById('profile-avatar-remove');
const profileRemoveAvatarInput = document.getElementById('profile-remove-avatar-input');
const avatarLightbox = document.getElementById('avatar-lightbox');
const avatarLightboxImage = document.getElementById('avatar-lightbox-image');
const avatarLightboxCloseButton = document.getElementById('avatar-lightbox-close');
const themeToggle = document.getElementById('theme-toggle');
const reducedMotionQuery = typeof window.matchMedia === 'function'
    ? window.matchMedia('(prefers-reduced-motion: reduce)')
    : null;

function prefersReducedMotion() {
    return Boolean(reducedMotionQuery && reducedMotionQuery.matches);
}

function applyTheme(theme) {
    const nextTheme = theme === 'dark' ? 'dark' : 'light';
    rootEl.setAttribute('data-theme', nextTheme);
    const themeColor = nextTheme === 'dark' ? '#202c33' : '#075e54';
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', themeColor);
    if (themeToggle) {
        themeToggle.checked = nextTheme === 'dark';
    }
}

function loadStoredTheme() {
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
}

function setSettingsMenuOpen(isOpen) {
    if (!settingsMenuButton || !settingsMenuPanel) {
        return;
    }

    settingsMenuButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    if (isOpen) {
        settingsMenuPanel.hidden = false;
        if (prefersReducedMotion()) {
            settingsMenuPanel.classList.add('is-open');
        } else {
            requestAnimationFrame(() => settingsMenuPanel.classList.add('is-open'));
        }
        return;
    }

    settingsMenuPanel.classList.remove('is-open');
    if (prefersReducedMotion()) {
        settingsMenuPanel.hidden = true;
        return;
    }

    window.setTimeout(() => {
        if (!settingsMenuPanel.classList.contains('is-open')) {
            settingsMenuPanel.hidden = true;
        }
    }, 180);
}

function setProfileModalOpen(isOpen) {
    if (!profileModal) {
        return;
    }

    profileModal.hidden = !isOpen;
    if (isOpen) {
        const usernameInput = profileModal.querySelector('input[name="username"]');
        window.setTimeout(() => {
            usernameInput?.focus();
            usernameInput?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }, 60);
    }
}

function updateProfileAvatarPreview(file) {
    if (!profileAvatarPreview || !profileAvatarFileName) {
        return;
    }

    profileAvatarPreview.classList.add('is-updating');
    const objectUrl = URL.createObjectURL(file);
    profileAvatarPreview.innerHTML = `<img src="${objectUrl}" alt="">`;
    profileAvatarFileName.textContent = `${file.name} • ${(file.size / (1024 * 1024)).toFixed(2)}MB`;
    profileAvatarRemoveButton?.removeAttribute('hidden');
    if (profileRemoveAvatarInput instanceof HTMLInputElement) {
        profileRemoveAvatarInput.value = '0';
    }
    window.setTimeout(() => {
        profileAvatarPreview.classList.remove('is-updating');
        URL.revokeObjectURL(objectUrl);
    }, 2800);
}

function clearProfileAvatarPreviewToInitials() {
    if (!profileAvatarPreview || !profileAvatarFileName) {
        return;
    }

    profileAvatarPreview.classList.add('is-updating');
    const initials = String(profileModalForm?.querySelector('input[name="username"]')?.value || '').slice(0, 2).toUpperCase();
    profileAvatarPreview.innerHTML = initials ? escapeHtml(initials) : '??';
    profileAvatarFileName.textContent = 'Photo will be removed when you save.';
    window.setTimeout(() => profileAvatarPreview.classList.remove('is-updating'), 150);
}

function setAvatarLightboxOpen(src) {
    if (!avatarLightbox || !avatarLightboxImage || !src) {
        return;
    }

    avatarLightboxImage.src = src;
    avatarLightbox.hidden = false;
    avatarLightbox.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}

function closeAvatarLightbox() {
    if (!avatarLightbox || !avatarLightboxImage || avatarLightbox.hidden) {
        return;
    }

    avatarLightbox.hidden = true;
    avatarLightbox.setAttribute('aria-hidden', 'true');
    avatarLightboxImage.src = '';
    document.body.style.overflow = '';
}

loadStoredTheme();
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
const personMinusIcon = `
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        <path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"></path>
        <circle cx="9.5" cy="7" r="4"></circle>
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
    const blockedByMe = Boolean(chatUser.blocked_by_me);
    const blockedMe = Boolean(chatUser.blocked_me);

    if (blockedByMe) {
        return `<button class="mini-button primary icon-button" type="button" data-request-action="unblock_user" data-user-id="${userId}" aria-label="Unblock user" title="Unblock user">${personPlusIcon}</button>`;
    }

    if (blockedMe) {
        return `
            <button class="mini-button danger icon-button" type="button" data-request-action="block_user" data-user-id="${userId}" aria-label="Block user" title="Block user">${personMinusIcon}</button>
            <span class="chat-time">Blocked you</span>`;
    }

    if (chatUser.can_chat) {
        return `<button class="mini-button danger icon-button" type="button" data-request-action="block_user" data-user-id="${userId}" aria-label="Block user" title="Block user">${personMinusIcon}</button>`;
    }

    if (status === 'pending' && direction === 'incoming') {
        return `
            <button class="mini-button primary icon-button" type="button" data-request-action="accept_friend_request" data-user-id="${userId}" aria-label="Accept friend request" title="Accept friend request">${acceptIcon}</button>
            <button class="mini-button danger icon-button" type="button" data-request-action="reject_friend_request" data-user-id="${userId}" aria-label="Reject friend request" title="Reject friend request">${rejectIcon}</button>`;
    }

    if (status === 'pending') {
        return `
            <button class="mini-button danger icon-button" type="button" data-request-action="cancel_friend_request" data-user-id="${userId}" aria-label="Cancel friend request" title="Cancel friend request">${personMinusIcon}</button>
            <button class="mini-button danger icon-button" type="button" data-request-action="block_user" data-user-id="${userId}" aria-label="Block user" title="Block user">${rejectIcon}</button>`;
    }

    return `
        <button class="mini-button primary icon-button" type="button" data-request-action="send_friend_request" data-user-id="${userId}" aria-label="Add as friend" title="Add as friend">${personPlusIcon}</button>
        <button class="mini-button danger icon-button" type="button" data-request-action="block_user" data-user-id="${userId}" aria-label="Block user" title="Block user">${rejectIcon}</button>`;
}

function buildAvatarMarkup(chatUser, displayName, mode = 'user') {
    const label = escapeHtml(String(displayName || '').slice(0, 2).toUpperCase());
    const userId = Number(chatUser?.id || 0);
    const groupId = Number(chatUser?.group_id || chatUser?.id || 0);
    const hasAvatar = Boolean(chatUser?.avatar_path);
    const avatarSrc = mode === 'group'
        ? `avatar.php?group=${groupId}`
        : `avatar.php?user=${userId}`;

    if (!hasAvatar) {
        return `<div class="avatar">${label}</div>`;
    }

    return `<div class="avatar"><img loading="lazy" src="${avatarSrc}" alt=""></div>`;
}

function renderDirectoryEntries(users, includeUnseenCount) {
    if (!Array.isArray(users) || users.length === 0) {
        return '';
    }

    return users.map((chatUser) => {
        const userId = Number(chatUser.id);
        const unseenCount = Number(chatUser.unseen_count || 0);
        const avatar = buildAvatarMarkup(chatUser, String(chatUser.username || ''), 'user');
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
                ${avatar}
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
        const avatar = buildAvatarMarkup(chatUser, displayName, chatUser.is_group ? 'group' : 'user');
        const username = escapeHtml(displayName);
        const preview = escapeHtml(chatUser.chat_list_preview || 'Start chatting');
        const chatTime = escapeHtml(formatClockTime(chatUser.last_message_at || chatUser.chat_list_time || ''));
        const countClass = unseenCount > 0 ? '' : ' is-empty';
        const hiddenAttr = unseenCount > 0 ? '' : ' aria-hidden="true"';
        const href = escapeHtml(chatUser.url || `chat.php?user=${userId}`);

        return `
            <a class="chat-item" data-chat-user-id="${userId}" href="${href}">
                ${avatar}
                <div class="chat-copy">
                    <div class="chat-copy-head">
                        <span class="chat-name-row">
                            <strong class="chat-name">${username}</strong>
                            ${chatUser.is_group
                                ? '<span class="chat-type-chip">Group</span>'
                                : ((chatUser.blocked_by_me || chatUser.blocked_me) ? '<span class="chat-type-chip blocked">Blocked</span>' : '')}
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
        const avatar = buildAvatarMarkup({ id: request.sender_id, avatar_path: request.sender_avatar_path }, String(request.sender_name || ''), 'user');
        const presenceLabel = escapeHtml(formatPresenceLabel(Boolean(request.is_online), request.presence_updated_at || null));
        const senderName = escapeHtml(request.sender_name || 'Unknown');
        const presenceClass = request.is_online ? ' online' : '';
        return `
            <div class="request-card" data-request-user-id="${userId}">
                ${avatar}
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
        data: { url: './' },
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
        data: { url: accepted && update.recipient_id ? `./chat.php?user=${Number(update.recipient_id)}` : './' },
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

    const navigate = () => {
        window.location.href = url;
    };
    const supportsCrossDocumentTransitions = typeof CSS !== 'undefined'
        && typeof CSS.supports === 'function'
        && CSS.supports('view-transition-name: none');

    if (prefersReducedMotion()) {
        navigate();
        return true;
    }

    if (supportsCrossDocumentTransitions) {
        navigate();
        return true;
    }

    if (typeof document.startViewTransition === 'function') {
        try {
            const transition = document.startViewTransition(() => {
                document.body.classList.add('is-route-leaving');
            });
            transition?.finished?.catch(() => {
                navigate();
            });
            transition?.finished?.then(() => {
                navigate();
            });
            return true;
        } catch (error) {
            navigate();
            return true;
        }
    }

    navigate();
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

settingsMenuButton?.addEventListener('click', () => {
    setSettingsMenuOpen(settingsMenuButton.getAttribute('aria-expanded') !== 'true');
});

openProfileModalButton?.addEventListener('click', () => {
    setSettingsMenuOpen(false);
    setProfileModalOpen(true);
});

themeToggle?.addEventListener('change', () => {
    const nextTheme = themeToggle.checked ? 'dark' : 'light';
    applyTheme(nextTheme);
    try {
        window.localStorage.setItem(themeStorageKey, nextTheme);
    } catch (error) {
        // Ignore storage access errors.
    }
});

document.addEventListener('click', (event) => {
    if (!settingsMenuPanel || !settingsMenuButton) {
        return;
    }

    const target = event.target;
    if (target instanceof Node && (settingsMenuPanel.contains(target) || settingsMenuButton.contains(target))) {
        return;
    }

    setSettingsMenuOpen(false);
});

profileModal?.addEventListener('click', (event) => {
    if (event.target === profileModal) {
        setProfileModalOpen(false);
    }
});

profileModalCloseButton?.addEventListener('click', () => setProfileModalOpen(false));
profileModalCancelButton?.addEventListener('click', () => setProfileModalOpen(false));
profileAvatarChooseButton?.addEventListener('click', () => profileAvatarFileInput?.click());
profileAvatarFileInput?.addEventListener('change', () => {
    const [file] = profileAvatarFileInput.files || [];
    if (!file) {
        return;
    }

    if (!String(file.type || '').startsWith('image/')) {
        window.alert('Please choose an image file.');
        profileAvatarFileInput.value = '';
        return;
    }

    if (Number(file.size || 0) > (8 * 1024 * 1024)) {
        window.alert('Profile photo must be 8MB or smaller.');
        profileAvatarFileInput.value = '';
        return;
    }

    updateProfileAvatarPreview(file);
});
profileAvatarRemoveButton?.addEventListener('click', () => {
    if (profileAvatarFileInput) {
        profileAvatarFileInput.value = '';
    }
    if (profileRemoveAvatarInput instanceof HTMLInputElement) {
        profileRemoveAvatarInput.value = '1';
    }
    clearProfileAvatarPreviewToInitials();
    profileAvatarRemoveButton.setAttribute('hidden', 'hidden');
});
profileModalForm?.addEventListener('submit', () => {
    if (profileModalSaveButton) {
        profileModalSaveButton.disabled = true;
        profileModalSaveButton.textContent = 'Saving…';
    }
});
profileModal?.querySelectorAll('input').forEach((input) => {
    input.addEventListener('focus', () => {
        window.setTimeout(() => {
            input.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }, 80);
    });
});
document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) {
        return;
    }

    const avatarImage = target.closest('.avatar img, .topbar-peer-avatar img');
    if (!(avatarImage instanceof HTMLImageElement)) {
        return;
    }

    if (avatarImage.closest('a, button')) {
        event.preventDefault();
        event.stopPropagation();
    }
    setAvatarLightboxOpen(avatarImage.currentSrc || avatarImage.src || '');
});
avatarLightboxCloseButton?.addEventListener('click', closeAvatarLightbox);
avatarLightbox?.addEventListener('click', (event) => {
    if (event.target === avatarLightbox) {
        closeAvatarLightbox();
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && avatarLightbox && !avatarLightbox.hidden) {
        closeAvatarLightbox();
        return;
    }

    if (event.key === 'Escape' && profileModal && !profileModal.hidden) {
        setProfileModalOpen(false);
        return;
    }

    if (event.key === 'Escape') {
        setSettingsMenuOpen(false);
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
