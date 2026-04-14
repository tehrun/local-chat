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
    <script src="assets/js/chat-head.js"></script>
    <link rel="stylesheet" href="assets/css/chat.css">
</head>
<body class="route-chat">
<div class="app">
    <div class="chat-shell">
        <header class="topbar">
            <a class="back-link" href="./" aria-label="Back to chats">
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
                    <div class="topbar-peer-profile">
                        <div class="topbar-peer-avatar" aria-hidden="true">
                            <?php if (!empty($otherUser['avatar_path'])): ?>
                                <img src="avatar.php?user=<?= (int) $otherUser['id'] ?>" alt="">
                            <?php else: ?>
                                <?= e(strtoupper(substr((string) $otherUser['username'], 0, 2))) ?>
                            <?php endif; ?>
                        </div>
                        <div id="group-members-button" class="header-members-trigger hidden">
                            <h1 id="header-title"><?= e($otherUser['username']) ?></h1>
                            <div class="presence-row">
                                <span class="presence-light <?= !empty($otherUser['is_online']) ? 'online' : '' ?>" id="header-presence-light" aria-hidden="true"></span>
                                <span id="header-presence-label">Offline</span>
                            </div>
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
                        id="menu-search-button"
                        class="header-menu-item"
                        type="button"
                        role="menuitem"
                        aria-expanded="false"
                        aria-controls="search-panel"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <circle cx="11" cy="11" r="7"></circle>
                            <path d="m20 20-3.5-3.5"></path>
                        </svg>
                        <span>Search messages</span>
                    </button>
                    <button
                        id="menu-pinned-button"
                        class="header-menu-item hidden"
                        type="button"
                        role="menuitem"
                        aria-expanded="false"
                        aria-controls="pinned-panel"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M12 17v5"></path>
                            <path d="m15 3 2 2-3 6v3H10v-3L7 5l2-2z"></path>
                        </svg>
                        <span>Pinned messages</span>
                    </button>
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
                        id="edit-group-avatar-button"
                        class="header-menu-item<?= $isGroupConversation && (int) $group['creator_user_id'] === (int) $user['id'] ? '' : ' hidden' ?>"
                        type="button"
                        role="menuitem"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M4 7.5A2.5 2.5 0 0 1 6.5 5h11A2.5 2.5 0 0 1 20 7.5v9A2.5 2.5 0 0 1 17.5 19h-11A2.5 2.5 0 0 1 4 16.5v-9Z"></path>
                            <circle cx="9" cy="9" r="1.25"></circle>
                            <path d="m8 15 2.5-2.5L13 15l2.5-3 2.5 3"></path>
                        </svg>
                        <span>Edit group photo</span>
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
                        <span>Remove friend</span>
                    </button>
                    <button
                        id="add-friend-button"
                        class="header-menu-item<?= !$isGroupConversation && ($friendship === null || $friendship['status'] !== 'accepted') ? '' : ' hidden' ?>"
                        type="button"
                        role="menuitem"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                            <path d="M19 8v6"></path>
                            <path d="M22 11h-6"></path>
                        </svg>
                        <span>Add friend</span>
                    </button>
                    <button
                        id="block-user-button"
                        class="header-menu-item danger<?= !$isGroupConversation && (!$blockingState || empty($blockingState['blocked_by_me'])) ? '' : ' hidden' ?>"
                        type="button"
                        role="menuitem"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <circle cx="12" cy="12" r="9"></circle>
                            <path d="m6 6 12 12"></path>
                        </svg>
                        <span>Block user</span>
                    </button>
                    <button
                        id="unblock-user-button"
                        class="header-menu-item<?= !$isGroupConversation && $blockingState && !empty($blockingState['blocked_by_me']) ? '' : ' hidden' ?>"
                        type="button"
                        role="menuitem"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <circle cx="12" cy="12" r="9"></circle>
                            <path d="M8 12h8"></path>
                        </svg>
                        <span>Unblock user</span>
                    </button>
                    <button
                        id="mute-conversation-button"
                        class="header-menu-item<?= $isGroupConversation ? ' hidden' : '' ?>"
                        type="button"
                        role="menuitem"
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M11 5 6 9H3v6h3l5 4z"></path>
                            <path id="mute-icon-sound-wave" class="mute-icon-segment" d="M16 9a5 5 0 0 1 0 6"></path>
                            <path id="mute-icon-sound-wave-outer" class="mute-icon-segment" d="M18.8 6.5a8.5 8.5 0 0 1 0 11"></path>
                            <path id="mute-icon-slash" class="mute-icon-segment is-hidden" d="m21 3-9 9"></path>
                        </svg>
                        <span>Mute notifications</span>
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
        <section id="pinned-panel" class="pinned-panel" hidden aria-label="Pinned messages">
            <div class="pinned-panel-header">
                <div class="pinned-panel-title">Pinned messages</div>
                <span id="pinned-panel-count" class="pinned-panel-count">0</span>
            </div>
            <div id="pinned-panel-list" class="pinned-panel-list"></div>
        </section>
        <section id="search-panel" class="search-panel" hidden aria-label="Search messages">
            <div class="search-input-row">
                <input id="message-search-input" type="search" placeholder="Search this conversation" maxlength="80" autocomplete="off" aria-label="Search messages in this conversation">
                <button id="message-search-submit" type="button">Search</button>
            </div>
            <div class="search-results" id="search-results" aria-live="polite"></div>
        </section>

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
                    <button id="lightbox-back" class="lightbox-button lightbox-button--close" type="button" aria-label="Back from full screen image">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M15 6l-6 6 6 6"></path>
                        </svg>
                    </button>
                    <div class="lightbox-button--menu">
                        <button id="lightbox-menu-button" class="lightbox-button" type="button" aria-label="Image actions" aria-haspopup="menu" aria-expanded="false" aria-controls="lightbox-menu">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <circle cx="12" cy="5" r="1.8"></circle>
                                <circle cx="12" cy="12" r="1.8"></circle>
                                <circle cx="12" cy="19" r="1.8"></circle>
                            </svg>
                        </button>
                        <div id="lightbox-menu" class="lightbox-menu" role="menu" hidden>
                            <button id="lightbox-save" class="lightbox-menu-item" type="button" role="menuitem">
                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                    <path d="M12 3v12"></path>
                                    <path d="m7 10 5 5 5-5"></path>
                                    <path d="M5 21h14"></path>
                                </svg>
                                <span>Save</span>
                            </button>
                        </div>
                    </div>
                </div>
                <figure class="lightbox-figure">
                    <img id="lightbox-image" class="lightbox-image" src="" alt="Full screen shared image">
                </figure>
                <div class="lightbox-footer">
                    <button id="lightbox-react" class="lightbox-button" type="button" aria-label="React to image">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.6-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8.3 8.5Z"></path>
                        </svg>
                    </button>
                    <button id="lightbox-reply" class="lightbox-button" type="button" aria-label="Reply to image">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="m9 14-5-5 5-5"></path>
                            <path d="M4 9h9a7 7 0 0 1 7 7v1"></path>
                        </svg>
                    </button>
                    <button id="lightbox-forward" class="lightbox-button" type="button" aria-label="Forward image">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="m10 14-5-5 5-5"></path>
                            <path d="M5 9h8a6 6 0 0 1 6 6v1"></path>
                            <path d="m14 14-5-5 5-5"></path>
                            <path d="M9 9h4"></path>
                        </svg>
                    </button>
                </div>
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

        <div id="reaction-members-modal" class="member-picker" aria-hidden="true" hidden>
            <div class="member-picker-panel" role="dialog" aria-modal="true" aria-labelledby="reaction-members-title">
                <div class="member-picker-header">
                    <h2 id="reaction-members-title">Reactions</h2>
                    <button class="member-picker-close" id="reaction-members-close" type="button" aria-label="Close reactions">×</button>
                </div>
                <div class="member-picker-list" id="reaction-members-list"></div>
                <p class="member-picker-empty" id="reaction-members-empty" hidden>No reactions yet.</p>
            </div>
        </div>

        <div id="message-delivery-modal" class="member-picker" aria-hidden="true" hidden>
            <div class="member-picker-panel" role="dialog" aria-modal="true" aria-labelledby="message-delivery-title">
                <div class="member-picker-header">
                    <h2 id="message-delivery-title">Message delivery</h2>
                    <button class="member-picker-close" id="message-delivery-close" type="button" aria-label="Close message delivery details">×</button>
                </div>
                <div class="member-picker-list" id="message-delivery-list"></div>
                <p class="member-picker-empty" id="message-delivery-empty" hidden>No delivery details yet.</p>
            </div>
        </div>

        <div class="composer-wrap">
            <div class="status-row" id="status-row"></div>
            <div class="composer-stack">
                <div class="conversation-actions" aria-hidden="false">
                    <button id="scroll-to-end-button" class="scroll-to-end-button" type="button" aria-label="Scroll to latest message" title="Scroll to latest message">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="m7 10 5 5 5-5"></path>
                        </svg>
                    </button>
                </div>
                <div id="reply-preview" class="reply-preview" hidden>
                    <div class="reply-preview-copy">
                        <strong id="reply-preview-author">Replying</strong>
                        <span id="reply-preview-text"></span>
                    </div>
                    <button id="reply-preview-cancel" class="reply-preview-cancel" type="button" aria-label="Cancel reply">×</button>
                </div>
                <div id="edit-preview" class="reply-preview edit-preview" hidden>
                    <div class="reply-preview-copy">
                        <strong id="edit-preview-author">Editing message</strong>
                        <span id="edit-preview-text"></span>
                    </div>
                    <button id="edit-preview-cancel" class="reply-preview-cancel" type="button" aria-label="Cancel edit">×</button>
                </div>
                <div class="composer">
                    <div class="quick-grid-wrap">
                        <button id="quick-grid-button" class="composer-icon-button attachment-trigger" type="button" aria-label="Open quick icon grid" aria-expanded="false" aria-controls="quick-grid-panel"<?= $canChat ? '' : ' disabled' ?>>
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <rect x="4" y="4" width="6" height="6" rx="1"></rect>
                                <rect x="14" y="4" width="6" height="6" rx="1"></rect>
                                <rect x="4" y="14" width="6" height="6" rx="1"></rect>
                                <rect x="14" y="14" width="6" height="6" rx="1"></rect>
                            </svg>
                        </button>
                        <div id="quick-grid-panel" class="quick-grid-panel" hidden>
                            <section class="quick-grid-category" aria-label="Smileys and people">
                                <p class="quick-grid-category-title">Smileys &amp; People</p>
                                <div class="quick-grid-category-options">
                                    <button class="quick-grid-option" type="button" data-grid-icon="😀" aria-label="Insert grinning face">😀</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="😄" aria-label="Insert smiling face with open mouth">😄</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="😎" aria-label="Insert cool face">😎</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🥳" aria-label="Insert party face">🥳</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🤔" aria-label="Insert thinking face">🤔</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🙏" aria-label="Insert folded hands">🙏</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="👏" aria-label="Insert clapping hands">👏</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🙌" aria-label="Insert raised hands">🙌</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🤝" aria-label="Insert handshake">🤝</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="💬" aria-label="Insert speech balloon">💬</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🙂" aria-label="Insert slightly smiling face">🙂</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="😉" aria-label="Insert winking face">😉</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="😍" aria-label="Insert smiling face with heart eyes">😍</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🥰" aria-label="Insert smiling face with hearts">🥰</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🤗" aria-label="Insert hugging face">🤗</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="😇" aria-label="Insert smiling face with halo">😇</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🤩" aria-label="Insert star struck face">🤩</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🥲" aria-label="Insert smiling face with tear">🥲</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🫶" aria-label="Insert heart hands">🫶</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🤟" aria-label="Insert love you hand sign">🤟</button>
                                </div>
                            </section>
                            <section class="quick-grid-category" aria-label="Hearts and symbols">
                                <p class="quick-grid-category-title">Hearts &amp; Symbols</p>
                                <div class="quick-grid-category-options">
                                    <button class="quick-grid-option" type="button" data-grid-icon="❤️" aria-label="Insert red heart">❤️</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="💙" aria-label="Insert blue heart">💙</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="💚" aria-label="Insert green heart">💚</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🔥" aria-label="Insert fire">🔥</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="✨" aria-label="Insert sparkle">✨</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="⭐" aria-label="Insert star">⭐</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="✅" aria-label="Insert check mark">✅</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="❗" aria-label="Insert exclamation mark">❗</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="📌" aria-label="Insert pin">📌</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="💡" aria-label="Insert light bulb">💡</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="💜" aria-label="Insert purple heart">💜</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🖤" aria-label="Insert black heart">🖤</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🤍" aria-label="Insert white heart">🤍</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="💯" aria-label="Insert hundred points">💯</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🔔" aria-label="Insert bell">🔔</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="⚠️" aria-label="Insert warning">⚠️</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🔒" aria-label="Insert lock">🔒</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🔑" aria-label="Insert key">🔑</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="📣" aria-label="Insert megaphone">📣</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="📍" aria-label="Insert round pushpin">📍</button>
                                </div>
                            </section>
                            <section class="quick-grid-category" aria-label="Nature and weather">
                                <p class="quick-grid-category-title">Nature &amp; Weather</p>
                                <div class="quick-grid-category-options">
                                    <button class="quick-grid-option" type="button" data-grid-icon="🌞" aria-label="Insert sun with face">🌞</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🌙" aria-label="Insert moon">🌙</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🌈" aria-label="Insert rainbow">🌈</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="☁️" aria-label="Insert cloud">☁️</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="⚡" aria-label="Insert lightning bolt">⚡</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🌸" aria-label="Insert cherry blossom">🌸</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🌴" aria-label="Insert palm tree">🌴</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🌊" aria-label="Insert wave">🌊</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🍀" aria-label="Insert clover">🍀</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🪴" aria-label="Insert potted plant">🪴</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🌤️" aria-label="Insert sun behind small cloud">🌤️</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🌧️" aria-label="Insert cloud with rain">🌧️</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="⛄" aria-label="Insert snowman">⛄</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🍁" aria-label="Insert maple leaf">🍁</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🌻" aria-label="Insert sunflower">🌻</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🌼" aria-label="Insert blossom">🌼</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🦋" aria-label="Insert butterfly">🦋</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🐝" aria-label="Insert honeybee">🐝</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🐬" aria-label="Insert dolphin">🐬</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🪐" aria-label="Insert ringed planet">🪐</button>
                                </div>
                            </section>
                            <section class="quick-grid-category" aria-label="Food and drinks">
                                <p class="quick-grid-category-title">Food &amp; Drinks</p>
                                <div class="quick-grid-category-options">
                                    <button class="quick-grid-option" type="button" data-grid-icon="🍕" aria-label="Insert pizza">🍕</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🍔" aria-label="Insert burger">🍔</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🍟" aria-label="Insert fries">🍟</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🌮" aria-label="Insert taco">🌮</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🍣" aria-label="Insert sushi">🍣</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="☕" aria-label="Insert coffee cup">☕</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🍵" aria-label="Insert tea cup">🍵</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🥤" aria-label="Insert soft drink">🥤</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🍩" aria-label="Insert doughnut">🍩</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🍰" aria-label="Insert cake">🍰</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🍎" aria-label="Insert red apple">🍎</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🍇" aria-label="Insert grapes">🍇</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🥑" aria-label="Insert avocado">🥑</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🍳" aria-label="Insert cooking">🍳</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🍜" aria-label="Insert steaming bowl">🍜</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🍪" aria-label="Insert cookie">🍪</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🍫" aria-label="Insert chocolate bar">🍫</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🍿" aria-label="Insert popcorn">🍿</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🧃" aria-label="Insert beverage box">🧃</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🥂" aria-label="Insert clinking glasses">🥂</button>
                                </div>
                            </section>
                            <section class="quick-grid-category" aria-label="Activities and places">
                                <p class="quick-grid-category-title">Activities &amp; Places</p>
                                <div class="quick-grid-category-options">
                                    <button class="quick-grid-option" type="button" data-grid-icon="🎉" aria-label="Insert confetti">🎉</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🎵" aria-label="Insert music note">🎵</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🎮" aria-label="Insert gamepad">🎮</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="⚽" aria-label="Insert soccer ball">⚽</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🏀" aria-label="Insert basketball">🏀</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="📷" aria-label="Insert camera">📷</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="✈️" aria-label="Insert airplane">✈️</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🚀" aria-label="Insert rocket">🚀</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🏖️" aria-label="Insert beach with umbrella">🏖️</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🏡" aria-label="Insert house with garden">🏡</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🎬" aria-label="Insert clapper board">🎬</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🎨" aria-label="Insert artist palette">🎨</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🎯" aria-label="Insert direct hit">🎯</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🎲" aria-label="Insert game die">🎲</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🏆" aria-label="Insert trophy">🏆</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🚴" aria-label="Insert cyclist">🚴</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🚗" aria-label="Insert car">🚗</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🚆" aria-label="Insert train">🚆</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🗺️" aria-label="Insert world map">🗺️</button>
                                    <button class="quick-grid-option" type="button" data-grid-icon="🗽" aria-label="Insert statue of liberty">🗽</button>
                                </div>
                            </section>
                        </div>
                    </div>
                    <textarea id="message-body" rows="1" placeholder="Message"<?= $canChat ? '' : ' disabled' ?>></textarea>
                    <input id="file-input" type="file" style="display:none">
                    <input id="image-file-input" type="file" accept="image/*" style="display:none">
                    <input id="group-avatar-file-input" type="file" accept="image/*" style="display:none">
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
<script type="application/json" id="chat-bootstrap-data"><?= jsonScriptValue($bootstrapData) ?></script>
<script src="assets/js/chat.js"></script>
</body>
</html>
