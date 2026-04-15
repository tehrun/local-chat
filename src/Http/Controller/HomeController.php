<?php

declare(strict_types=1);

namespace LocalChat\Http\Controller;

final class HomeController
{
    public function __invoke(): array
    {
        purgeExpiredMessages();

        $errors = [];
        $notice = null;
        if (isset($_SESSION['flash_errors']) && is_array($_SESSION['flash_errors'])) {
            $errors = array_values(array_filter($_SESSION['flash_errors'], static fn ($error): bool => is_string($error) && $error !== ''));
            unset($_SESSION['flash_errors']);
        }
        if (isset($_SESSION['flash_notice']) && is_string($_SESSION['flash_notice']) && $_SESSION['flash_notice'] !== '') {
            $notice = $_SESSION['flash_notice'];
            unset($_SESSION['flash_notice']);
        }

        $user = currentUser();
        $authMode = (isset($_GET['auth']) && $_GET['auth'] === 'register') ? 'register' : 'login';
        $authChallengePrompt = authChallengePrompt();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            requireCsrfToken();

            $action = $_POST['action'] ?? '';

            if ($action === 'register') {
                $authMode = 'register';
                $error = registerUser(
                    $_POST['username'] ?? '',
                    $_POST['password'] ?? '',
                    $_POST['confirm_password'] ?? '',
                    $_POST['verification_answer'] ?? ''
                );
                $authChallengePrompt = authChallengePrompt();
                if ($error !== null) {
                    $errors[] = $error;
                } else {
                    $notice = 'Registration successful. Please sign in.';
                    $authMode = 'login';
                }
            }

            if ($action === 'login') {
                $authMode = 'login';
                $error = loginUser($_POST['username'] ?? '', $_POST['password'] ?? '', $_POST['verification_answer'] ?? '');
                $authChallengePrompt = authChallengePrompt();
                if ($error !== null) {
                    $errors[] = $error;
                } else {
                    header('Location: ./');
                    exit;
                }
            }

            if ($action === 'logout') {
                $_SESSION = [];
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_regenerate_id(true);
                }
                session_destroy();
                header('Location: ./');
                exit;
            }

            if ($action === 'update_profile' && $user !== null) {
                $error = updateUserProfile(
                    (int) $user['id'],
                    (string) ($_POST['username'] ?? ''),
                    isset($_POST['name']) ? (string) $_POST['name'] : null,
                    isset($_POST['family_name']) ? (string) $_POST['family_name'] : null,
                    $_FILES['avatar_file'] ?? null,
                    (string) ($_POST['remove_avatar'] ?? '0') === '1'
                );

                if ($error !== null) {
                    $_SESSION['flash_errors'] = [$error];
                } else {
                    $_SESSION['flash_notice'] = 'Settings updated.';
                }

                header('Location: ./');
                exit;
            }
        }

        $user = currentUser();
        $users = $user ? allOtherUsers((int) $user['id']) : [];
        $chatUsers = $user ? combinedChatList((int) $user['id']) : [];
        $incomingRequests = $user ? incomingFriendRequests((int) $user['id']) : [];
        $loginRequired = isset($_GET['login']) && $_GET['login'] === 'required';

        return [
            'user' => $user,
            'users' => $users,
            'chatUsers' => $chatUsers,
            'incomingRequests' => $incomingRequests,
            'errors' => $errors,
            'notice' => $notice,
            'authMode' => $authMode,
            'authChallengePrompt' => $authChallengePrompt,
            'loginRequired' => $loginRequired,
            'bootstrapData' => [
                'currentUserId' => $user !== null ? (int) $user['id'] : null,
                'initialChatUsers' => $chatUsers,
                'initialDirectoryUsers' => $users,
                'initialIncomingRequests' => $incomingRequests,
                'webPushPublicKey' => $user !== null ? webPushPublicKey() : null,
                'initialHomeSignature' => $user !== null ? chatListStateSignature((int) $user['id']) : '',
                'preferPolling' => PHP_SAPI === 'cli-server',
            ],
        ];
    }
}
