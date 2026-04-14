
const chatBootstrap = JSON.parse(document.getElementById('chat-bootstrap-data')?.textContent || '{}');
const currentUserId = chatBootstrap.currentUserId;
const currentUsername = chatBootstrap.currentUsername;
const conversationUserId = chatBootstrap.conversationUserId;
const groupId = chatBootstrap.groupId;
const isGroupConversation = Boolean(chatBootstrap.isGroupConversation);
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
let conversationDisplayName = chatBootstrap.conversationDisplayName;
const messageBatchSize = chatBootstrap.messageBatchSize;
const initialMessages = chatBootstrap.initialMessages ?? [];
const initialHasMoreMessages = Boolean(chatBootstrap.initialHasMoreMessages);
const initialTypingMembers = chatBootstrap.initialTypingMembers ?? [];
const initialCanChat = Boolean(chatBootstrap.initialCanChat);
const initialFriendship = chatBootstrap.initialFriendship ?? null;
const initialBlockingState = chatBootstrap.initialBlockingState ?? null;
const initialGroup = chatBootstrap.initialGroup ?? null;
const initialPinnedMessageIds = chatBootstrap.initialPinnedMessageIds ?? [];
const initialPresence = Boolean(chatBootstrap.initialPresence);
const initialPresenceUpdatedAt = chatBootstrap.initialPresenceUpdatedAt ?? null;
const preferPolling = Boolean(chatBootstrap.preferPolling);
const initialConversationSignature = chatBootstrap.initialConversationSignature ?? '';
const imageUploadTargetBytes = chatBootstrap.imageUploadTargetBytes;
const FAST_POLL_INTERVAL_MS = 2500;
const MAX_POLL_INTERVAL_MS = 12000;
const FAST_HOME_POLL_INTERVAL_MS = 4000;
const MAX_HOME_POLL_INTERVAL_MS = 15000;
const TEXT_SEND_TIMEOUT_MS = 15000;
const TEXT_SEND_RETRY_DELAY_MS = 1200;
const AUTO_SCROLL_THRESHOLD_PX = 72;
const SCROLL_TO_END_VISIBILITY_THRESHOLD_PX = 280;
const POPULAR_REACTIONS = ['👍', '❤️', '😂', '😢', '🙏'];
const messagesEl = document.getElementById('messages');
const statusRowEl = document.getElementById('status-row');
const bodyEl = document.getElementById('message-body');
const actionButton = document.getElementById('action-button');
const quickGridButton = document.getElementById('quick-grid-button');
const quickGridPanel = document.getElementById('quick-grid-panel');
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
const pinnedMenuButton = document.getElementById('menu-pinned-button');
const pinnedPanelEl = document.getElementById('pinned-panel');
const pinnedPanelListEl = document.getElementById('pinned-panel-list');
const pinnedPanelCountEl = document.getElementById('pinned-panel-count');
const searchMenuButton = document.getElementById('menu-search-button');
const searchPanelEl = document.getElementById('search-panel');
const messageSearchInput = document.getElementById('message-search-input');
const messageSearchSubmit = document.getElementById('message-search-submit');
const searchResultsEl = document.getElementById('search-results');
const deleteConversationButton = document.getElementById('delete-conversation-button');
const revokeFriendshipButton = document.getElementById('revoke-friendship-button');
const addFriendButton = document.getElementById('add-friend-button');
const blockUserButton = document.getElementById('block-user-button');
const unblockUserButton = document.getElementById('unblock-user-button');
const muteConversationButton = document.getElementById('mute-conversation-button');
const muteIconSlash = document.getElementById('mute-icon-slash');
const muteIconSoundWave = document.getElementById('mute-icon-sound-wave');
const muteIconSoundWaveOuter = document.getElementById('mute-icon-sound-wave-outer');
const addGroupMemberButton = document.getElementById('add-group-member-button');
const leaveGroupButton = document.getElementById('leave-group-button');
const deleteGroupButton = document.getElementById('delete-group-button');
const renameGroupButton = document.getElementById('rename-group-button');
const editGroupAvatarButton = document.getElementById('edit-group-avatar-button');
const groupAvatarFileInput = document.getElementById('group-avatar-file-input');
const themeStorageKey = 'localchat:theme';
const muteStorageKey = !isGroupConversation && conversationUserId > 0 ? `localchat:mute:${Math.min(currentUserId, conversationUserId)}:${Math.max(currentUserId, conversationUserId)}` : '';
const rootEl = document.documentElement;
const backLink = document.querySelector('.back-link');
const chatShellEl = document.querySelector('.chat-shell');
const topbarEl = document.querySelector('.topbar');
const reducedMotionQuery = typeof window.matchMedia === 'function'
    ? window.matchMedia('(prefers-reduced-motion: reduce)')
    : null;
const SWIPE_BACK_START_EDGE_PX = 36;
const SWIPE_BACK_MIN_DISTANCE_PX = 90;
const SWIPE_BACK_MAX_VERTICAL_DRIFT_PX = 72;
const SWIPE_BACK_MAX_DURATION_MS = 650;
let swipeBackStart = null;

function prefersReducedMotion() {
    return Boolean(reducedMotionQuery && reducedMotionQuery.matches);
}

function navigateWithTransition(url) {
    if (!url) {
        return;
    }

    const navigate = () => {
        window.location.href = url;
    };
    const supportsCrossDocumentTransitions = typeof CSS !== 'undefined'
        && typeof CSS.supports === 'function'
        && CSS.supports('view-transition-name: none');

    if (prefersReducedMotion()) {
        navigate();
        return;
    }

    if (supportsCrossDocumentTransitions) {
        navigate();
        return;
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
            return;
        } catch (error) {
            navigate();
            return;
        }
    }

    navigate();
}

function shouldIgnoreSwipeBack(target) {
    if (!(target instanceof Element)) {
        return false;
    }
    return Boolean(target.closest('a, button, input, textarea, select, label, .reaction-picker, .member-picker, .lightbox, .message-menu, .attachment-menu'));
}

function resetSwipeBackState() {
    swipeBackStart = null;
}

function handleSwipeBackTouchStart(event) {
    if (!backLink || event.touches.length !== 1) {
        resetSwipeBackState();
        return;
    }
    if (imageLightbox && !imageLightbox.hidden) {
        resetSwipeBackState();
        return;
    }
    const touch = event.touches[0];
    if (touch.clientX > SWIPE_BACK_START_EDGE_PX || shouldIgnoreSwipeBack(event.target)) {
        resetSwipeBackState();
        return;
    }
    swipeBackStart = {
        x: touch.clientX,
        y: touch.clientY,
        at: Date.now(),
        engaged: false,
    };
}

function handleSwipeBackTouchMove(event) {
    if (!swipeBackStart || event.touches.length !== 1) {
        return;
    }
    const touch = event.touches[0];
    const deltaX = touch.clientX - swipeBackStart.x;
    const deltaY = Math.abs(touch.clientY - swipeBackStart.y);

    if (deltaX > 12 && deltaX > deltaY * 1.2) {
        swipeBackStart.engaged = true;
        event.preventDefault();
        return;
    }
    if (deltaY > SWIPE_BACK_MAX_VERTICAL_DRIFT_PX) {
        resetSwipeBackState();
    }
}

function handleSwipeBackTouchEnd(event) {
    if (!swipeBackStart || event.changedTouches.length !== 1) {
        resetSwipeBackState();
        return;
    }
    const touch = event.changedTouches[0];
    const deltaX = touch.clientX - swipeBackStart.x;
    const deltaY = Math.abs(touch.clientY - swipeBackStart.y);
    const elapsedMs = Date.now() - swipeBackStart.at;

    const shouldNavigateBack = swipeBackStart.engaged
        && deltaX >= SWIPE_BACK_MIN_DISTANCE_PX
        && deltaY <= SWIPE_BACK_MAX_VERTICAL_DRIFT_PX
        && elapsedMs <= SWIPE_BACK_MAX_DURATION_MS;
    resetSwipeBackState();
    if (!shouldNavigateBack) {
        return;
    }
    navigateWithTransition(backLink.getAttribute('href') || './');
}

function applyTheme(theme) {
    const nextTheme = theme === 'dark' ? 'dark' : 'light';
    rootEl.setAttribute('data-theme', nextTheme);
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', nextTheme === 'dark' ? '#202c33' : '#075e54');
}

function isConversationMuted() {
    if (!muteStorageKey) {
        return false;
    }
    try {
        return window.localStorage.getItem(muteStorageKey) === '1';
    } catch (error) {
        return false;
    }
}

function setConversationMuted(muted) {
    if (!muteStorageKey) {
        return;
    }
    try {
        if (muted) {
            window.localStorage.setItem(muteStorageKey, '1');
        } else {
            window.localStorage.removeItem(muteStorageKey);
        }
    } catch (error) {
        // Ignore storage errors.
    }
}

function updateMuteButtonLabel() {
    if (!muteConversationButton) {
        return;
    }
    const muted = isConversationMuted();
    muteConversationButton.querySelector('span').textContent = muted ? 'Unmute notifications' : 'Mute notifications';
    if (muteIconSlash) {
        muteIconSlash.classList.toggle('is-hidden', !muted);
    }
    if (muteIconSoundWave) {
        muteIconSoundWave.classList.toggle('is-hidden', muted);
    }
    if (muteIconSoundWaveOuter) {
        muteIconSoundWaveOuter.classList.toggle('is-hidden', muted);
    }
}

function isMessagePinned(messageId) {
    return pinnedMessageIds.includes(Number(messageId || 0));
}

async function toggleMessagePin(messageId) {
    const targetId = Number(messageId || 0);
    if (!targetId) {
        return;
    }
    const action = isMessagePinned(targetId) ? 'unpin_message' : 'pin_message';
    try {
        const response = await fetch(conversationApiUrl(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken },
            body: new URLSearchParams({ action, message_id: String(targetId), csrf_token: csrfToken }),
        });
        const payload = await response.json();
        if (!response.ok || payload.error) {
            throw new Error(payload.error || 'Could not update pin right now.');
        }
        applyConversationPayload(payload.payload || payload);
        showHint(action === 'unpin_message' ? 'Message unpinned.' : 'Message pinned to the top.');
    } catch (error) {
        showError(error.message || 'Could not update pin right now.');
    }
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
const memberPickerTitleEl = document.getElementById('member-picker-title');
const memberPickerListEl = document.getElementById('member-picker-list');
const memberPickerSearchInput = document.getElementById('member-picker-search-input');
const memberPickerEmptyEl = document.getElementById('member-picker-empty');
const groupMembersButton = document.getElementById('group-members-button');
const groupMembersModal = document.getElementById('group-members-modal');
const groupMembersClose = document.getElementById('group-members-close');
const groupMembersListEl = document.getElementById('group-members-list');
const groupMembersEmptyEl = document.getElementById('group-members-empty');
const reactionMembersModal = document.getElementById('reaction-members-modal');
const reactionMembersClose = document.getElementById('reaction-members-close');
const reactionMembersListEl = document.getElementById('reaction-members-list');
const reactionMembersEmptyEl = document.getElementById('reaction-members-empty');
const messageDeliveryModal = document.getElementById('message-delivery-modal');
const messageDeliveryClose = document.getElementById('message-delivery-close');
const messageDeliveryListEl = document.getElementById('message-delivery-list');
const messageDeliveryEmptyEl = document.getElementById('message-delivery-empty');
const replyPreviewEl = document.getElementById('reply-preview');
const replyPreviewAuthorEl = document.getElementById('reply-preview-author');
const replyPreviewTextEl = document.getElementById('reply-preview-text');
const replyPreviewCancelEl = document.getElementById('reply-preview-cancel');
const editPreviewEl = document.getElementById('edit-preview');
const editPreviewTextEl = document.getElementById('edit-preview-text');
const editPreviewCancelEl = document.getElementById('edit-preview-cancel');
const imageLightbox = document.getElementById('image-lightbox');
const lightboxImage = document.getElementById('lightbox-image');
const lightboxBack = document.getElementById('lightbox-back');
const lightboxMenuButton = document.getElementById('lightbox-menu-button');
const lightboxMenu = document.getElementById('lightbox-menu');
const lightboxSave = document.getElementById('lightbox-save');
const lightboxReact = document.getElementById('lightbox-react');
const lightboxReply = document.getElementById('lightbox-reply');
const lightboxForward = document.getElementById('lightbox-forward');
const scrollToEndButton = document.getElementById('scroll-to-end-button');
let lastFocusedElement = null;
let renderedSignature = '';
let localMessageCounter = 0;
let pinnedMessageIds = Array.isArray(initialPinnedMessageIds)
    ? [...new Set(initialPinnedMessageIds.map((value) => Number(value)).filter((value) => Number.isInteger(value) && value > 0))]
    : [];
let typingTimer = null;
let typingActive = false;
let isSending = false;
let activeUploadCount = 0;
let pendingTextQueue = [];
let reactionPickerEl = null;
let reactionPickerMessageId = 0;
let messageActionMenuEl = null;
let messageActionMessageId = 0;
let longPressTimer = null;
let longPressHandled = false;
let replyTarget = null;
let editTarget = null;
let pendingForwardMessage = null;
let memberPickerMode = 'group-invite';
let textSendInFlight = false;
let textSendRetryTimer = null;
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
let searchPanelOpen = false;
let searchPanelCloseTimer = null;
let pinnedPanelOpen = false;
let pinnedPanelCloseTimer = null;
let lightboxDownloadHref = '#';
let lightboxDownloadFilename = 'chat-image';
let lightboxActiveMessageId = 0;
let lightboxHistoryEntryActive = false;
let lightboxSwipeStartX = null;
let lightboxSwipeStartY = null;
let lightboxSwipeTracking = false;
const personMinusIcon = `
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        <path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"></path>
        <circle cx="9.5" cy="7" r="4"></circle>
        <path d="M22 11h-6"></path>
    </svg>`;
