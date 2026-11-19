<?php
// ============================================================================
// File: ajax/chat_poll.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Live Support Chat AJAX (TASK D11)
// Description: Poll newer messages, auto-mark incoming messages as read,
//              update unread badges, and strictly enforce conversation isolation.
// ============================================================================
require_once __DIR__ . '/../_base.php';

// Authentication check
if (!is_logged_in()) {
    json_response(['ok' => false, 'message' => '401 Unauthorized: Please sign in.'], 401);
}

$user = current_user();
$after_id = max(0, (int)req('after_id', 0));

// Determine conversation target & enforce strict authorization isolation
if ($user->role === 'Member') {
    // SECURITY CRITICAL: A Member ALWAYS gets their own conversation,
    // regardless of any passed member_id parameter.
    $member_id = (int)$user->id;
} elseif ($user->role === 'Admin') {
    $member_id = (int)req('member_id', 0);
} else {
    json_response(['ok' => false, 'message' => '403 Forbidden.'], 403);
}

$messages_out = [];

if ($member_id > 0) {
    // 1. Fetch newer messages in this conversation
    $stmt = $db->prepare('
        SELECT * FROM `chat_message`
        WHERE `member_id` = ? AND `id` > ?
        ORDER BY `id` ASC
    ');
    $stmt->execute([$member_id, $after_id]);
    $new_messages = $stmt->fetchAll();

    foreach ($new_messages as $msg) {
        $messages_out[] = [
            'id'             => (int)$msg->id,
            'member_id'      => (int)$msg->member_id,
            'sender_role'    => $msg->sender_role,
            'message'        => $msg->message,
            'is_me'          => ($user->role === $msg->sender_role),
            'created_at'     => $msg->created_at,
            'time_formatted' => date('h:i A', strtotime($msg->created_at))
        ];
    }

    // 2. Mark incoming messages from the other party as read (if mark_read is enabled)
    $should_mark_read = (int)req('mark_read', 1) === 1;
    if ($should_mark_read) {
        $other_role = ($user->role === 'Admin') ? 'Member' : 'Admin';
        $mark_stmt = $db->prepare('
            UPDATE `chat_message`
            SET `is_read` = 1
            WHERE `member_id` = ? AND `sender_role` = ? AND `is_read` = 0
        ');
        $mark_stmt->execute([$member_id, $other_role]);
    }
}

// 3. Compute overall unread count for navbar badge
$nav_unread_count = 0;
if ($user->role === 'Member') {
    $u_stmt = $db->prepare("SELECT COUNT(*) FROM `chat_message` WHERE `member_id` = ? AND `sender_role` = 'Admin' AND `is_read` = 0");
    $u_stmt->execute([$user->id]);
    $nav_unread_count = (int)$u_stmt->fetchColumn();
} elseif ($user->role === 'Admin') {
    $nav_unread_count = (int)$db->query("SELECT COUNT(*) FROM `chat_message` WHERE `sender_role` = 'Member' AND `is_read` = 0")->fetchColumn();
}

// 4. If Admin, also return conversation list summary with unread counts
$conversations_summary = [];
if ($user->role === 'Admin') {
    $conv_stmt = $db->query("
        SELECT u.id AS member_id, u.name AS member_name, u.photo AS member_photo,
               cm.message AS last_message, cm.created_at AS last_time,
               (SELECT COUNT(*) FROM `chat_message` WHERE `member_id` = u.id AND `sender_role` = 'Member' AND `is_read` = 0) AS unread_count
        FROM `user` u
        JOIN `chat_message` cm ON cm.id = (
            SELECT MAX(id) FROM `chat_message` WHERE member_id = u.id
        )
        WHERE u.role = 'Member'
        ORDER BY cm.id DESC
    ");
    $conv_rows = $conv_stmt->fetchAll();
    foreach ($conv_rows as $c) {
        $conversations_summary[] = [
            'member_id'    => (int)$c->member_id,
            'member_name'  => $c->member_name,
            'last_message' => $c->last_message,
            'unread_count' => (int)$c->unread_count,
            'time'         => date('h:i A', strtotime($c->last_time))
        ];
    }
}

json_response([
    'ok'            => true,
    'member_id'     => $member_id,
    'messages'      => $messages_out,
    'unread_count'  => $nav_unread_count,
    'conversations' => $conversations_summary
]);
