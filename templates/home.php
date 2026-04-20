<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#075e54">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Local Chat">
    <link rel="manifest" href="manifest.json">
    <link rel="icon" href="icons/icon.svg" type="image/svg+xml">
    <meta name="csrf-token" content="<?= e(csrfToken()) ?>">
    <title>Local Chat</title>
    <script src="assets/js/home-head.js"></script>
    <link rel="stylesheet" href="assets/css/home.css">
</head>
<body class="route-home">
<div class="app">
    <div class="shell">
        <header class="topbar">
            <div>
                <h1>Local Chat</h1>
                <p>Simple private conversations on your local network.</p>
            </div>
            <div class="topbar-actions">
                <?php if ($user !== null): ?>
                    <button class="install-button" id="install-app-button" type="button" hidden>Install app</button>
                    <div class="header-menu">
                        <button
                            id="settings-menu-button"
                            class="header-icon-button"
                            type="button"
                            aria-label="Settings"
                            aria-expanded="false"
                            aria-controls="settings-menu-panel"
                            title="Settings"
                        >
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <circle cx="12" cy="12" r="3"></circle>
                                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 8.92 4.6H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9c.36.46.86.73 1.44.77H21a2 2 0 1 1 0 4h-.09c-.58.04-1.08.31-1.51.77Z"></path>
                            </svg>
                        </button>
                        <div id="settings-menu-panel" class="header-menu-panel" role="menu" aria-label="Settings" hidden>
                            <button class="header-menu-item" id="open-profile-modal-button" type="button" role="menuitem">
                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="12" cy="7" r="4"></circle>
                                </svg>
                                <span class="header-menu-copy">
                                    <strong class="profile-name-inline">
                                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                            <path d="M12 20h9"></path>
                                            <path d="m16.5 3.5 4 4L7 21l-4 1 1-4 12.5-14.5Z"></path>
                                        </svg>
                                        <?= e(trim(($user['name'] ?? '') . ' ' . ($user['family_name'] ?? '')) !== '' ? trim(($user['name'] ?? '') . ' ' . ($user['family_name'] ?? '')) : $user['username']) ?>
                                    </strong>
                                    <span>Your account</span>
                                </span>
                            </button>
                            <button class="header-menu-item" id="open-change-password-modal-button" type="button" role="menuitem">
                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                    <rect x="3" y="11" width="18" height="10" rx="2"></rect>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                </svg>
                                <span class="header-menu-copy">
                                    <strong>Change password</strong>
                                    <span>Update your account password</span>
                                </span>
                            </button>
                            <div class="header-menu-label" role="menuitem">
                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                    <path d="M21 12.79A9 9 0 1 1 11.21 3c0 .28-.02.57-.02.86A7 7 0 0 0 20.14 12c.29 0 .58-.02.86-.02Z"></path>
                                </svg>
                                <span class="header-menu-copy">
                                    <strong>Dark mode</strong>
                                </span>
                                <input id="theme-toggle" class="theme-switch" type="checkbox" aria-label="Toggle dark mode">
                            </div>
                            <a class="header-menu-item" href="surf.php" role="menuitem">
                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <path d="M2 12h20"></path>
                                    <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10Z"></path>
                                </svg>
                                <span class="header-menu-copy">
                                    <strong>Surf mode</strong>
                                    <span>Open browser automation</span>
                                </span>
                            </a>
                            <form method="post" class="header-menu-form">
                                <input type="hidden" name="action" value="logout">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <button class="header-menu-item danger" type="submit" role="menuitem">
                                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                                        <path d="m16 17 5-5-5-5"></path>
                                        <path d="M21 12H9"></path>
                                    </svg>
                                    <span class="header-menu-copy">
                                        <strong>Log out</strong>
                                    </span>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </header>

        <main class="content">
            <?php if ($loginRequired): ?>
                <div class="alert error">Please sign in to open a conversation.</div>
            <?php endif; ?>

            <?php foreach ($errors as $error): ?>
                <div class="alert error"><?= e($error) ?></div>
            <?php endforeach; ?>

            <?php if ($notice !== null): ?>
                <div class="alert notice"><?= e($notice) ?></div>
            <?php endif; ?>

            <div class="card intro-bubble" id="welcome-message" data-dismiss-key="localchat:welcome-dismissed">
                <div class="intro-bubble-copy">
                    <h2 class="panel-title">Welcome</h2>
                    <p class="panel-text">Send text messages and voice notes, see who is online, and keep chat history for 7 days while uploaded photos, files, and voice notes expire after 24 hours.</p>
                </div>
                <button class="intro-dismiss" id="welcome-dismiss-button" type="button" aria-label="Dismiss welcome message">&times;</button>
            </div>

            <?php if ($user === null): ?>
                <section class="auth-grid">
                    <div class="card">
                        <?php if ($authMode === 'register'): ?>
                            <h2 class="panel-title">Create account</h2>
                            <form method="post">
                                <input type="hidden" name="action" value="register">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <label>
                                    Username
                                    <input type="text" name="username" minlength="3" required>
                                </label>
                                <label>
                                    Password
                                    <input type="password" name="password" minlength="6" required>
                                </label>
                                <label>
                                    Confirm password
                                    <input type="password" name="confirm_password" minlength="6" required>
                                </label>
                                <label>
                                    Verification: solve <?= e($authChallengePrompt) ?>
                                    <input type="text" name="verification_answer" inputmode="numeric" pattern="-?[0-9]+" autocomplete="off" required>
                                </label>
                                <button class="primary" type="submit">Register</button>
                            </form>
                            <p class="auth-switch">Already have an account? <a href="./">Sign in</a></p>
                        <?php elseif ($authMode === 'forgot'): ?>
                            <h2 class="panel-title">Reset password</h2>
                            <form method="post">
                                <input type="hidden" name="action" value="request_password_reset">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <label>
                                    Username
                                    <input type="text" name="username" required>
                                </label>
                                <label>
                                    Verification: solve <?= e($authChallengePrompt) ?>
                                    <input type="text" name="verification_answer" inputmode="numeric" pattern="-?[0-9]+" autocomplete="off" required>
                                </label>
                                <button class="secondary" type="submit">Request reset link</button>
                            </form>
                            <p class="auth-switch">Remembered it? <a href="./">Sign in</a></p>
                        <?php elseif ($authMode === 'reset'): ?>
                            <h2 class="panel-title">Choose new password</h2>
                            <form method="post">
                                <input type="hidden" name="action" value="confirm_password_reset">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="token" value="<?= e((string) ($resetToken ?? '')) ?>">
                                <label>
                                    Reset token
                                    <input type="text" name="token_visible" value="<?= e((string) ($resetToken ?? '')) ?>" disabled>
                                </label>
                                <label>
                                    New password
                                    <input type="password" name="password" minlength="12" required>
                                </label>
                                <label>
                                    Confirm new password
                                    <input type="password" name="confirm_password" minlength="12" required>
                                </label>
                                <label>
                                    Verification: solve <?= e($authChallengePrompt) ?>
                                    <input type="text" name="verification_answer" inputmode="numeric" pattern="-?[0-9]+" autocomplete="off" required>
                                </label>
                                <button class="secondary" type="submit">Reset password</button>
                            </form>
                            <p class="auth-switch"><a href="./?auth=forgot">Need a new reset link?</a></p>
                        <?php else: ?>
                            <h2 class="panel-title">Sign in</h2>
                            <form method="post" id="login-form">
                                <input type="hidden" name="action" value="login">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <label>
                                    Username
                                    <input type="text" name="username" required data-login-field="username">
                                </label>
                                <label>
                                    Password
                                    <input type="password" name="password" required data-login-field="password">
                                </label>
                                <label>
                                    Verification: solve <?= e($authChallengePrompt) ?>
                                    <input type="text" name="verification_answer" inputmode="numeric" pattern="-?[0-9]+" autocomplete="off" required>
                                </label>
                                <button class="secondary" id="login-submit" type="submit">Login</button>
                            </form>
                            <p class="auth-switch"><a href="./?auth=forgot">Forgot password?</a></p>
                            <p class="auth-switch">Don&apos;t have an account? <a href="./?auth=register">Create one</a></p>
                        <?php endif; ?>
                    </div>
                </section>
            <?php else: ?>
                <section class="request-list" id="friend-request-list">
                    <?php if ($incomingRequests !== []): ?>
                        <?php foreach ($incomingRequests as $request): ?>
                            <div class="request-card" data-request-user-id="<?= (int) $request['sender_id'] ?>">
                                <div class="avatar">
                                    <?php if (!empty($request['sender_avatar_path'])): ?>
                                        <img src="avatar.php?user=<?= (int) $request['sender_id'] ?>" alt="">
                                    <?php else: ?>
                                        <?= e(strtoupper(substr((string) $request['sender_name'], 0, 2))) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="request-meta">
                                    <strong><?= e($request['sender_name']) ?></strong>
                                    <span class="presence-badge">
                                        <span class="dot <?= !empty($request['is_online']) ? 'online' : '' ?>" aria-hidden="true"></span>
                                        <span><?= e($request['presence_label'] ?? 'Offline') ?></span>
                                    </span>
                                    <p class="request-copy"><?= e($request['sender_name']) ?> wants to add you as a friend.</p>
                                </div>
                                <div class="request-actions" aria-label="Friend request actions">
                                    <button class="mini-button primary icon-button" type="button" data-request-action="accept_friend_request" data-user-id="<?= (int) $request['sender_id'] ?>" aria-label="Accept friend request" title="Accept friend request">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                            <path d="M20 6 9 17l-5-5"></path>
                                        </svg>
                                    </button>
                                    <button class="mini-button danger icon-button" type="button" data-request-action="reject_friend_request" data-user-id="<?= (int) $request['sender_id'] ?>" aria-label="Reject friend request" title="Reject friend request">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                                            <path d="M18 6 6 18"></path>
                                            <path d="m6 6 12 12"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>

                <section class="chat-list" id="chat-list">
                    <?php if ($chatUsers === []): ?>
                        <div class="card" id="chat-list-empty">
                            <h2 class="panel-title">No chats yet</h2>
                            <p class="panel-text">Start a conversation from the new chat button to see it here.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($chatUsers as $chatUser): ?>
                            <?php $unseenCount = (int) ($chatUser['unseen_count'] ?? 0); ?>
                            <a class="chat-item" data-chat-user-id="<?= (int) $chatUser['id'] ?>" href="<?= e((string) ($chatUser['url'] ?? ('chat.php?user=' . (int) $chatUser['id']))) ?>">
                                <div class="avatar">
                                    <?php if (!empty($chatUser['avatar_path'])): ?>
                                        <img src="<?= !empty($chatUser['is_group']) ? 'avatar.php?group=' . (int) $chatUser['group_id'] : 'avatar.php?user=' . (int) $chatUser['id'] ?>" alt="">
                                    <?php else: ?>
                                        <?= e(strtoupper(substr((string) ($chatUser['name'] ?? $chatUser['username']), 0, 2))) ?>
                                    <?php endif; ?>
                                </div>
                                <div class="chat-copy">
                                    <div class="chat-copy-head">
                                        <span class="chat-name-row">
                                            <strong class="chat-name"><?= e((string) ($chatUser['name'] ?? $chatUser['username'])) ?></strong>
                                            <?php if (!empty($chatUser['is_group'])): ?>
                                                <span class="chat-type-chip">Group</span>
                                            <?php elseif (!empty($chatUser['blocked_by_me']) || !empty($chatUser['blocked_me'])): ?>
                                                <span class="chat-type-chip blocked">Blocked</span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="chat-last-time<?= ($chatUser['chat_list_time'] ?? '') !== '' ? '' : ' is-empty' ?>" data-role="chat-time"><?= e($chatUser['chat_list_time'] ?? '') ?></span>
                                    </div>
                                    <div class="chat-preview-row">
                                        <span class="chat-preview" data-role="chat-preview"><?= e($chatUser['chat_list_preview'] ?? 'Start chatting') ?></span>
                                        <span class="chat-time<?= $unseenCount > 0 ? '' : ' is-empty' ?>" data-role="unseen-count"<?= $unseenCount > 0 ? '' : ' aria-hidden="true"' ?>><?= $unseenCount > 0 ? $unseenCount : '' ?></span>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <div class="helper-row">
                <span class="dot"></span>
                <span>Now installable on supported devices and browsers.</span>
            </div>
        </main>
    </div>