let statusState = initialCanChat && typingMembers.length > 0 ? 'typing' : 'idle';
let statusMessage = typingMembers.length > 0 ? `${typingMembers.map((member) => member.username).join(', ')} typing…` : '';
updateReplyPreviewUi();

function setHeaderMenuOpen(isOpen) {
    if (!headerMenuButton || !headerMenuPanel) {
        return;
    }

    headerMenuButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    if (isOpen) {
        headerMenuPanel.hidden = false;
        if (prefersReducedMotion()) {
            headerMenuPanel.classList.add('is-open');
        } else {
            requestAnimationFrame(() => {
                headerMenuPanel.classList.add('is-open');
            });
        }
        return;
    }

    headerMenuPanel.classList.remove('is-open');
    if (prefersReducedMotion()) {
        headerMenuPanel.hidden = true;
    } else if (!isOpen) {
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

function setSearchPanelOpen(isOpen) {
    searchPanelOpen = Boolean(isOpen);
    if (!searchPanelEl || !searchMenuButton) {
        return;
    }
    if (searchPanelCloseTimer !== null) {
        window.clearTimeout(searchPanelCloseTimer);
        searchPanelCloseTimer = null;
    }

    searchMenuButton.setAttribute('aria-expanded', searchPanelOpen ? 'true' : 'false');

    if (searchPanelOpen) {
        setPinnedPanelOpen(false);
        searchPanelEl.hidden = false;
        if (prefersReducedMotion()) {
            searchPanelEl.classList.add('is-open');
            messageSearchInput?.focus();
        } else {
            requestAnimationFrame(() => {
                searchPanelEl.classList.add('is-open');
            });
            window.setTimeout(() => messageSearchInput?.focus(), 80);
        }
        return;
    }

    searchPanelEl.classList.remove('is-open');
    if (prefersReducedMotion()) {
        searchPanelEl.hidden = true;
    } else {
        searchPanelCloseTimer = window.setTimeout(() => {
            if (!searchPanelEl.classList.contains('is-open')) {
                searchPanelEl.hidden = true;
            }
            searchPanelCloseTimer = null;
        }, 220);
    }
}

function setPinnedPanelOpen(isOpen) {
    pinnedPanelOpen = Boolean(isOpen);
    if (!pinnedPanelEl || !pinnedMenuButton) {
        return;
    }
    if (pinnedPanelCloseTimer !== null) {
        window.clearTimeout(pinnedPanelCloseTimer);
        pinnedPanelCloseTimer = null;
    }

    pinnedMenuButton.setAttribute('aria-expanded', pinnedPanelOpen ? 'true' : 'false');

    if (pinnedPanelOpen) {
        setSearchPanelOpen(false);
        pinnedPanelEl.hidden = false;
        if (prefersReducedMotion()) {
            pinnedPanelEl.classList.add('is-open');
        } else {
            requestAnimationFrame(() => {
                pinnedPanelEl.classList.add('is-open');
            });
        }
        return;
    }

    pinnedPanelEl.classList.remove('is-open');
    if (prefersReducedMotion()) {
        pinnedPanelEl.hidden = true;
    } else {
        pinnedPanelCloseTimer = window.setTimeout(() => {
            if (!pinnedPanelEl.classList.contains('is-open')) {
                pinnedPanelEl.hidden = true;
            }
            pinnedPanelCloseTimer = null;
        }, 220);
    }
}

function renderPinnedPanel(messages) {
    if (!pinnedPanelEl || !pinnedPanelListEl || !pinnedPanelCountEl || !pinnedMenuButton) {
        return;
    }
    const pinnedMessages = Array.isArray(messages) ? messages : [];
    pinnedMenuButton.classList.toggle('hidden', pinnedMessages.length === 0);
    if (pinnedMessages.length === 0) {
        pinnedPanelCountEl.textContent = '0';
        pinnedPanelListEl.innerHTML = '';
        setPinnedPanelOpen(false);
        return;
    }

    pinnedPanelCountEl.textContent = String(pinnedMessages.length);
    pinnedPanelListEl.innerHTML = pinnedMessages.map((message) => {
        const preview = replySnippetFromMessage(message) || 'Pinned message';
        const timestamp = formatHumanTimestamp(message.created_at);
        return `<button class="pinned-message-item" type="button" data-pinned-target-id="${Number(message.id)}"><span class="pinned-preview">${escapeHtml(preview)}</span><span class="pinned-timestamp">${escapeHtml(timestamp)}</span></button>`;
    }).join('');

    pinnedPanelListEl.querySelectorAll('[data-pinned-target-id]').forEach((pinnedEl) => {
        pinnedEl.addEventListener('click', () => {
            const targetId = Number(pinnedEl.getAttribute('data-pinned-target-id') || 0);
            if (!targetId) {
                return;
            }
            jumpToMessage(targetId);
            setPinnedPanelOpen(false);
        });
    });
}

function jumpToMessage(messageId, behavior = 'smooth') {
    const targetId = Number(messageId || 0);
    if (!targetId) {
        return false;
    }
    const targetRow = messagesEl.querySelector(`.message-row[data-message-id="${targetId}"]`);
    if (!(targetRow instanceof HTMLElement)) {
        window.location.hash = `message-${targetId}`;
        return false;
    }
    const topbarHeight = topbarEl instanceof HTMLElement ? topbarEl.offsetHeight : 0;
    const extraTopOffset = 12;
    const targetTop = targetRow.offsetTop - topbarHeight - extraTopOffset;
    messagesEl.scrollTo({
        top: Math.max(0, targetTop),
        behavior,
    });
    targetRow.classList.add('reply-target-highlight');
    window.setTimeout(() => targetRow.classList.remove('reply-target-highlight'), 1100);
    window.location.hash = `message-${targetId}`;
    return true;
}

function renderSearchResults(results) {
    if (!searchResultsEl) {
        return;
    }
    if (!Array.isArray(results) || results.length === 0) {
        searchResultsEl.innerHTML = '<div class="empty-state">No matching messages found.</div>';
        return;
    }
    searchResultsEl.innerHTML = results.map((message) => {
        const snippet = String(message?.body || '').trim() || '(No text content)';
        const sender = isGroupConversation ? String(message?.sender_name || '') : '';
        const timestamp = formatHumanTimestamp(message?.created_at || '');
        const prefix = sender !== '' ? `${sender}: ` : '';
        return `<button class="search-result-item" type="button" data-search-message-id="${Number(message?.id || 0)}"><strong>${escapeHtml(prefix + snippet)}</strong><span>${escapeHtml(timestamp)}</span></button>`;
    }).join('');
    searchResultsEl.querySelectorAll('[data-search-message-id]').forEach((resultEl) => {
        resultEl.addEventListener('click', () => {
            const messageId = Number(resultEl.getAttribute('data-search-message-id') || 0);
            if (!messageId) {
                return;
            }
            jumpToMessage(messageId);
            setSearchPanelOpen(false);
        });
    });
}

async function performMessageSearch() {
    const query = String(messageSearchInput?.value || '').trim();
    if (!searchResultsEl) {
        return;
    }
    if (query.length < 2) {
        searchResultsEl.innerHTML = '<div class="empty-state">Type at least 2 characters.</div>';
        return;
    }
    searchResultsEl.innerHTML = '<div class="empty-state">Searching…</div>';
    try {
        const response = await fetch(`${conversationApiUrl('search_messages')}&q=${encodeURIComponent(query)}&limit=20`, {
            headers: { 'Accept': 'application/json' },
        });
        const payload = await response.json();
        if (!response.ok || payload.error) {
            throw new Error(payload.error || 'Search failed.');
        }
        renderSearchResults(Array.isArray(payload.messages) ? payload.messages : []);
    } catch (error) {
        searchResultsEl.innerHTML = `<div class="empty-state">${escapeHtml(error?.message || 'Search failed.')}</div>`;
    }
}

function setQuickGridOpen(isOpen) {
    if (!quickGridButton || !quickGridPanel) {
        return;
    }
    quickGridButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    quickGridPanel.hidden = !isOpen;
}

function insertIconIntoComposer(icon) {
    if (!bodyEl || !icon || bodyEl.disabled) {
        return;
    }
    const start = bodyEl.selectionStart ?? bodyEl.value.length;
    const end = bodyEl.selectionEnd ?? bodyEl.value.length;
    const prefix = bodyEl.value.slice(0, start);
    const suffix = bodyEl.value.slice(end);
    bodyEl.value = `${prefix}${icon}${suffix}`;
    const nextPos = start + icon.length;
    bodyEl.setSelectionRange(nextPos, nextPos);
    bodyEl.focus();
    autoResizeComposer();
    updateComposerClearance();
    updateComposerDirection();
    updateActionButton();
    markTyping();
}

function conversationPageUrl() {
    return isGroupConversation ? `chat.php?group=${groupId}` : `chat.php?user=${conversationUserId}`;
}

function conversationStreamUrl() {
    return isGroupConversation ? `chat_stream.php?group=${groupId}` : `chat_stream.php?user=${conversationUserId}`;
}

function mediaUrlForMessage(messageId) {
    const numericMessageId = Number(messageId || 0);
    if (numericMessageId <= 0) {
        return 'media.php';
    }
    if (isGroupConversation) {
        return `media.php?message=${numericMessageId}&group=${groupId}`;
    }
    return `media.php?message=${numericMessageId}`;
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

function availableForwardCandidates() {
    const query = String(memberPickerSearchInput?.value || '').trim().toLowerCase();
    return directoryUsersState.filter((user) => {
        if (!user || !user.can_chat || Number(user.id) === currentUserId) {
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

    if (memberPickerMode === 'forward') {
        const candidates = availableForwardCandidates();
        memberPickerListEl.innerHTML = candidates.map((user) => `
            <button class="member-picker-item" type="button" data-forward-user-id="${Number(user.id)}">
                <div class="member-picker-copy">
                    <strong>${escapeHtml(user.username || '')}</strong>
                    <span>${escapeHtml(user.presence_label || 'Friend')}</span>
                </div>
                <span class="mini-button primary">Send</span>
            </button>
        `).join('');

        const query = String(memberPickerSearchInput?.value || '').trim();
        memberPickerEmptyEl.textContent = query === ''
            ? 'Pick a friend to forward this message.'
            : 'No friends match your search.';
        memberPickerEmptyEl.hidden = candidates.length > 0;
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

    const canManageMembers = Number(groupState?.creator_user_id || 0) === currentUserId;
    groupMembersListEl.innerHTML = members.map((member) => {
        const memberUserId = Number(member.user_id || 0);
        const isCreator = String(member.role || '') === 'creator';
        const canRemoveMember = canManageMembers && !isCreator && memberUserId > 0 && memberUserId !== currentUserId;
        return `
        <div class="member-picker-item">
            <div class="member-picker-copy">
                <strong>${escapeHtml(member.username || '')}</strong>
                <span>${escapeHtml(member.presence_label || 'Member')}</span>
            </div>
            <div class="member-picker-actions">
                ${isCreator ? '<span class="member-role-chip">Creator</span>' : ''}
                ${canRemoveMember
                    ? `<button class="member-remove-button" type="button" data-group-remove-user-id="${memberUserId}" aria-label="Remove ${escapeHtml(member.username || 'member')} from group" title="Remove member">${personMinusIcon}</button>`
                    : ''}
            </div>
        </div>
    `;
    }).join('');

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
        if (memberPickerTitleEl) {
            memberPickerTitleEl.textContent = memberPickerMode === 'forward' ? 'Forward to' : 'Add friends';
        }
        if (memberPickerSearchInput) {
            memberPickerSearchInput.placeholder = memberPickerMode === 'forward' ? 'Search friends by name' : 'Search friends by name';
            memberPickerSearchInput.setAttribute('aria-label', memberPickerSearchInput.placeholder);
        }
        renderMemberPicker();
        memberPickerSearchInput?.focus();
    } else if (memberPickerSearchInput) {
        memberPickerSearchInput.value = '';
        memberPickerMode = 'group-invite';
        pendingForwardMessage = null;
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
let blockingState = initialBlockingState || { blocked_by_me: false, blocked_me: false, is_blocked: false };
let hasMoreMessages = initialHasMoreMessages;
let loadingOlderMessages = false;
let lastUnseenCounts = new Map([[String(isGroupConversation ? groupId : conversationUserId), initialMessages.filter((message) =>
    Number(message.sender_id) !== currentUserId && !message.read_at
).length]]);
const webPushPublicKey = chatBootstrap.webPushPublicKey ?? null;
const directoryUsersState = chatBootstrap.directoryUsersState ?? [];
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
        return `Today at ${timeLabel}`;
    }

    if (diffDays === -1) {
        return `Yesterday at ${timeLabel}`;
    }

    const dateLabel = new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        year: date.getFullYear() === now.getFullYear() ? undefined : 'numeric',
    }).format(date);

    return `${dateLabel} at ${timeLabel}`;
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
        if (quickGridButton) {
            quickGridButton.disabled = activeUploadCount > 0;
        }
        attachmentButton.disabled = activeUploadCount > 0;
        attachmentGalleryOption.disabled = activeUploadCount > 0;
        attachmentDocumentOption.disabled = activeUploadCount > 0;
        if (activeUploadCount > 0) {
            setQuickGridOpen(false);
            setAttachmentMenuOpen(false);
        }
        bodyEl.disabled = false;
        return;
    }

    const isAccepted = Boolean(friendshipState && friendshipState.status === 'accepted');
    const blockedByMe = Boolean(blockingState?.blocked_by_me);
    const blockedMe = Boolean(blockingState?.blocked_me);
    const isBlocked = blockedByMe || blockedMe;
    canChat = isAccepted;
    if (isBlocked) {
        canChat = false;
    }
    actionButton.disabled = !canChat || activeUploadCount > 0;
    if (quickGridButton) {
        quickGridButton.disabled = !canChat || activeUploadCount > 0;
    }
    attachmentButton.disabled = !canChat || activeUploadCount > 0;
    attachmentGalleryOption.disabled = !canChat || activeUploadCount > 0;
    attachmentDocumentOption.disabled = !canChat || activeUploadCount > 0;
    if (!canChat || activeUploadCount > 0) {
        setQuickGridOpen(false);
        setAttachmentMenuOpen(false);
    }
    bodyEl.disabled = !canChat;
    revokeFriendshipButton.classList.toggle('hidden', !isAccepted);
    blockUserButton?.classList.toggle('hidden', blockedByMe);
    unblockUserButton?.classList.toggle('hidden', !blockedByMe);
    const canSendFriendRequest = Boolean(
        !isAccepted
        && !isBlocked
        && (!friendshipState || !['pending', 'accepted'].includes(String(friendshipState.status || '')))
    );
    addFriendButton.classList.toggle('hidden', !canSendFriendRequest);

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
        if (blockedByMe) {
            showHint(`You blocked ${conversationDisplayName}. Unblock to send new messages.`);
            return;
        }
        if (blockedMe) {
            showHint(`${conversationDisplayName} blocked you. Sending and reactions are disabled.`);
            return;
        }

        const status = String(friendshipState?.status || 'none');
        const direction = String(friendshipState?.request_direction || '');
        if (status === 'pending' && direction === 'outgoing') {
            showHint(`Friend request sent to ${conversationDisplayName}. You can start chatting once it is accepted.`);
            return;
        }

        if (status === 'pending' && direction === 'incoming') {
            showHint(`${conversationDisplayName} sent you a friend request. Accept it from Home to start chatting.`);
            return;
        }

        showHint('You are not friends yet. Message history is still visible, but sending is disabled.');
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

function replySnippetFromMessage(message) {
    if (!message) {
        return '';
    }
    const body = String(message.body || '').trim();
    if (body !== '') {
        return body;
    }
    if (message.image_path) {
        return '📷 Photo';
    }
    if (message.file_path) {
        return `📎 ${String(message.file_name || 'File')}`;
    }
    if (message.audio_path) {
        return '🎤 Voice note';
    }
    return 'Message';
}

function updateReplyPreviewUi() {
    if (!replyPreviewEl || !replyPreviewAuthorEl || !replyPreviewTextEl) {
        return;
    }
    if (!replyTarget) {
        replyPreviewEl.hidden = true;
        replyPreviewAuthorEl.textContent = 'Replying';
        replyPreviewTextEl.textContent = '';
        return;
    }
    replyPreviewEl.hidden = false;
    replyPreviewAuthorEl.textContent = `Replying to ${replyTarget.sender_name || 'message'}`;
    replyPreviewTextEl.textContent = replyTarget.preview || 'Message';
}

function openForwardPickerByMessage(message) {
    const messageId = Number(message?.id || 0);
    if (!messageId) {
        showError('Unable to forward this message right now.');
        return;
    }
    pendingForwardMessage = message;
    memberPickerMode = 'forward';
    setMemberPickerOpen(true);
}

function setReplyTargetByMessage(message) {
    const messageId = Number(message?.id || 0);
    if (!messageId) {
        replyTarget = null;
        updateReplyPreviewUi();
        return;
    }
    if (editTarget) {
        clearEditTarget(false);
    }
    replyTarget = {
        id: messageId,
        sender_name: Number(message?.sender_id || 0) === currentUserId ? 'You' : String(message?.sender_name || 'message'),
        preview: replySnippetFromMessage(message),
    };
    updateReplyPreviewUi();
    keepComposerFocused(true);
}

function clearReplyTarget() {
    replyTarget = null;
    updateReplyPreviewUi();
}

function updateEditPreviewUi() {
    if (!editPreviewEl || !editPreviewTextEl) {
        return;
    }
    if (!editTarget) {
        editPreviewEl.hidden = true;
        editPreviewTextEl.textContent = '';
        return;
    }
    editPreviewEl.hidden = false;
    editPreviewTextEl.textContent = String(editTarget.originalBody || '');
}

function setEditTargetByMessage(message) {
    const messageId = Number(message?.id || 0);
    const currentBody = String(message?.body || '').trim();
    if (!messageId || currentBody === '') {
        showError('Only text messages can be edited.');
        return;
    }
    if (replyTarget) {
        clearReplyTarget();
    }
    editTarget = {
        id: messageId,
        originalBody: currentBody,
    };
    bodyEl.value = currentBody;
    autoResizeComposer();
    updateComposerClearance();
    updateComposerDirection();
    updateActionButton();
    updateEditPreviewUi();
    keepComposerFocused(true);
}

function clearEditTarget(shouldFocusComposer = true, preserveText = false) {
    editTarget = null;
    updateEditPreviewUi();
    if (!preserveText) {
        bodyEl.value = '';
        autoResizeComposer();
        updateComposerClearance();
        updateComposerDirection();
    }
    updateActionButton();
    if (shouldFocusComposer) {
        keepComposerFocused(true);
    }
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

function createPendingMessage(body, type, nextReplyTarget = null) {
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
        is_forwarded: false,
        delivered_at: null,
        read_at: null,
        group_delivery: {
            recipient_count: 0,
            delivered_count: 0,
            read_count: 0,
            delivered_to: [],
            read_by: [],
        },
        created_at: now.toISOString(),
        created_at_label: `${now.toISOString().slice(0, 19).replace('T', ' ')} UTC`,
        reply_to_message_id: nextReplyTarget && Number(nextReplyTarget.id) > 0 ? Number(nextReplyTarget.id) : null,
        reply_reference: nextReplyTarget ? {
            id: Number(nextReplyTarget.id),
            sender_id: null,
            sender_name: String(nextReplyTarget.sender_name || ''),
            body: String(nextReplyTarget.preview || ''),
            audio_path: null,
            image_path: null,
            file_path: null,
            file_name: null,
            created_at: null,
        } : null,
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

    if (isGroupConversation) {
        const recipientCount = Number(message.group_delivery?.recipient_count || 0);
        const deliveredCount = Number(message.group_delivery?.delivered_count || 0);
        const readCount = Number(message.group_delivery?.read_count || 0);
        if (recipientCount > 0 && readCount >= recipientCount) {
            return 'read';
        }
        if (deliveredCount > 0) {
            return 'delivered';
        }
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

function formatHumanTimestampWithAt(value) {
    const label = formatHumanTimestamp(value);
    return label.replace(/^(.*)\s(\d{2}:\d{2})$/, '$1 at $2');
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

    let reactionLines = [];
    if (isGroupConversation) {
        const grouped = new Map();
        Array.from(uniqueByUser.values()).forEach((emoji) => {
            grouped.set(emoji, (grouped.get(emoji) || 0) + 1);
        });
        reactionLines = Array.from(grouped.entries())
            .sort((left, right) => right[1] - left[1])
            .slice(0, 3)
            .map(([emoji, total]) => `${escapeHtml(emoji)}${total > 1 ? ` ${total}` : ''}`);
    } else {
        reactionLines = Array.from(uniqueByUser.values())
            .slice(0, 3)
            .map((emoji) => escapeHtml(emoji));
    }

    if (reactionLines.length === 0) {
        return '';
    }

    return `<div class="message-reactions" aria-label="Reactions">${reactionLines.map((line) => `<span class="message-reactions-line">${line}</span>`).join('')}</div>`;
}

function reactionSignatureForMessage(message) {
    return (Array.isArray(message?.reactions) ? message.reactions : [])
        .map((reaction) => `${Number(reaction?.user_id || 0)}:${String(reaction?.emoji || '').trim()}`)
        .filter((entry) => !entry.endsWith(':'))
        .sort()
        .join('|');
}

function changedReactionMessageIds(previousMessages, nextMessages) {
    const previousMap = new Map(previousMessages.map((message) => [Number(message?.id || 0), reactionSignatureForMessage(message)]));
    return new Set(nextMessages
        .map((message) => {
            const messageId = Number(message?.id || 0);
            if (!messageId) {
                return 0;
            }
            const nextSignature = reactionSignatureForMessage(message);
            if (nextSignature === '') {
                return 0;
            }
            return previousMap.get(messageId) !== nextSignature ? messageId : 0;
        })
        .filter((messageId) => messageId > 0));
}

function renderReplyReference(message) {
    const replyRef = message?.reply_reference;
    const replyId = Number(replyRef?.id || message?.reply_to_message_id || 0);
    if (!replyRef || !replyId) {
        return '';
    }
    const replyName = Number(replyRef?.sender_id || 0) === currentUserId
        ? 'You'
        : String(replyRef?.sender_name || 'Message');
    const preview = replySnippetFromMessage(replyRef);
    return `<a href="#message-${replyId}" class="message-reply-reference" data-reply-target-id="${replyId}" aria-label="Jump to referenced message">
        <strong>${escapeHtml(replyName)}</strong>
        <span>${escapeHtml(preview)}</span>
    </a>`;
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

function ensureMessageActionMenu() {
    if (messageActionMenuEl) {
        return messageActionMenuEl;
    }

    const menu = document.createElement('div');
    menu.className = 'message-action-menu';
    menu.hidden = true;
    menu.innerHTML = '<button type="button" data-action="delete-message">Delete message</button>';
    menu.querySelector('button[data-action="delete-message"]')?.addEventListener('click', async () => {
        if (!messageActionMessageId) {
            return;
        }
        await deleteMessageById(messageActionMessageId);
        hideMessageActionMenu();
    });
    document.body.appendChild(menu);
    messageActionMenuEl = menu;
    return menu;
}

function hideMessageActionMenu() {
    if (!messageActionMenuEl) {
        return;
    }
    messageActionMenuEl.hidden = true;
    messageActionMessageId = 0;
}

function showMessageActionMenu(anchorEl, messageId) {
    if (!(anchorEl instanceof HTMLElement) || !messageId) {
        return;
    }

    hideReactionPicker();
    hideReactionDetailsPanel();
    const menu = ensureMessageActionMenu();
    menu.hidden = false;
    messageActionMessageId = messageId;

    const anchorRect = anchorEl.getBoundingClientRect();
    requestAnimationFrame(() => {
        const menuRect = menu.getBoundingClientRect();
        const minLeft = 8;
        const maxLeft = window.innerWidth - menuRect.width - 8;
        const centeredLeft = anchorRect.left + (anchorRect.width / 2) - (menuRect.width / 2);
        const left = Math.max(minLeft, Math.min(maxLeft, centeredLeft));
        const maxTop = window.innerHeight - menuRect.height - 8;
        const top = Math.max(8, Math.min(maxTop, anchorRect.bottom + 8));
        menu.style.left = `${left}px`;
        menu.style.top = `${top}px`;
    });
}

async function deleteMessageById(messageId) {
    if (!Number(messageId)) {
        return;
    }

    try {
        const response = await fetch(conversationApiUrl(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken },
            body: new URLSearchParams({
                action: 'delete_message',
                message_id: String(Number(messageId)),
                csrf_token: csrfToken,
            }),
        });
        const payload = await response.json();
        if (!response.ok) {
            showError(payload?.error || 'Could not delete this message right now.');
            return;
        }

        if (payload?.payload && typeof payload.payload === 'object') {
            removeMessage(Number(messageId));
            applyConversationPayload(payload.payload);
            syncReadStateSoon();
            return;
        }

        if (typeof payload?.signature === 'string' && payload.signature !== '') {
            conversationSignature = payload.signature;
        }

        await refreshConversation();
    } catch (error) {
        showError('Could not delete this message right now.');
    }
}

async function editMessageById(messageId) {
    const targetMessage = (window.__messagesState || []).find((item) => Number(item.id) === Number(messageId));
    if (!targetMessage) {
        showError('Message not found.');
        return;
    }

    setEditTargetByMessage(targetMessage);
}

async function submitComposerEdit() {
    if (!editTarget) {
        return;
    }

    const trimmedBody = String(bodyEl.value || '').trim();
    if (trimmedBody === '') {
        showError('Message cannot be empty.');
        return;
    }

    if (trimmedBody === String(editTarget.originalBody || '').trim()) {
        clearEditTarget();
        return;
    }

    const targetId = Number(editTarget.id || 0);
    if (!targetId) {
        clearEditTarget();
        return;
    }

    try {
        const response = await fetch(conversationApiUrl(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken },
            body: new URLSearchParams({
                action: 'edit_message',
                message_id: String(targetId),
                body: trimmedBody,
                csrf_token: csrfToken,
            }),
        });
        const payload = await response.json();
        if (!response.ok) {
            showError(payload?.error || 'Could not edit this message right now.');
            return;
        }
        clearEditTarget(false);

        if (payload?.payload && typeof payload.payload === 'object') {
            applyConversationPayload(payload.payload);
            syncReadStateSoon();
            return;
        }

        await refreshConversation();
    } catch (error) {
        showError('Could not edit this message right now.');
    }
}

function clearLongPressTimer() {
    if (longPressTimer !== null) {
        window.clearTimeout(longPressTimer);
        longPressTimer = null;
    }
}

function hideReactionDetailsPanel() {
    if (!reactionMembersModal) {
        return;
    }
    reactionMembersModal.hidden = true;
    reactionMembersModal.setAttribute('aria-hidden', 'true');
    if (reactionMembersListEl) {
        reactionMembersListEl.innerHTML = '';
    }
    if (reactionMembersEmptyEl) {
        reactionMembersEmptyEl.hidden = true;
    }
    document.body.style.overflow = '';
}

function hideMessageDeliveryPanel() {
    if (!messageDeliveryModal) {
        return;
    }
    messageDeliveryModal.hidden = true;
    messageDeliveryModal.setAttribute('aria-hidden', 'true');
    if (messageDeliveryListEl) {
        messageDeliveryListEl.innerHTML = '';
    }
    if (messageDeliveryEmptyEl) {
        messageDeliveryEmptyEl.hidden = true;
    }
    document.body.style.overflow = '';
}

function showMessageDeliveryDetails(messageId) {
    const message = (window.__messagesState || []).find((item) => Number(item.id) === Number(messageId));
    if (!messageDeliveryModal || !messageDeliveryListEl || !messageDeliveryEmptyEl || !message || !isGroupConversation) {
        return;
    }

    const readBy = Array.isArray(message.group_delivery?.read_by) ? message.group_delivery.read_by : [];
    const deliveredTo = Array.isArray(message.group_delivery?.delivered_to) ? message.group_delivery.delivered_to : [];
    const readUserIds = new Set(readBy.map((entry) => Number(entry?.user_id || 0)));
    const deliveredOnly = deliveredTo.filter((entry) => !readUserIds.has(Number(entry?.user_id || 0)));
    const lines = [
        ...readBy.map((entry) => ({
            label: String(entry?.username || `User #${Number(entry?.user_id || 0)}`),
            status: entry?.read_at
                ? `Seen ${formatHumanTimestampWithAt(entry.read_at)}`
                : 'Seen',
        })),
        ...deliveredOnly.map((entry) => ({
            label: String(entry?.username || `User #${Number(entry?.user_id || 0)}`),
            status: entry?.delivered_at
                ? `Delivered ${formatHumanTimestampWithAt(entry.delivered_at)}`
                : 'Delivered',
        })),
    ];

    hideReactionPicker();
    hideReactionDetailsPanel();
    if (lines.length === 0) {
        messageDeliveryListEl.innerHTML = '';
        messageDeliveryEmptyEl.hidden = false;
    } else {
        messageDeliveryListEl.innerHTML = lines.map((line) => (
            `<div class="member-picker-item"><div class="member-picker-copy"><strong>${escapeHtml(line.label)}</strong><span>${escapeHtml(line.status)}</span></div></div>`
        )).join('');
        messageDeliveryEmptyEl.hidden = true;
    }
    messageDeliveryModal.hidden = false;
    messageDeliveryModal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    messageDeliveryClose?.focus();
}

function showReactionPicker(anchorEl, messageId, existingEmoji = '', allowReactions = true, actionOptions = {}) {
    if (!(anchorEl instanceof HTMLElement) || !messageId) {
        return;
    }

    hideReactionDetailsPanel();
    hideMessageDeliveryPanel();
    const picker = ensureReactionPicker();
    const options = allowReactions ? [...POPULAR_REACTIONS] : [];
    if (allowReactions && existingEmoji && !options.includes(existingEmoji)) {
        options.unshift(existingEmoji);
    }
    const reactionButtons = options.map((emoji) => `
        <button type="button" data-emoji="${escapeHtml(emoji)}" class="${emoji === existingEmoji ? 'is-active' : ''}" aria-label="React ${escapeHtml(emoji)}">${escapeHtml(emoji)}</button>
    `).join('');
    const removeButton = allowReactions && existingEmoji ? '<button type="button" class="reaction-remove" data-emoji="" aria-label="Remove reaction">✕</button>' : '';
    const replyButton = actionOptions.reply !== false
        ? '<button type="button" class="reaction-action" data-action="reply" aria-label="Reply to message" title="Reply"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m9 14-5-5 5-5"></path><path d="M4 9h9a7 7 0 0 1 7 7v1"></path></svg></button>'
        : '';
    const copyButton = actionOptions.copy === false
        ? ''
        : '<button type="button" class="reaction-action" data-action="copy" aria-label="Copy message" title="Copy"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="9" y="9" width="11" height="11" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg></button>';
    const forwardButton = actionOptions.forward
        ? '<button type="button" class="reaction-action" data-action="forward" aria-label="Forward message" title="Forward"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m10 14-5-5 5-5"></path><path d="M5 9h8a6 6 0 0 1 6 6v1"></path><path d="m14 14-5-5 5-5"></path><path d="M9 9h4"></path></svg></button>'
        : '';
    const editButton = actionOptions.edit
        ? '<button type="button" class="reaction-action" data-action="edit" aria-label="Edit message" title="Edit"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 20h9"></path><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"></path></svg></button>'
        : '';
    const deliveryDetailsButton = actionOptions.deliveryDetails
        ? '<button type="button" class="reaction-action" data-action="delivery-details" aria-label="View delivery details" title="Delivery details"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6Z"></path><circle cx="12" cy="12" r="3"></circle></svg></button>'
        : '';
    const deleteButton = actionOptions.delete
        ? '<button type="button" class="reaction-action danger" data-action="delete" aria-label="Delete for me" title="Delete for me"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 7h16"></path><path d="M10 11v6"></path><path d="M14 11v6"></path><path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12"></path><path d="M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"></path></svg></button>'
        : '';
    const pinIcon = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 17v5"></path><path d="m15 3 2 2-3 6v3H10v-3L7 5l2-2z"></path></svg>';
    const unpinIcon = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 17v5"></path><path d="m15 3 2 2-3 6v3H10v-3L7 5l2-2z"></path><path d="M4 4l16 16"></path></svg>';
    const pinButton = actionOptions.pin === false
        ? ''
        : `<button type="button" class="reaction-action" data-action="pin" aria-label="${actionOptions.pinned ? 'Unpin message' : 'Pin message'}" title="${actionOptions.pinned ? 'Unpin' : 'Pin'}">${actionOptions.pinned ? unpinIcon : pinIcon}</button>`;
    const reactionsRow = reactionButtons + removeButton;
    const actionsRow = replyButton + copyButton + forwardButton + pinButton + editButton + deliveryDetailsButton + deleteButton;
    const actionRowClasses = reactionsRow !== ''
        ? 'reaction-picker-row reaction-picker-actions with-reaction-row'
        : 'reaction-picker-row reaction-picker-actions';
    picker.innerHTML = `${reactionsRow !== '' ? `<div class="reaction-picker-row reaction-picker-reactions">${reactionsRow}</div>` : ''}<div class="${actionRowClasses}">${actionsRow}</div>`;

    picker.querySelectorAll('button[data-emoji]').forEach((buttonEl) => {
        buttonEl.addEventListener('click', () => {
            const emoji = String(buttonEl.getAttribute('data-emoji') || '');
            if (!reactionPickerMessageId) {
                return;
            }
            if (!prefersReducedMotion()) {
                buttonEl.classList.add('is-tapped');
                buttonEl.addEventListener('animationend', () => {
                    buttonEl.classList.remove('is-tapped');
                    hideReactionPicker();
                }, { once: true });
                window.setTimeout(() => {
                    buttonEl.classList.remove('is-tapped');
                    if (reactionPickerMessageId) {
                        hideReactionPicker();
                    }
                }, 220);
            } else {
                hideReactionPicker();
            }
            setMessageReaction(reactionPickerMessageId, emoji);
        });
    });
    picker.querySelector('button[data-action="reply"]')?.addEventListener('click', () => {
        const message = (window.__messagesState || []).find((item) => Number(item.id) === Number(messageId));
        if (message) {
            setReplyTargetByMessage(message);
        }
        hideReactionPicker();
    });
    picker.querySelector('button[data-action="copy"]')?.addEventListener('click', async () => {
        hideReactionPicker();
        await copyMessageById(messageId);
    });
    picker.querySelector('button[data-action="forward"]')?.addEventListener('click', () => {
        const message = (window.__messagesState || []).find((item) => Number(item.id) === Number(messageId));
        if (message) {
            openForwardPickerByMessage(message);
        }
        hideReactionPicker();
    });
    picker.querySelector('button[data-action="edit"]')?.addEventListener('click', async () => {
        hideReactionPicker();
        await editMessageById(messageId);
    });
    picker.querySelector('button[data-action="delivery-details"]')?.addEventListener('click', () => {
        hideReactionPicker();
        showMessageDeliveryDetails(messageId);
    });
    picker.querySelector('button[data-action="delete"]')?.addEventListener('click', async () => {
        hideReactionPicker();
        await deleteMessageById(messageId);
    });
    picker.querySelector('button[data-action="pin"]')?.addEventListener('click', async () => {
        hideReactionPicker();
        await toggleMessagePin(messageId);
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

async function copyMessageById(messageId) {
    const targetMessageId = Number(messageId || 0);
    if (!targetMessageId) {
        showError('Unable to copy this message.');
        return;
    }
    const message = (window.__messagesState || []).find((item) => Number(item.id) === targetMessageId);
    if (!message) {
        showError('Unable to copy this message.');
        return;
    }

    const copyText = replySnippetFromMessage(message);
    if (copyText === '') {
        showError('Nothing to copy from this message.');
        return;
    }

    try {
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            await navigator.clipboard.writeText(copyText);
        } else {
            const fallbackInput = document.createElement('textarea');
            fallbackInput.value = copyText;
            fallbackInput.setAttribute('readonly', 'readonly');
            fallbackInput.style.position = 'fixed';
            fallbackInput.style.left = '-9999px';
            document.body.appendChild(fallbackInput);
            fallbackInput.select();
            document.execCommand('copy');
            fallbackInput.remove();
        }
        showHint('Message copied.');
    } catch (error) {
        showError('Unable to copy message right now.');
    }
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
    if (!reactionMembersModal || !reactionMembersListEl || !reactionMembersEmptyEl || !message || !Array.isArray(message.reactions) || message.reactions.length === 0) {
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

    hideReactionPicker();
    hideMessageDeliveryPanel();
    reactionMembersListEl.innerHTML = lines.map((line) => {
        const separatorIndex = line.indexOf('  ');
        const emoji = separatorIndex >= 0 ? line.slice(0, separatorIndex).trim() : '';
        const name = separatorIndex >= 0 ? line.slice(separatorIndex + 2).trim() : line;
        return `<div class="member-picker-item"><div class="member-picker-copy"><strong>${escapeHtml(name)}</strong><span>Reacted with ${escapeHtml(emoji)}</span></div></div>`;
    }).join('');
    reactionMembersEmptyEl.hidden = lines.length > 0;
    reactionMembersModal.hidden = false;
    reactionMembersModal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    reactionMembersClose?.focus();
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

function reconcilePendingTextDuplicates(messages) {
    const list = Array.isArray(messages) ? [...messages] : [];
    if (list.length < 2) {
        return list;
    }

    const matchWindowMs = 8000;
    const confirmedByKey = new Map();

    const buildKey = (message) => {
        const body = String(message?.body || '').trim();
        const replyId = Number(message?.reply_to_message_id || message?.reply_reference?.id || 0);
        return `${body}::${replyId}`;
    };

    list.forEach((message) => {
        if (!message || message.pending || Number(message.sender_id) !== currentUserId) {
            return;
        }
        const body = String(message.body || '').trim();
        if (body === '' || message.audio_path || message.image_path || message.file_path) {
            return;
        }
        const key = buildKey(message);
        const bucket = confirmedByKey.get(key) || [];
        bucket.push(Date.parse(message.created_at) || 0);
        confirmedByKey.set(key, bucket);
    });

    if (confirmedByKey.size === 0) {
        return list;
    }

    const removeIndices = new Set();
    list.forEach((message, index) => {
        if (!message || !message.pending || Number(message.sender_id) !== currentUserId) {
            return;
        }
        const body = String(message.body || '').trim();
        if (body === '' || message.audio_path || message.image_path || message.file_path) {
            return;
        }

        const key = buildKey(message);
        const bucket = confirmedByKey.get(key);
        if (!bucket || bucket.length === 0) {
            return;
        }

        const pendingTime = Date.parse(message.created_at) || 0;
        const matchIndex = bucket.findIndex((confirmedTime) => Math.abs(confirmedTime - pendingTime) <= matchWindowMs);
        if (matchIndex === -1) {
            return;
        }

        removeIndices.add(index);
        bucket.splice(matchIndex, 1);
        if (bucket.length === 0) {
            confirmedByKey.delete(key);
        } else {
            confirmedByKey.set(key, bucket);
        }
    });

    if (removeIndices.size === 0) {
        return list;
    }

    return list.filter((_, index) => !removeIndices.has(index));
}

function pendingMessages(messages) {
    return (messages || []).filter((message) => Boolean(message && message.pending));
}

function removeMessage(messageId) {
    renderMessages((window.__messagesState || []).filter((item) => item.id !== messageId));
}


function setLightboxMenuOpen(isOpen) {
    if (!lightboxMenu || !lightboxMenuButton) {
        return;
    }
    const nextOpen = Boolean(isOpen);
    lightboxMenu.hidden = !nextOpen;
    lightboxMenuButton.setAttribute('aria-expanded', nextOpen ? 'true' : 'false');
}

function openImageLightbox(src, filename, messageId = 0) {
    if (!src || !imageLightbox || !lightboxImage) {
        return;
    }

    lastFocusedElement = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    lightboxImage.src = src;
    lightboxDownloadHref = src;
    lightboxDownloadFilename = filename || 'chat-image';
    lightboxActiveMessageId = Number(messageId || 0);
    const lightboxMessage = (window.__messagesState || []).find((item) => Number(item.id) === lightboxActiveMessageId);
    const canReactToLightboxImage = Boolean(lightboxMessage) && Number(lightboxMessage.sender_id) !== currentUserId;
    setLightboxMenuOpen(false);
    if (lightboxReact instanceof HTMLButtonElement) {
        lightboxReact.hidden = !canReactToLightboxImage;
        lightboxReact.disabled = !canReactToLightboxImage;
    }
    if (lightboxReply instanceof HTMLButtonElement) {
        lightboxReply.disabled = lightboxActiveMessageId <= 0;
    }
    if (lightboxForward instanceof HTMLButtonElement) {
        lightboxForward.disabled = lightboxActiveMessageId <= 0;
    }
    imageLightbox.hidden = false;
    imageLightbox.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    if (!lightboxHistoryEntryActive) {
        const nextState = Object.assign({}, window.history.state || {}, { imageLightboxOpen: true });
        window.history.pushState(nextState, document.title);
        lightboxHistoryEntryActive = true;
    }

    requestAnimationFrame(() => {
        imageLightbox.classList.add('is-visible');
        lightboxBack?.focus();
    });
}

function closeImageLightbox(options = {}) {
    if (!imageLightbox || imageLightbox.hidden) {
        return;
    }
    const viaHistory = Boolean(options && options.viaHistory);

    setLightboxMenuOpen(false);
    imageLightbox.classList.remove('is-visible');
    imageLightbox.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';

    window.setTimeout(() => {
        imageLightbox.hidden = true;
        lightboxImage.src = '';
        lightboxDownloadHref = '#';
        lightboxDownloadFilename = 'chat-image';
        lightboxActiveMessageId = 0;
    }, 240);

    if (lightboxHistoryEntryActive) {
        if (viaHistory) {
            lightboxHistoryEntryActive = false;
        } else if (window.history.state && window.history.state.imageLightboxOpen) {
            window.history.back();
        } else {
            lightboxHistoryEntryActive = false;
        }
    }

    if (lastFocusedElement) {
        lastFocusedElement.focus();
    }
}

function formatVoiceTime(seconds) {
    const safeSeconds = Number.isFinite(seconds) && seconds > 0 ? Math.floor(seconds) : 0;
    const minutes = Math.floor(safeSeconds / 60);
    const remainder = String(safeSeconds % 60).padStart(2, '0');
    return `${minutes}:${remainder}`;
}

const voiceWaveformCache = new Map();

function seededValue(seedText, index) {
    let hash = 2166136261;
    const source = `${seedText}:${index}`;
    for (let offset = 0; offset < source.length; offset += 1) {
        hash ^= source.charCodeAt(offset);
        hash = Math.imul(hash, 16777619);
    }
    return (hash >>> 0) / 4294967295;
}

function seededVoiceBarHeight(seedText, index) {
    const random = seededValue(seedText, index);
    const wave = Math.sin((index + 1) * 0.55 + random * 2.4) * 0.5 + 0.5;
    return Math.max(18, Math.min(92, Math.round(24 + wave * 62)));
}

function setVoiceBarHeights(bars, heights) {
    bars.forEach((barEl, index) => {
        const height = Number.isFinite(heights[index]) ? heights[index] : heights[heights.length - 1];
        barEl.style.height = `${Math.max(18, Math.min(92, Math.round(height || 18)))}%`;
    });
}

function createVoiceBars(seedText = '') {
    const bars = [];
    for (let index = 0; index < 42; index += 1) {
        const height = seededVoiceBarHeight(seedText || 'voice', index);
        bars.push(`<span class="voice-note-bar" style="height:${height}%"></span>`);
    }
    return bars.join('');
}

async function hydrateVoiceBarsFromAudio(audioEl, bars, seedText, onDurationResolved = null) {
    const sourceUrl = String(audioEl.currentSrc || audioEl.src || '');
    if (!sourceUrl || bars.length === 0) {
        return;
    }

    const cacheKey = sourceUrl;
    const cached = voiceWaveformCache.get(cacheKey);
    if (cached && typeof cached === 'object' && Array.isArray(cached.heights) && cached.heights.length === bars.length) {
        setVoiceBarHeights(bars, cached.heights);
        if (typeof onDurationResolved === 'function' && Number.isFinite(cached.duration) && cached.duration > 0) {
            onDurationResolved(cached.duration);
        }
        return;
    }

    const pendingPromise = cached instanceof Promise ? cached : (async () => {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) {
            return null;
        }
        const response = await fetch(sourceUrl, { credentials: 'same-origin' });
        if (!response.ok) {
            return null;
        }
        const buffer = await response.arrayBuffer();
        const context = new AudioContextClass();
        try {
            const audioBuffer = await context.decodeAudioData(buffer.slice(0));
            const channelData = audioBuffer.getChannelData(0);
            if (!(channelData instanceof Float32Array) || channelData.length === 0) {
                return null;
            }
            const samplesPerBar = Math.max(1, Math.floor(channelData.length / bars.length));
            const computedHeights = Array.from({ length: bars.length }, (_, index) => {
                const start = index * samplesPerBar;
                const end = Math.min(channelData.length, start + samplesPerBar);
                let peak = 0;
                for (let sampleIndex = start; sampleIndex < end; sampleIndex += 1) {
                    peak = Math.max(peak, Math.abs(channelData[sampleIndex]));
                }
                return 18 + Math.min(74, Math.round(peak * 82));
            });
            return {
                heights: computedHeights,
                duration: Number.isFinite(audioBuffer.duration) ? audioBuffer.duration : 0,
            };
        } finally {
            context.close().catch(() => {
                // Ignore AudioContext close errors.
            });
        }
    })();

    voiceWaveformCache.set(cacheKey, pendingPromise);

    try {
        const payload = await pendingPromise;
        if (payload && Array.isArray(payload.heights) && payload.heights.length === bars.length) {
            voiceWaveformCache.set(cacheKey, payload);
            setVoiceBarHeights(bars, payload.heights);
            if (typeof onDurationResolved === 'function' && Number.isFinite(payload.duration) && payload.duration > 0) {
                onDurationResolved(payload.duration);
            }
            return;
        }
    } catch (error) {
        // Ignore waveform extraction errors and keep seeded bars.
    }

    const fallbackHeights = bars.map((_, index) => seededVoiceBarHeight(seedText || sourceUrl, index));
    const fallbackPayload = { heights: fallbackHeights, duration: 0 };
    voiceWaveformCache.set(cacheKey, fallbackPayload);
    setVoiceBarHeights(bars, fallbackHeights);
}

function setupVoiceNotePlayers(rootEl = messagesEl) {
    rootEl.querySelectorAll('.voice-note-player').forEach((playerEl) => {
        if (playerEl.dataset.ready === '1') {
            return;
        }
        playerEl.dataset.ready = '1';
        const audioEl = playerEl.querySelector('audio');
        const toggleButton = playerEl.querySelector('.voice-note-toggle');
        const waveEl = playerEl.querySelector('.voice-note-wave');
        const timeEl = playerEl.querySelector('.voice-note-time');
        if (!(audioEl instanceof HTMLAudioElement) || !(toggleButton instanceof HTMLButtonElement) || !(waveEl instanceof HTMLElement) || !(timeEl instanceof HTMLElement)) {
            return;
        }
        const bars = Array.from(waveEl.querySelectorAll('.voice-note-bar'));
        const waveSeed = String(waveEl.dataset.waveSeed || audioEl.currentSrc || audioEl.src || 'voice');
        setVoiceBarHeights(bars, bars.map((_, index) => seededVoiceBarHeight(waveSeed, index)));
        let estimatedDuration = 0;
        const updateEstimatedDuration = (duration) => {
            if (Number.isFinite(duration) && duration > 0) {
                estimatedDuration = duration;
            }
        };
        const updateBars = () => {
            const duration = Number.isFinite(audioEl.duration) && audioEl.duration > 0 ? audioEl.duration : estimatedDuration;
            const progress = duration > 0 ? (audioEl.currentTime / duration) : 0;
            const activeCount = Math.round(progress * bars.length);
            bars.forEach((barEl, index) => {
                barEl.classList.toggle('active', index < activeCount);
            });
            timeEl.textContent = formatVoiceTime(duration > 0 ? Math.max(duration - audioEl.currentTime, 0) : 0);
        };
        const syncToggle = () => {
            const isPlaying = !audioEl.paused && !audioEl.ended;
            toggleButton.textContent = isPlaying ? '❚❚' : '▶';
        };
        toggleButton.addEventListener('click', async () => {
            if (audioEl.paused || audioEl.ended) {
                try {
                    await audioEl.play();
                } catch (error) {
                    return;
                }
            } else {
                audioEl.pause();
            }
            syncToggle();
        });
        waveEl.addEventListener('click', (event) => {
            const rect = waveEl.getBoundingClientRect();
            if (rect.width <= 0 || !Number.isFinite(audioEl.duration) || audioEl.duration <= 0) {
                return;
            }
            const ratio = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width));
            audioEl.currentTime = ratio * audioEl.duration;
            updateBars();
        });
        audioEl.addEventListener('loadedmetadata', () => {
            updateEstimatedDuration(audioEl.duration);
            updateBars();
            if (shouldAutoScroll || isNearBottom()) {
                scrollMessagesToEnd();
            }
        }, { once: true });
        audioEl.addEventListener('durationchange', () => {
            updateEstimatedDuration(audioEl.duration);
            updateBars();
        });
        audioEl.addEventListener('loadeddata', updateBars);
        audioEl.addEventListener('timeupdate', updateBars);
        audioEl.addEventListener('play', syncToggle);
        audioEl.addEventListener('pause', syncToggle);
        audioEl.addEventListener('ended', () => {
            syncToggle();
            updateBars();
        });
        syncToggle();
        updateBars();
        hydrateVoiceBarsFromAudio(audioEl, bars, waveSeed, (duration) => {
            updateEstimatedDuration(duration);
            updateBars();
        });
        if (audioEl.preload !== 'none') {
            audioEl.load();
        }
    });
}

function renderMessages(messages) {
    const previousMessages = window.__messagesState || [];
    const normalizedMessages = reconcilePendingTextDuplicates(messages);
    const reactionChangedMessageIds = changedReactionMessageIds(previousMessages, normalizedMessages);
    const shouldPinToBottom = shouldAutoScroll || isNearBottom() || initialScrollPending;
    window.__messagesState = normalizedMessages;
    const availableMessageIds = new Set(normalizedMessages.map((message) => Number(message.id || 0)).filter((id) => id > 0));
    const validPinnedIds = pinnedMessageIds.filter((id) => availableMessageIds.has(id));
    const pinnedMessages = validPinnedIds
        .map((id) => normalizedMessages.find((message) => Number(message.id) === id))
        .filter((message) => Boolean(message));
    const signature = JSON.stringify(normalizedMessages.map((message) => [
        message.id,
        message.created_at,
        message.edited_at || '',
        message.delivered_at || '',
        message.read_at || '',
        Number(message.group_delivery?.recipient_count || 0),
        Number(message.group_delivery?.delivered_count || 0),
        Number(message.group_delivery?.read_count || 0),
        (Array.isArray(message.group_delivery?.read_by) ? message.group_delivery.read_by : []).map((entry) => `${Number(entry?.user_id || 0)}:${String(entry?.username || '')}`).join('|'),
        Boolean(message.pending),
        message.body || '',
        message.audio_path || '',
        message.image_path || '',
        message.file_path || '',
        message.file_name || '',
        Boolean(message.is_forwarded),
        Number(message.reply_to_message_id || message.reply_reference?.id || 0),
        String(message.reply_reference?.body || ''),
        Boolean(message.attachment_expired),
        (Array.isArray(message.reactions) ? message.reactions : []).map((reaction) => `${Number(reaction?.user_id || 0)}:${String(reaction?.emoji || '')}`).join('|'),
    ]).concat(`pins:${validPinnedIds.join('|')}`));
    if (signature === renderedSignature) {
        return;
    }

    hideReactionPicker();
    hideReactionDetailsPanel();
    hideMessageDeliveryPanel();
    hideMessageActionMenu();
    clearLongPressTimer();

    renderedSignature = signature;

    renderPinnedPanel(pinnedMessages);

    if (normalizedMessages.length === 0) {
        messagesEl.innerHTML = '<div class="empty-state">No messages yet. Say hi, share a file, share a photo, or tap the microphone to send a voice note.</div>';
    } else {
        messagesEl.innerHTML = normalizedMessages.map((message) => {
            const isMine = Number(message.sender_id) === currentUserId;
            const shouldShowSender = isGroupConversation && !isMine;
            const textDirection = detectTextDirection(message.body || '');
            const isPinned = isMessagePinned(message.id);
            const body = message.body
                ? `<div class="message-text ${textDirection}" dir="${textDirection}">${escapeHtml(message.body).replace(/\n/g, '<br>')}</div>`
                : '';
            const mediaUrl = mediaUrlForMessage(message.id);
            const image = message.image_path
                ? `<button class="message-photo-button" type="button" data-image-src="${escapeHtml(mediaUrl)}" data-image-download="chat-image-${Number(message.id)}" data-image-message-id="${Number(message.id)}" aria-label="Open shared image full screen"><img class="message-photo" loading="lazy" src="${escapeHtml(mediaUrl)}" alt="Shared image"></button>`
                : '';
            const audio = message.audio_path
                ? `<div class="voice-note-player"><button class="voice-note-toggle" type="button" aria-label="Play voice note">▶</button><div class="voice-note-wave" role="slider" aria-label="Voice note waveform seek" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-wave-seed="${escapeHtml(String(message.id || mediaUrl))}">${createVoiceBars(String(message.id || mediaUrl))}</div><span class="voice-note-time">0:00</span><audio preload="auto" src="${escapeHtml(mediaUrl)}"></audio></div>`
                : '';
            const file = message.file_path
                ? `<a class="message-file" href="${escapeHtml(mediaUrl)}" download="${escapeHtml(message.file_name || `shared-file-${Number(message.id)}`)}"><span class="message-file-icon">📎</span><span class="message-file-copy"><strong>${escapeHtml(message.file_name || `shared-file-${Number(message.id)}`)}</strong><span>Download file</span></span></a>`
                : '';
            const expiredAttachment = message.attachment_expired
                ? '<div class="message-text muted" dir="auto">Attachment expired.</div>'
                : '';
            const pendingLabel = message.pending ? ' · Sending…' : '';
            const ticks = renderDeliveryTicks(message);
            const replyReference = renderReplyReference(message);
            const senderLabel = shouldShowSender
                ? `<div class="message-sender">${escapeHtml(message.sender_name)}</div>`
                : '';
            const forwardedLabel = message.is_forwarded
                ? '<div class="message-forwarded"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m10 14-5-5 5-5"></path><path d="M5 9h8a6 6 0 0 1 6 6v1"></path><path d="m14 14-5-5 5-5"></path><path d="M9 9h4"></path></svg><span>Forwarded</span></div>'
                : '';
            const hasEditedTimestamp = Boolean(message.edited_at);
            const timeLabel = formatHumanTimestamp(hasEditedTimestamp ? message.edited_at : message.created_at);
            const editedLabel = hasEditedTimestamp ? ' · edited' : '';
            const reactions = renderMessageReactions(message);
            const hasReactionAnimation = !prefersReducedMotion() && reactionChangedMessageIds.has(Number(message.id));
            const myReaction = Array.isArray(message.reactions)
                ? String((message.reactions.find((reaction) => Number(reaction?.user_id) === currentUserId)?.emoji) || '')
                : '';
            const pinBadge = isPinned ? '<span class="message-pin-badge" aria-hidden="true">📌</span>' : '';

            const rowClasses = ['message-row'];
            if (isMine) {
                rowClasses.push('mine');
            }
            if (reactions !== '') {
                rowClasses.push('has-reactions');
            }
            if (message.audio_path) {
                rowClasses.push('private-audio');
            }

            return `
                <article id="message-${Number(message.id)}" class="${rowClasses.join(' ')}" data-message-id="${Number(message.id)}" data-sender-id="${Number(message.sender_id)}" data-my-reaction="${escapeHtml(myReaction)}">
                    <div class="message">
                        ${senderLabel}
                        ${forwardedLabel}
                        ${replyReference}
                        ${body}
                        ${image}
                        ${audio}
                        ${file}
                        ${expiredAttachment}
                        <div class="meta"><span class="meta-label">${pinBadge}${escapeHtml(timeLabel)}${editedLabel}${pendingLabel}</span><span>${ticks}</span></div>
                    </div>
                    ${reactions !== '' && hasReactionAnimation
                        ? reactions.replace('class="message-reactions"', 'class="message-reactions is-new-reaction"')
                        : reactions}
                </article>`;
        }).join('');

        messagesEl.querySelectorAll('img').forEach((imageEl) => {
            imageEl.addEventListener('load', () => {
                if (shouldAutoScroll || isNearBottom()) {
                    scrollMessagesToEnd();
                }
            }, { once: true });
        });

        setupVoiceNotePlayers(messagesEl);
        messagesEl.querySelectorAll('.message-row[data-message-id]').forEach((rowEl) => {
            const messageForRow = () => {
                const messageId = Number(rowEl.getAttribute('data-message-id') || 0);
                if (!messageId) {
                    return null;
                }
                return (window.__messagesState || []).find((item) => Number(item.id) === messageId) || null;
            };
            const hasImageMessage = () => Boolean(messageForRow()?.image_path);
            const canReactToRow = () => {
                const senderId = Number(rowEl.getAttribute('data-sender-id') || 0);
                return senderId > 0 && senderId !== currentUserId;
            };
            const isOwnMessage = () => Number(rowEl.getAttribute('data-sender-id') || 0) === currentUserId;
            const openReactionPickerFromTap = (event) => {
                if (event.target instanceof HTMLElement && event.target.closest('a, button, audio, input, textarea, label, .message-reactions, .voice-note-wave')) {
                    return;
                }
                hideReactionPicker();
                hideMessageActionMenu();
            };

            rowEl.addEventListener('click', (event) => {
                if (longPressHandled) {
                    longPressHandled = false;
                    event.preventDefault();
                    return;
                }
                hideMessageActionMenu();
                openReactionPickerFromTap(event);
            });

            rowEl.addEventListener('pointerdown', (event) => {
                if (event.pointerType === 'mouse') {
                    return;
                }
                clearLongPressTimer();
                longPressHandled = false;
                longPressTimer = window.setTimeout(() => {
                    const messageId = Number(rowEl.getAttribute('data-message-id') || 0);
                    if (!messageId) {
                        return;
                    }
                    if (isOwnMessage()) {
                        showReactionPicker(rowEl, messageId, '', false, {
                            reply: false,
                            forward: true,
                            pin: true,
                            pinned: isMessagePinned(messageId),
                            copy: !hasImageMessage(),
                            edit: !hasImageMessage(),
                            deliveryDetails: isGroupConversation,
                            delete: true,
                        });
                        longPressHandled = true;
                        return;
                    }
                    if (!canReactToRow()) {
                        return;
                    }
                    const existingEmoji = String(rowEl.getAttribute('data-my-reaction') || '');
                    longPressHandled = true;
                    showReactionPicker(rowEl, messageId, existingEmoji, true, {
                        reply: true,
                        forward: true,
                        copy: !hasImageMessage(),
                        pin: true,
                        pinned: isMessagePinned(messageId),
                        edit: false,
                        delete: true,
                    });
                }, 480);
            });
            rowEl.addEventListener('pointerup', clearLongPressTimer);
            rowEl.addEventListener('pointercancel', clearLongPressTimer);
            rowEl.addEventListener('pointerleave', clearLongPressTimer);
            rowEl.addEventListener('contextmenu', (event) => {
                event.preventDefault();
                const messageId = Number(rowEl.getAttribute('data-message-id') || 0);
                if (!messageId) {
                    return;
                }
                if (isOwnMessage()) {
                    showReactionPicker(rowEl, messageId, '', false, {
                        reply: false,
                        forward: true,
                        pin: true,
                        pinned: isMessagePinned(messageId),
                        copy: !hasImageMessage(),
                        edit: !hasImageMessage(),
                        deliveryDetails: isGroupConversation,
                        delete: true,
                    });
                    return;
                }
                const existingEmoji = String(rowEl.getAttribute('data-my-reaction') || '');
                showReactionPicker(rowEl, messageId, existingEmoji, canReactToRow(), {
                    forward: true,
                    copy: !hasImageMessage(),
                    pin: true,
                    pinned: isMessagePinned(messageId),
                    delete: true,
                });
            });
            rowEl.querySelectorAll('[data-reply-target-id]').forEach((referenceEl) => {
                referenceEl.addEventListener('click', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    const targetId = Number(referenceEl.getAttribute('data-reply-target-id') || 0);
                    if (!targetId) {
                        return;
                    }
                    jumpToMessage(targetId);
                });
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
        messagesEl.querySelectorAll('.message-reactions.is-new-reaction').forEach((reactionEl) => {
            reactionEl.addEventListener('animationend', () => {
                reactionEl.classList.remove('is-new-reaction');
            }, { once: true });
        });
    }

    if (shouldPinToBottom) {
        scrollMessagesToEnd();
    } else {
        updateScrollToEndButton();
    }

    handleIncomingMessages(previousMessages, normalizedMessages);
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

    const hasText = editTarget !== null || bodyEl.value.trim() !== '';
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
            : (payload.messages.length === 0 ? [] : mergeMessages(pendingMessages(window.__messagesState || []), payload.messages));
        renderMessages(nextMessages);
        const unreadCount = nextMessages.filter((message) =>
            Number(message.sender_id) !== currentUserId && !message.read_at
        ).length;
        lastUnseenCounts.set(String(isGroupConversation ? groupId : conversationUserId), unreadCount);
    } else if (payload.message) {
        replacePendingMessage(payload.pending_id || '', payload.message);
        upsertMessage(payload.message);
    }
    if (Array.isArray(payload.pinned_message_ids)) {
        pinnedMessageIds = [...new Set(payload.pinned_message_ids
            .map((value) => Number(value))
            .filter((value) => Number.isInteger(value) && value > 0))];
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
        editGroupAvatarButton?.classList.toggle('hidden', !Boolean(groupState.can_update_avatar));
    }
    if (payload.presence) {
        updatePresence(payload.presence.is_online, payload.presence.updated_at || null);
    }
    if (Object.prototype.hasOwnProperty.call(payload, 'can_chat')) {
        canChat = Boolean(payload.can_chat);
    }
    if (Object.prototype.hasOwnProperty.call(payload, 'friendship')) {
        friendshipState = payload.friendship;
    }
    if (Object.prototype.hasOwnProperty.call(payload, 'blocking')) {
        blockingState = payload.blocking || { blocked_by_me: false, blocked_me: false, is_blocked: false };
    }
    updateFriendshipUi();
    updateMuteButtonLabel();
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

    if (textSendRetryTimer !== null) {
        window.clearTimeout(textSendRetryTimer);
        textSendRetryTimer = null;
    }

    textSendInFlight = true;
    isSending = true;
    updateFriendshipUi();

    while (pendingTextQueue.length > 0) {
        const nextMessage = pendingTextQueue[0];

        try {
            const controller = typeof AbortController === 'function' ? new AbortController() : null;
            const timeoutId = controller
                ? window.setTimeout(() => controller.abort(), TEXT_SEND_TIMEOUT_MS)
                : null;
            const response = await fetch(conversationApiUrl(), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken },
                signal: controller ? controller.signal : undefined,
                body: new URLSearchParams({
                    action: 'send_text',
                    body: nextMessage.body,
                    reply_to_message_id: String(Number(nextMessage.replyToMessageId || 0)),
                    csrf_token: csrfToken,
                }),
            }).finally(() => {
                if (timeoutId !== null) {
                    window.clearTimeout(timeoutId);
                }
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
            const sendError = error instanceof Error ? error : null;
            const timedOut = sendError?.name === 'AbortError';
            showError(timedOut
                ? 'Message send timed out. Retrying…'
                : 'Could not send message right now. Retrying…');
            if (textSendRetryTimer === null) {
                textSendRetryTimer = window.setTimeout(() => {
                    textSendRetryTimer = null;
                    flushPendingTextQueue();
                }, TEXT_SEND_RETRY_DELAY_MS);
            }
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
    if (!canChat) {
        showError('Friendship revoked. You cannot send new messages until you are friends again.');
        return;
    }
    if (editTarget) {
        submitComposerEdit();
        return;
    }
    const body = bodyEl.value.trim();
    if (!body) {
        return;
    }

    const shouldPinToBottom = isNearBottom();
    const activeReplyTarget = replyTarget ? { ...replyTarget } : null;
    const pendingMessage = createPendingMessage(body, 'text', activeReplyTarget);
    pendingTextQueue.push({
        body,
        pendingId: pendingMessage.id,
        replyToMessageId: activeReplyTarget ? Number(activeReplyTarget.id) : 0,
        shouldPinToBottom,
    });
    upsertMessage(pendingMessage);
    shouldAutoScroll = shouldPinToBottom;
    bodyEl.value = '';
    clearReplyTarget();
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
        const replyToMessageId = Number(replyTarget?.id || 0);
        formData.append('action', 'send_voice');
        formData.append('csrf_token', csrfToken);
        if (replyToMessageId > 0) {
            formData.append('reply_to_message_id', String(replyToMessageId));
        }
        formData.append('voice_note', blob, filename);

        const response = await fetch(conversationApiUrl(), {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrfToken },
            credentials: 'same-origin',
            body: formData,
        });
        const payload = await response.json();

        if (!response.ok) {
            showError(payload.error || 'Could not send voice note.');
            return false;
        }

        applyConversationPayload(payload);
        scrollMessagesToEnd();
        clearReplyTarget();
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
        let imageToUpload = file;
        const targetBytes = Math.max(350 * 1024, Number(imageUploadTargetBytes) || (4.5 * 1024 * 1024));
        if (file.size > targetBytes) {
            imageToUpload = await maybeCompressImageForUpload(file, targetBytes);
        }

        const formData = new FormData();
        const replyToMessageId = Number(replyTarget?.id || 0);
        formData.append('action', 'send_image');
        formData.append('csrf_token', csrfToken);
        if (replyToMessageId > 0) {
            formData.append('reply_to_message_id', String(replyToMessageId));
        }
        formData.append('image_file', imageToUpload, imageToUpload.name || file.name || 'photo.jpg');

        const response = await fetch(conversationApiUrl(), {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrfToken },
            credentials: 'same-origin',
            body: formData,
        });
        const payload = await response.json();

        if (!response.ok) {
            showError(payload.error || 'Could not send image.');
            return false;
        }

        applyConversationPayload(payload);
        if (!payload?.message?.image_path && !Array.isArray(payload?.messages)) {
            await refreshConversation();
        }
        scrollMessagesToEnd();
        clearReplyTarget();
        showHint('Image sent.');
        return true;
    } catch (error) {
        showError('Could not send image right now. Please try again.');
        return false;
    }
}

async function maybeCompressImageForUpload(file, maxBytes) {
    if (!(file instanceof File) || !String(file.type || '').startsWith('image/')) {
        return file;
    }

    const nonCanvasFriendlyTypes = ['image/heic', 'image/heif'];
    if (nonCanvasFriendlyTypes.includes(String(file.type || '').toLowerCase())) {
        return file;
    }

    const targetBytes = Math.max(350 * 1024, Number(maxBytes) || (4.5 * 1024 * 1024));
    let sourceUrl = '';
    try {
        sourceUrl = URL.createObjectURL(file);
        const image = await new Promise((resolve, reject) => {
            const imageEl = new Image();
            imageEl.decoding = 'async';
            imageEl.onload = () => resolve(imageEl);
            imageEl.onerror = () => reject(new Error('Could not decode image.'));
            imageEl.src = sourceUrl;
        });

        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d', { alpha: false });
        if (!context) {
            return file;
        }

        const baseWidth = Number(image.naturalWidth || image.width || 0);
        const baseHeight = Number(image.naturalHeight || image.height || 0);
        if (baseWidth <= 0 || baseHeight <= 0) {
            return file;
        }

        const scaleCandidates = [1, 0.92, 0.84, 0.76, 0.68, 0.6, 0.52];
        const qualityCandidates = [0.86, 0.78, 0.7, 0.62, 0.54, 0.46];
        let smallestBlob = null;

        for (const scale of scaleCandidates) {
            const width = Math.max(320, Math.round(baseWidth * scale));
            const height = Math.max(320, Math.round(baseHeight * scale));
            canvas.width = width;
            canvas.height = height;
            context.clearRect(0, 0, width, height);
            context.drawImage(image, 0, 0, width, height);

            for (const quality of qualityCandidates) {
                const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
                if (!(blob instanceof Blob)) {
                    continue;
                }

                if (!(smallestBlob instanceof Blob) || blob.size < smallestBlob.size) {
                    smallestBlob = blob;
                }

                if (blob.size <= targetBytes && blob.size < file.size) {
                    const nameWithoutExt = String(file.name || 'photo').replace(/\.[^/.]+$/, '');
                    return new File([blob], `${nameWithoutExt}.jpg`, { type: 'image/jpeg', lastModified: Date.now() });
                }
            }
        }

        if (smallestBlob instanceof Blob && smallestBlob.size < file.size) {
            const nameWithoutExt = String(file.name || 'photo').replace(/\.[^/.]+$/, '');
            return new File([smallestBlob], `${nameWithoutExt}.jpg`, { type: 'image/jpeg', lastModified: Date.now() });
        }
    } catch (error) {
        return file;
    } finally {
        if (sourceUrl) {
            URL.revokeObjectURL(sourceUrl);
        }
    }

    return file;
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

function forwardApiUrlForUser(targetUserId) {
    return `chat_api.php?user=${Number(targetUserId)}`;
}

async function forwardAttachmentAsFile(message, fallbackName) {
    const messageId = Number(message?.id || 0);
    if (messageId <= 0 || message?.attachment_expired) {
        throw new Error('This attachment is no longer available to forward.');
    }
    const response = await fetch(mediaUrlForMessage(messageId), {
        method: 'GET',
        credentials: 'same-origin',
    });
    if (!response.ok) {
        throw new Error('Could not load attachment to forward.');
    }
    const blob = await response.blob();
    if (!(blob instanceof Blob) || blob.size === 0) {
        throw new Error('Could not load attachment to forward.');
    }
    const fileName = String(message?.file_name || fallbackName || `forwarded-${messageId}`).trim() || `forwarded-${messageId}`;
    return new File([blob], fileName, { type: blob.type || 'application/octet-stream', lastModified: Date.now() });
}

async function forwardMessageToUser(message, targetUserId) {
    const targetId = Number(targetUserId || 0);
    if (!message || targetId <= 0) {
        throw new Error('Please choose who to forward this message to.');
    }

    const hasImage = Boolean(String(message?.image_path || '').trim());
    const hasFile = Boolean(String(message?.file_path || '').trim());
    const hasAudio = Boolean(String(message?.audio_path || '').trim());
    const body = String(message?.body || '').trim();
    let response;

    if (hasImage) {
        const imageFile = await forwardAttachmentAsFile(message, `forwarded-image-${Number(message.id || 0)}.jpg`);
        const formData = new FormData();
        formData.append('action', 'send_image');
        formData.append('csrf_token', csrfToken);
        formData.append('forwarded', 'true');
        formData.append('image_file', imageFile, imageFile.name);
        response = await fetch(forwardApiUrlForUser(targetId), {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrfToken },
            credentials: 'same-origin',
            body: formData,
        });
    } else if (hasFile) {
        const sharedFile = await forwardAttachmentAsFile(message, `forwarded-file-${Number(message.id || 0)}`);
        const formData = new FormData();
        formData.append('action', 'send_file');
        formData.append('csrf_token', csrfToken);
        formData.append('forwarded', 'true');
        formData.append('shared_file', sharedFile, sharedFile.name);
        response = await fetch(forwardApiUrlForUser(targetId), {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrfToken },
            credentials: 'same-origin',
            body: formData,
        });
    } else if (hasAudio) {
        const voiceFile = await forwardAttachmentAsFile(message, `forwarded-voice-${Number(message.id || 0)}.webm`);
        const formData = new FormData();
        formData.append('action', 'send_voice');
        formData.append('csrf_token', csrfToken);
        formData.append('forwarded', 'true');
        formData.append('voice_note', voiceFile, voiceFile.name);
        response = await fetch(forwardApiUrlForUser(targetId), {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrfToken },
            credentials: 'same-origin',
            body: formData,
        });
    } else if (body !== '') {
        response = await fetch(forwardApiUrlForUser(targetId), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken },
            credentials: 'same-origin',
            body: new URLSearchParams({
                action: 'send_text',
                body,
                forwarded: 'true',
                csrf_token: csrfToken,
            }),
        });
    } else {
        throw new Error('This message type cannot be forwarded.');
    }

    const payload = await response.json();
    if (!response.ok || payload?.error) {
        throw new Error(payload?.error || 'Could not forward message.');
    }

    if (!isGroupConversation && targetId === conversationUserId) {
        applyConversationPayload(payload);
        scrollMessagesToEnd();
    }
    return payload;
}

async function submitForwardToUser(targetUserId, targetUsername = '') {
    if (!pendingForwardMessage) {
        showError('Choose a message to forward first.');
        return;
    }

    showHint('Forwarding message…');
    activeUploadCount += 1;
    isSending = true;
    updateFriendshipUi();

    try {
        const normalizedTargetUserId = Number(targetUserId || 0);
        await forwardMessageToUser(pendingForwardMessage, normalizedTargetUserId);
        const label = String(targetUsername || `User #${Number(targetUserId || 0)}`);
        showHint(`Forwarded to ${label}.`);
        setMemberPickerOpen(false);
        if (normalizedTargetUserId > 0 && (isGroupConversation || normalizedTargetUserId !== conversationUserId)) {
            navigateWithTransition(`chat.php?user=${normalizedTargetUserId}`);
            return;
        }
    } catch (error) {
        showError(error instanceof Error ? error.message : 'Could not forward message right now.');
    } finally {
        activeUploadCount = Math.max(0, activeUploadCount - 1);
        isSending = textSendInFlight || pendingTextQueue.length > 0 || activeUploadCount > 0;
        updateFriendshipUi();
        updateActionButton();
    }
}

async function uploadSharedFile(file) {
    if (!(file instanceof File) || file.size === 0) {
        showError('Please choose a file to share.');
        return false;
    }

    try {
        const formData = new FormData();
        const replyToMessageId = Number(replyTarget?.id || 0);
        formData.append('action', 'send_file');
        formData.append('csrf_token', csrfToken);
        if (replyToMessageId > 0) {
            formData.append('reply_to_message_id', String(replyToMessageId));
        }
        formData.append('shared_file', file, file.name || 'shared-file');

        const response = await fetch(conversationApiUrl(), {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrfToken },
            credentials: 'same-origin',
            body: formData,
        });
        const payload = await response.json();

        if (!response.ok) {
            showError(payload.error || 'Could not send file.');
            return false;
        }

        applyConversationPayload(payload);
        if (!payload?.message?.file_path && !Array.isArray(payload?.messages)) {
            await refreshConversation();
        }
        scrollMessagesToEnd();
        clearReplyTarget();
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

quickGridButton?.addEventListener('click', () => {
    markUserInteraction();
    if (!canChat || isSending || quickGridButton.disabled) {
        return;
    }
    const isOpen = quickGridButton.getAttribute('aria-expanded') === 'true';
    setQuickGridOpen(!isOpen);
});

quickGridPanel?.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target.closest('[data-grid-icon]') : null;
    if (!target) {
        return;
    }
    const icon = target.getAttribute('data-grid-icon') || '';
    insertIconIntoComposer(icon);
    setQuickGridOpen(false);
});

attachmentButton.addEventListener('click', () => {
    markUserInteraction();
    if (!canChat || isSending || (!supportsFileUpload() && !supportsImageUpload())) {
        return;
    }
    setQuickGridOpen(false);
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
quickGridButton?.addEventListener('pointerdown', preserveComposerFocus);
quickGridButton?.addEventListener('mousedown', preserveComposerFocus);
quickGridButton?.addEventListener('touchstart', preserveComposerFocus, { passive: false });
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
        if (editTarget || bodyEl.value.trim() !== '') {
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
    openImageLightbox(
        trigger.getAttribute('data-image-src') || '',
        trigger.getAttribute('data-image-download') || 'chat-image',
        Number(trigger.getAttribute('data-image-message-id') || 0)
    );
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
backLink?.addEventListener('click', (event) => {
    event.preventDefault();
    navigateWithTransition(backLink.getAttribute('href') || './');
});
chatShellEl?.addEventListener('touchstart', handleSwipeBackTouchStart, { passive: true });
chatShellEl?.addEventListener('touchmove', handleSwipeBackTouchMove, { passive: false });
chatShellEl?.addEventListener('touchend', handleSwipeBackTouchEnd, { passive: true });
chatShellEl?.addEventListener('touchcancel', resetSwipeBackState, { passive: true });

scrollToEndButton?.addEventListener('click', () => {
    scrollMessagesToEnd('smooth');
    syncReadStateSoon();
});
replyPreviewCancelEl?.addEventListener('click', () => {
    clearReplyTarget();
    keepComposerFocused(true);
});
editPreviewCancelEl?.addEventListener('click', () => {
    clearEditTarget();
});

headerMenuButton?.addEventListener('click', (event) => {
    event.preventDefault();
    const isOpen = headerMenuButton.getAttribute('aria-expanded') === 'true';
    setHeaderMenuOpen(!isOpen);
});
pinnedMenuButton?.addEventListener('click', (event) => {
    event.preventDefault();
    setHeaderMenuOpen(false);
    setPinnedPanelOpen(!pinnedPanelOpen);
});
searchMenuButton?.addEventListener('click', (event) => {
    event.preventDefault();
    setHeaderMenuOpen(false);
    setSearchPanelOpen(!searchPanelOpen);
});
messageSearchSubmit?.addEventListener('click', () => {
    performMessageSearch();
});
messageSearchInput?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
        event.preventDefault();
        performMessageSearch();
    }
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

    const confirmed = window.confirm(`Remove ${conversationDisplayName} from your friends? Existing messages will stay, but both of you will not be able to send new messages.`);
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

addFriendButton?.addEventListener('click', async () => {
    markUserInteraction();
    setHeaderMenuOpen(false);
    if (isGroupConversation || isSending) {
        return;
    }

    if (friendshipState && ['pending', 'accepted'].includes(String(friendshipState.status || ''))) {
        return;
    }

    addFriendButton.disabled = true;

    try {
        const response = await fetch(conversationApiUrl(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken },
            body: new URLSearchParams({ action: 'send_friend_request', csrf_token: csrfToken }),
        });
        const payload = await response.json();

        if (!response.ok) {
            showError(payload.error || 'Could not send friend request right now.');
            return;
        }

        applyConversationPayload(payload.payload || payload);
        showHint(`Friend request sent to ${conversationDisplayName}.`);
    } catch (error) {
        showError('Could not send friend request right now. Please try again.');
    } finally {
        addFriendButton.disabled = false;
    }
});

blockUserButton?.addEventListener('click', async () => {
    markUserInteraction();
    setHeaderMenuOpen(false);
    if (isGroupConversation || isSending || Boolean(blockingState?.blocked_by_me)) {
        return;
    }

    const confirmed = window.confirm(`Block ${conversationDisplayName}? You won't be able to send new messages until you unblock.`);
    if (!confirmed) {
        return;
    }

    blockUserButton.disabled = true;
    try {
        const response = await fetch(conversationApiUrl(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken },
            body: new URLSearchParams({ action: 'block_user', csrf_token: csrfToken }),
        });
        const payload = await response.json();
        if (!response.ok || payload.error) {
            showError(payload.error || 'Could not block this user right now.');
            return;
        }
        applyConversationPayload(payload.payload || payload);
        showHint(`${conversationDisplayName} is blocked.`);
    } catch (error) {
        showError('Could not block this user right now. Please try again.');
    } finally {
        blockUserButton.disabled = false;
    }
});

unblockUserButton?.addEventListener('click', async () => {
    markUserInteraction();
    setHeaderMenuOpen(false);
    if (isGroupConversation || isSending || !Boolean(blockingState?.blocked_by_me)) {
        return;
    }

    unblockUserButton.disabled = true;
    try {
        const response = await fetch(conversationApiUrl(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken },
            body: new URLSearchParams({ action: 'unblock_user', csrf_token: csrfToken }),
        });
        const payload = await response.json();
        if (!response.ok || payload.error) {
            showError(payload.error || 'Could not unblock this user right now.');
            return;
        }
        applyConversationPayload(payload.payload || payload);
        showHint(`${conversationDisplayName} unblocked.`);
    } catch (error) {
        showError('Could not unblock this user right now. Please try again.');
    } finally {
        unblockUserButton.disabled = false;
    }
});

muteConversationButton?.addEventListener('click', () => {
    setHeaderMenuOpen(false);
    if (isGroupConversation || !muteStorageKey) {
        return;
    }
    const muted = !isConversationMuted();
    setConversationMuted(muted);
    updateMuteButtonLabel();
    showHint(muted ? 'Notifications muted for this conversation on this device.' : 'Notifications enabled for this conversation.');
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
reactionMembersClose?.addEventListener('click', () => hideReactionDetailsPanel());
reactionMembersModal?.addEventListener('click', (event) => {
    if (event.target === reactionMembersModal) {
        hideReactionDetailsPanel();
    }
});
messageDeliveryClose?.addEventListener('click', () => hideMessageDeliveryPanel());
messageDeliveryModal?.addEventListener('click', (event) => {
    if (event.target === messageDeliveryModal) {
        hideMessageDeliveryPanel();
    }
});

addGroupMemberButton?.addEventListener('click', async () => {
    markUserInteraction();
    setHeaderMenuOpen(false);
    memberPickerMode = 'group-invite';
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
    if (memberPickerMode === 'forward') {
        const target = event.target instanceof Element ? event.target.closest('[data-forward-user-id]') : null;
        if (!target) {
            return;
        }

        const userId = Number(target.getAttribute('data-forward-user-id') || 0);
        const candidate = directoryUsersState.find((entry) => Number(entry.id) === userId);
        if (!candidate || userId <= 0) {
            return;
        }

        target.setAttribute('disabled', 'disabled');
        await submitForwardToUser(candidate.id, candidate.username || '');
        target.removeAttribute('disabled');
        return;
    }

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

groupMembersListEl?.addEventListener('click', async (event) => {
    const target = event.target instanceof Element ? event.target.closest('[data-group-remove-user-id]') : null;
    if (!target) {
        return;
    }

    const userId = Number(target.getAttribute('data-group-remove-user-id') || 0);
    const member = Array.isArray(groupState?.members)
        ? groupState.members.find((entry) => Number(entry?.user_id || 0) === userId)
        : null;
    if (!member || userId <= 0) {
        return;
    }

    if (!window.confirm(`Remove ${member.username || 'this member'} from the group?`)) {
        return;
    }

    target.setAttribute('disabled', 'disabled');
    try {
        const response = await fetch(conversationApiUrl(), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json', 'X-CSRF-Token': csrfToken },
            body: new URLSearchParams({ action: 'remove_group_member', user_id: String(userId), csrf_token: csrfToken }),
        });
        const payload = await response.json();
        if (!response.ok || payload.error) {
            throw new Error(payload.error || 'Could not remove that member right now.');
        }

        applyConversationPayload(payload.payload || payload);
        showHint(`${member.username || 'Member'} was removed from the group.`);
    } catch (error) {
        showError(error.message || 'Could not remove that member right now.');
        target.removeAttribute('disabled');
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

        window.location.href = './';
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

editGroupAvatarButton?.addEventListener('click', () => {
    markUserInteraction();
    setHeaderMenuOpen(false);
    groupAvatarFileInput?.click();
});

groupAvatarFileInput?.addEventListener('change', async () => {
    const [file] = groupAvatarFileInput.files || [];
    if (!file) {
        return;
    }

    if (!String(file.type || '').startsWith('image/')) {
        showError('Please choose an image file.');
        groupAvatarFileInput.value = '';
        return;
    }

    if (Number(file.size || 0) > (8 * 1024 * 1024)) {
        showError('Group photo must be 8MB or smaller.');
        groupAvatarFileInput.value = '';
        return;
    }

    editGroupAvatarButton.disabled = true;
    showHint('Uploading group photo…');
    try {
        const formData = new FormData();
        formData.append('action', 'update_group_avatar');
        formData.append('csrf_token', csrfToken);
        formData.append('avatar_file', file, file.name || 'group-photo.jpg');
        const payload = await new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', conversationApiUrl(), true);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-CSRF-Token', csrfToken);
            xhr.upload.onprogress = (event) => {
                if (!event.lengthComputable) {
                    return;
                }
                const percent = Math.min(100, Math.max(0, Math.round((event.loaded / event.total) * 100)));
                showHint(`Uploading group photo… ${percent}%`);
            };
            xhr.onerror = () => reject(new Error('Could not update the group photo right now.'));
            xhr.onload = () => {
                try {
                    const json = JSON.parse(xhr.responseText || '{}');
                    if (xhr.status < 200 || xhr.status >= 300 || json.error) {
                        reject(new Error(json.error || 'Could not update the group photo right now.'));
                        return;
                    }
                    resolve(json);
                } catch (error) {
                    reject(new Error('Could not update the group photo right now.'));
                }
            };
            xhr.send(formData);
        });

        applyConversationPayload(payload.payload || payload);
        showHint('Group photo updated.');
    } catch (error) {
        showError(error.message || 'Could not update the group photo right now.');
    } finally {
        if (groupAvatarFileInput) {
            groupAvatarFileInput.value = '';
        }
        editGroupAvatarButton.disabled = false;
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

        window.location.href = './';
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

lightboxBack?.addEventListener('click', () => {
    closeImageLightbox();
});
lightboxMenuButton?.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    const isOpen = lightboxMenuButton.getAttribute('aria-expanded') === 'true';
    setLightboxMenuOpen(!isOpen);
});
lightboxSave?.addEventListener('click', () => {
    if (!lightboxDownloadHref || lightboxDownloadHref === '#') {
        return;
    }
    const link = document.createElement('a');
    link.href = lightboxDownloadHref;
    link.download = lightboxDownloadFilename || 'chat-image';
    document.body.appendChild(link);
    link.click();
    link.remove();
    setLightboxMenuOpen(false);
});
lightboxReact?.addEventListener('click', () => {
    if (!lightboxActiveMessageId) {
        return;
    }
    showReactionPicker(lightboxReact, lightboxActiveMessageId, '', true, {
        reply: false,
        copy: false,
        edit: false,
        delete: false,
        deliveryDetails: false,
        pin: false,
    });
});
lightboxReply?.addEventListener('click', () => {
    if (!lightboxActiveMessageId) {
        return;
    }
    const message = (window.__messagesState || []).find((item) => Number(item.id) === lightboxActiveMessageId);
    if (message) {
        setReplyTargetByMessage(message);
        closeImageLightbox();
        keepComposerFocused(true);
    }
});
lightboxForward?.addEventListener('click', () => {
    if (!lightboxActiveMessageId) {
        return;
    }
    const message = (window.__messagesState || []).find((item) => Number(item.id) === lightboxActiveMessageId);
    if (message) {
        openForwardPickerByMessage(message);
        closeImageLightbox();
    }
});

imageLightbox?.addEventListener('click', (event) => {
    if (event.target === imageLightbox) {
        closeImageLightbox();
    }
});
imageLightbox?.addEventListener('touchstart', (event) => {
    const touch = event.touches && event.touches[0];
    if (!touch) {
        return;
    }
    lightboxSwipeStartX = touch.clientX;
    lightboxSwipeStartY = touch.clientY;
    lightboxSwipeTracking = touch.clientX <= 40;
}, { passive: true });
imageLightbox?.addEventListener('touchmove', (event) => {
    if (!lightboxSwipeTracking || lightboxSwipeStartX === null || lightboxSwipeStartY === null) {
        return;
    }
    const touch = event.touches && event.touches[0];
    if (!touch) {
        return;
    }
    const deltaX = touch.clientX - lightboxSwipeStartX;
    const deltaY = Math.abs(touch.clientY - lightboxSwipeStartY);
    if (deltaX > 80 && deltaY < 60) {
        lightboxSwipeTracking = false;
        closeImageLightbox();
    }
}, { passive: true });
imageLightbox?.addEventListener('touchend', () => {
    lightboxSwipeStartX = null;
    lightboxSwipeStartY = null;
    lightboxSwipeTracking = false;
}, { passive: true });
window.addEventListener('popstate', () => {
    if (imageLightbox && !imageLightbox.hidden) {
        closeImageLightbox({ viaHistory: true });
        return;
    }
    if (lightboxHistoryEntryActive && (!window.history.state || !window.history.state.imageLightboxOpen)) {
        lightboxHistoryEntryActive = false;
    }
});

document.addEventListener('keydown', (event) => {
    markUserInteraction();
    if (event.key === 'Escape' && groupMembersModal && !groupMembersModal.hidden) {
        setGroupMembersOpen(false);
        return;
    }
    if (event.key === 'Escape' && reactionMembersModal && !reactionMembersModal.hidden) {
        hideReactionDetailsPanel();
        return;
    }
    if (event.key === 'Escape' && messageDeliveryModal && !messageDeliveryModal.hidden) {
        hideMessageDeliveryPanel();
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
    if (event.key === 'Escape' && searchPanelOpen) {
        setSearchPanelOpen(false);
        return;
    }
    if (event.key === 'Escape' && pinnedPanelOpen) {
        setPinnedPanelOpen(false);
        return;
    }
    if (event.key === 'Escape' && imageLightbox && !imageLightbox.hidden) {
        closeImageLightbox();
        return;
    }
    if (event.key === 'Escape' && messageActionMenuEl && !messageActionMenuEl.hidden) {
        hideMessageActionMenu();
    }
}, { passive: true });
document.addEventListener('click', (event) => {
    if (!lightboxMenu || !lightboxMenuButton || lightboxMenu.hidden) {
        return;
    }
    const target = event.target;
    if (target instanceof Node && (lightboxMenu.contains(target) || lightboxMenuButton.contains(target))) {
        return;
    }
    setLightboxMenuOpen(false);
});
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
document.addEventListener('click', (event) => {
    if (!pinnedPanelEl || !pinnedMenuButton || !pinnedPanelOpen) {
        return;
    }
    const target = event.target;
    if (target instanceof Node && (pinnedPanelEl.contains(target) || pinnedMenuButton.contains(target))) {
        return;
    }
    setPinnedPanelOpen(false);
});
document.addEventListener('click', (event) => {
    if (!searchPanelEl || !searchMenuButton || !searchPanelOpen) {
        return;
    }
    const target = event.target;
    if (target instanceof Node && (searchPanelEl.contains(target) || searchMenuButton.contains(target))) {
        return;
    }
    setSearchPanelOpen(false);
});
document.addEventListener('pointerdown', (event) => {
    if (!(event.target instanceof Node)) {
        return;
    }
    if (reactionPickerEl && !reactionPickerEl.hidden && !reactionPickerEl.contains(event.target)) {
        hideReactionPicker();
    }
    if (messageActionMenuEl && !messageActionMenuEl.hidden && !messageActionMenuEl.contains(event.target)) {
        hideMessageActionMenu();
    }
});
document.addEventListener('click', markUserInteraction, { passive: true });

document.addEventListener('click', (event) => {
    if (quickGridButton && quickGridButton.getAttribute('aria-expanded') === 'true') {
        const target = event.target;
        if (!(target instanceof Node) || (!quickGridPanel?.contains(target) && !quickGridButton.contains(target))) {
            setQuickGridOpen(false);
        }
    }

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
        setQuickGridOpen(false);
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

window.addEventListener('resize', () => {
    hideReactionPicker();
    hideMessageActionMenu();
    clearLongPressTimer();
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
updateMuteButtonLabel();
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
