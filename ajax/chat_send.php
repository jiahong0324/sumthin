<?php
// ============================================================================
// File: ajax/chat_send.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Live Support Chat AJAX (TASK D11)
// Description: Dispatch chat messages with role authorization, 1-500 char validation,
//              and 1 msg/sec rate limiting.
// ============================================================================
require_once __DIR__ . '/../_base.php';

// Method check: Must be POST
if (!is_post()) {
    json_response(['ok' => false, 'message' => '405 Method Not Allowed: POST request required.'], 405);
}

// Authentication check
if (!is_logged_in()) {
    json_response(['ok' => false, 'message' => '401 Unauthorized: Please sign in.'], 401);
}

// CSRF verification
verify_csrf();

// Rate limiting: Max 1 message per second per user
$last_time = (float)($_SESSION['last_chat_timestamp'] ?? 0);
$now = microtime(true);
if (($now - $last_time) < 1.0) {
    json_response(['ok' => false, 'message' => 'Please wait a moment before sending another message (Max 1 msg/sec).'], 429);
}
$_SESSION['last_chat_timestamp'] = $now;

// Message validation (1 to 500 characters trimmed)
$message = trim((string)req('message'));
if ($message === '') {
    json_response(['ok' => false, 'message' => 'Message cannot be empty.'], 422);
} elseif (mb_strlen($message) > 500) {
    json_response(['ok' => false, 'message' => 'Message exceeds maximum limit of 500 characters.'], 422);
}

// Determine conversation target & role authorization
$user = current_user();

if ($user->role === 'Member') {
    // Member strictly restricted to their own conversation (ignore any member_id param)
    $member_id   = (int)$user->id;
    $sender_role = 'Member';
} elseif ($user->role === 'Admin') {
    // Admin writes to the specified member conversation
    $member_id   = (int)req('member_id');
    $sender_role = 'Admin';

    if ($member_id <= 0) {
        json_response(['ok' => false, 'message' => 'Please select a member conversation.'], 422);
    }

    $chk = $db->prepare("SELECT id FROM `user` WHERE id = ? AND role = 'Member'");
    $chk->execute([$member_id]);
    if (!$chk->fetchColumn()) {
        json_response(['ok' => false, 'message' => 'Recipient member account not found.'], 422);
    }
} else {
    json_response(['ok' => false, 'message' => '403 Forbidden: Invalid role.'], 403);
}

// Insert message
$stmt = $db->prepare('
    INSERT INTO `chat_message` (`member_id`, `sender_role`, `message`, `is_read`, `created_at`)
    VALUES (?, ?, ?, 0, NOW())
');
$stmt->execute([$member_id, $sender_role, $message]);
$new_id = (int)$db->lastInsertId();

json_response([
    'ok'      => true,
    'message' => 'Message sent.',
    'data'    => [
        'id'             => $new_id,
        'member_id'      => $member_id,
        'sender_role'    => $sender_role,
        'message'        => $message,
        'is_me'          => true,
        'created_at'     => date('Y-m-d H:i:s'),
        'time_formatted' => date('h:i A')
    ]
]);