</div>

<?php if ($user !== null): ?>
    <button class="floating-chat-launcher" id="chat-switcher-toggle" type="button" aria-label="Start a new chat">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M12 20.25c4.97 0 9-3.53 9-7.88s-4.03-7.87-9-7.87-9 3.52-9 7.87c0 2.2 1.03 4.18 2.68 5.61L4.5 21l4.1-1.78a10.3 10.3 0 0 0 3.4.53Z"></path>
            <path d="M12 9v6"></path>
            <path d="M9 12h6"></path>
        </svg>
    </button>
    <div class="chat-switcher" id="chat-switcher" hidden>
        <div class="chat-switcher-panel" role="dialog" aria-modal="true" aria-labelledby="chat-switcher-title">
            <div class="chat-switcher-header">
                <h2 id="chat-switcher-title">Search users</h2>
                <button class="chat-switcher-close" id="chat-switcher-close" type="button" aria-label="Close user list">×</button>
            </div>
            <div class="chat-switcher-actions">
                <button class="mini-button secondary" id="create-group-button" type="button">Create group</button>
            </div>
            <div class="chat-switcher-search">
                <input id="chat-switcher-search-input" type="search" placeholder="Search users by name" autocomplete="off" aria-label="Search users by name">
            </div>
            <div class="chat-switcher-list" id="chat-switcher-list"></div>
            <p class="chat-switcher-empty" id="chat-switcher-empty" hidden>No users match your search.</p>
        </div>
    </div>
    <div class="profile-modal" id="profile-modal" hidden>
        <div class="profile-modal-card" role="dialog" aria-modal="true" aria-labelledby="profile-modal-title">
            <div class="profile-modal-header">
                <h3 id="profile-modal-title">Edit profile</h3>
                <button class="chat-switcher-close" id="profile-modal-close" type="button" aria-label="Close profile form">×</button>
            </div>
            <form method="post" class="profile-modal-form" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update_profile">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <div class="profile-avatar-upload">
                    <div class="profile-avatar-preview" id="profile-avatar-preview" aria-hidden="true">
                        <?php if (!empty($user['avatar_path'])): ?>
                            <img src="avatar.php?user=<?= (int) $user['id'] ?>" alt="">
                        <?php else: ?>
                            <?= e(strtoupper(substr((string) $user['username'], 0, 2))) ?>
                        <?php endif; ?>
                    </div>
                    <div class="profile-avatar-copy">
                        <div>
                            <button class="mini-button secondary" id="profile-avatar-choose" type="button">Choose photo</button>
                            <input id="profile-avatar-file-input" type="file" name="avatar_file" accept="image/*" hidden>
                        </div>
                        <p class="profile-avatar-file" id="profile-avatar-file-name">JPG, PNG, GIF, WEBP, HEIC/HEIF • up to 8MB.</p>
                    </div>
                </div>
                <label>
                    Username
                    <input type="text" name="username" minlength="3" required value="<?= e((string) $user['username']) ?>">
                </label>
                <label>
                    Name (optional)
                    <input type="text" name="name" maxlength="100" value="<?= e((string) ($user['name'] ?? '')) ?>">
                </label>
                <label>
                    Family name (optional)
                    <input type="text" name="family_name" maxlength="100" value="<?= e((string) ($user['family_name'] ?? '')) ?>">
                </label>
                <div class="profile-modal-form-actions">
                    <button class="mini-button secondary" id="profile-modal-cancel" type="button">Cancel</button>
                    <button class="mini-button primary" id="profile-modal-save" type="submit">Save</button>
                </div>
            </form>
        </div>
    </div>
    <div class="profile-modal" id="change-password-modal" hidden>
        <div class="profile-modal-card" role="dialog" aria-modal="true" aria-labelledby="change-password-title">
            <div class="profile-modal-header">
                <h3 id="change-password-title">Change password</h3>
                <button class="chat-switcher-close" id="change-password-close" type="button" aria-label="Close password form">×</button>
            </div>
            <form method="post" class="profile-modal-form">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <label>
                    Current password
                    <input type="password" name="current_password" required>
                </label>
                <label>
                    New password
                    <input type="password" name="password" minlength="12" required>
                </label>
                <label>
                    Confirm new password
                    <input type="password" name="confirm_password" minlength="12" required>
                </label>
                <div class="profile-modal-form-actions">
                    <button class="mini-button secondary" id="change-password-cancel" type="button">Cancel</button>
                    <button class="mini-button primary" type="submit">Save</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>
<script type="application/json" id="home-bootstrap-data"><?= jsonScriptValue($bootstrapData) ?></script>
<script src="assets/js/home.js"></script>
</body>
</html>
