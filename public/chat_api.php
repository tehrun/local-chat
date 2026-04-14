<?php

declare(strict_types=1);

use LocalChat\Http\Api\ChatApiDispatcher;

require __DIR__ . '/../src/bootstrap.php';

$user = requireAuth();
$groupId = requirePositiveInt($_POST + $_GET, 'group');
$isGroupConversation = $groupId > 0;

$context = [
    'user' => $user,
    'group_id' => $groupId,
    'other_user_id' => $isGroupConversation ? 0 : requirePositiveInt($_POST + $_GET, 'user'),
    'is_group_conversation' => $isGroupConversation,
    'action' => (string) ($_GET['action'] ?? $_POST['action'] ?? 'messages'),
    'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
];

$dispatcher = new ChatApiDispatcher();
$result = $dispatcher->dispatch($context);

jsonResponse($result['payload'], (int) ($result['status'] ?? 200));
