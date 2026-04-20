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
        $authMode = 'login';
        if (isset($_GET['auth']) && in_array($_GET['auth'], ['register', 'forgot', 'reset'], true)) {
            $authMode = (string) $_GET['auth'];
        }
        $resetToken = isset($_GET['token']) && is_string($_GET['token']) ? trim($_GET['token']) : '';
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

            if ($action === 'request_password_reset' && $user === null) {
                $authMode = 'forgot';

                $rateLimitError = enforceAuthRateLimit('password_reset_request');
                if ($rateLimitError !== null) {
                    $errors[] = $rateLimitError;
                } else {
                    $challenge = ensureAuthChallenge();
                    $normalizedAnswer = trim((string) ($_POST['verification_answer'] ?? ''));
                    if ($normalizedAnswer === '' || !preg_match('/^-?\d+$/', $normalizedAnswer) || (int) $normalizedAnswer !== $challenge['answer']) {
                        recordAuthAttempt('password_reset_request', false);
                        refreshAuthChallenge();
                        $errors[] = 'Incorrect verification answer. Please solve the new math question.';
                    } else {
                        $username = (string) ($_POST['username'] ?? '');
                        $issued = issuePasswordResetToken($username);
                        recordAuthAttempt('password_reset_request', true);
                        refreshAuthChallenge();

                        if ($issued === null) {
                            $notice = 'If that account exists, a reset link can be used now.';
                        } else {
                            $token = (string) ($issued['token'] ?? '');
                            $notice = 'Reset link: ./?auth=reset&token=' . rawurlencode($token);
                        }
                    }
                }

                $authChallengePrompt = authChallengePrompt();
            }

            if ($action === 'confirm_password_reset' && $user === null) {
                $authMode = 'reset';

                $rateLimitError = enforceAuthRateLimit('password_reset_confirm');
                if ($rateLimitError !== null) {
                    $errors[] = $rateLimitError;
                } else {
                    $challenge = ensureAuthChallenge();
                    $normalizedAnswer = trim((string) ($_POST['verification_answer'] ?? ''));
                    if ($normalizedAnswer === '' || !preg_match('/^-?\d+$/', $normalizedAnswer) || (int) $normalizedAnswer !== $challenge['answer']) {
                        recordAuthAttempt('password_reset_confirm', false);
                        refreshAuthChallenge();
                        $errors[] = 'Incorrect verification answer. Please solve the new math question.';
                    } else {
                        $error = confirmPasswordReset(
                            (string) ($_POST['token'] ?? ''),
                            (string) ($_POST['password'] ?? ''),
                            (string) ($_POST['confirm_password'] ?? '')
                        );

                        if ($error !== null) {
                            recordAuthAttempt('password_reset_confirm', false);
                            refreshAuthChallenge();
                            $errors[] = $error;
                            $resetToken = (string) ($_POST['token'] ?? '');
                        } else {
                            recordAuthAttempt('password_reset_confirm', true);
                            refreshAuthChallenge();
                            $notice = 'Password reset successful. Please sign in.';
                            $authMode = 'login';
                            $resetToken = '';
                        }
                    }
                }

                $authChallengePrompt = authChallengePrompt();
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
                $submittedEmail = isset($_POST['email']) ? (string) $_POST['email'] : null;
                $previousEmail = isset($user['email']) && is_string($user['email']) ? strtolower(trim($user['email'])) : '';
                $error = updateUserProfile(
                    (int) $user['id'],
                    (string) ($_POST['username'] ?? ''),
                    isset($_POST['name']) ? (string) $_POST['name'] : null,
                    isset($_POST['family_name']) ? (string) $_POST['family_name'] : null,
                    $submittedEmail,
                    $_FILES['avatar_file'] ?? null
                );

                if ($error !== null) {
                    $_SESSION['flash_errors'] = [$error];
                } else {
                    $normalizedSubmittedEmail = $submittedEmail === null ? '' : strtolower(trim($submittedEmail));
                    if ($normalizedSubmittedEmail !== '' && $normalizedSubmittedEmail !== $previousEmail) {
                        issueEmailVerificationCode((int) $user['id'], $normalizedSubmittedEmail);
                        $_SESSION['flash_notice'] = 'Settings updated. A 6-digit verification code was sent to your email.';
                    } else {
                        $_SESSION['flash_notice'] = 'Settings updated.';
                    }
                }

                header('Location: ./');
                exit;
            }

            if ($action === 'confirm_email' && $user !== null) {
                $error = confirmEmailAddressVerification(
                    (int) $user['id'],
                    (string) ($_POST['verification_code'] ?? '')
                );

                if ($error !== null) {
                    $_SESSION['flash_errors'] = [$error];
                } else {
                    $_SESSION['flash_notice'] = 'Email verified.';
                }

                header('Location: ./');
                exit;
            }

            if ($action === 'change_password' && $user !== null) {
                $error = changeUserPassword(
                    (int) $user['id'],
                    (string) ($_POST['current_password'] ?? ''),
                    (string) ($_POST['password'] ?? ''),
                    (string) ($_POST['confirm_password'] ?? '')
                );

                if ($error !== null) {
                    $_SESSION['flash_errors'] = [$error];
                } else {
                    $_SESSION['flash_notice'] = 'Password changed.';
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
            'resetToken' => $resetToken,
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
