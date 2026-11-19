<?php
// ============================================================================
// File: admin/chat.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Admin Support Chat Desk (TASK D11)
// Description: Two-pane administrative customer messaging console:
//              - Left pane: Active member conversations, unread badges & snippets.
//              - Right pane: Selected member conversation & real-time messaging.
// ============================================================================
require_once __DIR__ . '/../_base.php';

// Enforce Admin authorization
auth('Admin');

// Fetch conversation list (all members with chat history)
$conv_stmt = $db->query("
    SELECT u.id AS member_id, u.name AS member_name, u.email AS member_email, u.photo AS member_photo,
           cm.message AS last_message, cm.created_at AS last_time,
           (SELECT COUNT(*) FROM `chat_message` WHERE `member_id` = u.id AND `sender_role` = 'Member' AND `is_read` = 0) AS unread_count
    FROM `user` u
    JOIN `chat_message` cm ON cm.id = (
        SELECT MAX(id) FROM `chat_message` WHERE member_id = u.id
    )
    WHERE u.role = 'Member'
    ORDER BY unread_count DESC, cm.id DESC
");
$conversations = $conv_stmt->fetchAll();

// Determine selected member conversation
$selected_member_id = (int)req('member_id');
if ($selected_member_id <= 0 && !empty($conversations)) {
    $selected_member_id = (int)$conversations[0]->member_id;
}

$selected_member = null;
$messages = [];
$last_message_id = 0;

if ($selected_member_id > 0) {
    // Fetch member profile
    $m_stmt = $db->prepare("SELECT id, name, email, photo FROM `user` WHERE id = ? AND role = 'Member'");
    $m_stmt->execute([$selected_member_id]);
    $selected_member = $m_stmt->fetch();

    if ($selected_member) {
        // Preload messages
        $msg_stmt = $db->prepare("
            SELECT * FROM `chat_message`
            WHERE member_id = ?
            ORDER BY id ASC
        ");
        $msg_stmt->execute([$selected_member_id]);
        $messages = $msg_stmt->fetchAll();

        // Mark incoming member messages as read
        $db->prepare("UPDATE `chat_message` SET `is_read` = 1 WHERE member_id = ? AND sender_role = 'Member' AND is_read = 0")
           ->execute([$selected_member_id]);

        if (!empty($messages)) {
            $last_message_id = (int)end($messages)->id;
        }
    }
}

// Fetch all other members for "Start New Chat" dropdown
$all_members = $db->query("SELECT id, name, email FROM `user` WHERE role = 'Member' ORDER BY name ASC")->fetchAll();

$title = 'Customer Support Desk';
require_once __DIR__ . '/../_head.php';
?>

<div class="container">
    <div class="admin-header-panel">
        <div>
            <h1 style="font-size:24px; font-weight:800; color:var(--dark);">Live Support Desk</h1>
            <p style="font-size:14px; color:var(--gray); margin-top:4px;">
                Communicate directly with registered customers in real-time without refreshing the page.
            </p>
        </div>
        <div>
            <!-- Quick Member Selector -->
            <form action="<?= base_url('admin/chat.php') ?>" method="GET" style="display:inline-flex; gap:8px;">
                <select name="member_id" id="chat-member-select" class="form-select" style="min-width:220px;">
                    <option value="">-- Jump to Member Chat --</option>
                    <?php foreach ($all_members as $am): ?>
                        <option value="<?= (int)$am->id ?>" <?= $selected_member_id === (int)$am->id ? 'selected' : '' ?>>
                            <?= encode($am->name) ?> (<?= encode($am->email) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <!-- Two-Pane Chat Container -->
    <div class="chat-page-wrapper">
        <!-- LEFT PANE: Conversation List -->
        <div class="chat-sidebar">
            <div class="chat-sidebar-header">
                Conversations (<?= count($conversations) ?>)
            </div>
            <div class="chat-conv-list" id="admin-conv-list">
                <?php if (empty($conversations)): ?>
                    <div style="padding:24px 16px; text-align:center; color:var(--gray); font-size:13px;">
                        No active support conversations.
                    </div>
                <?php else: ?>
                    <?php foreach ($conversations as $c): ?>
                        <?php $is_active = ($c->member_id == $selected_member_id); ?>
                        <a href="<?= base_url('admin/chat.php?member_id=' . (int)$c->member_id) ?>" 
                           class="chat-conv-item <?= $is_active ? 'active' : '' ?>" 
                           id="conv-tab-<?= (int)$c->member_id ?>"
                           data-member-id="<?= (int)$c->member_id ?>">
                            <div class="chat-conv-avatar">
                                <?= strtoupper(substr($c->member_name, 0, 1)) ?>
                            </div>
                            <div class="chat-conv-content">
                                <div class="chat-conv-name-row">
                                    <span class="chat-conv-name"><?= encode($c->member_name) ?></span>
                                    <span class="chat-conv-time"><?= date('h:i A', strtotime($c->last_time)) ?></span>
                                </div>
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <span class="chat-conv-last-msg"><?= encode($c->last_message) ?></span>
                                    <span class="badge-unread-chat conv-unread-pill" style="<?= $c->unread_count > 0 ? '' : 'display:none;' ?>">
                                        <?= (int)$c->unread_count ?>
                                    </span>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT PANE: Selected Member Conversation -->
        <div class="chat-main-pane">
            <?php if ($selected_member): ?>
                <!-- Header -->
                <div class="chat-header">
                    <div>
                        <div class="chat-header-title"><?= encode($selected_member->name) ?></div>
                        <div style="font-size:12px; color:var(--gray);">
                            <?= encode($selected_member->email) ?> &bull; Customer #<?= (int)$selected_member->id ?>
                        </div>
                    </div>
                    <div>
                        <a href="<?= base_url('admin/member_detail.php?id=' . (int)$selected_member->id) ?>" class="btn btn-outline-sm" target="_blank">
                            View Member Profile ↗
                        </a>
                    </div>
                </div>

                <!-- Messages Stream -->
                <div class="chat-messages-container" id="chat-messages-box" 
                     data-member-id="<?= (int)$selected_member->id ?>" 
                     data-last-id="<?= $last_message_id ?>">
                    <?php if (empty($messages)): ?>
                        <div class="chat-empty-notice" style="text-align:center; padding:40px 20px; color:var(--gray); margin:auto;">
                            <div style="font-size:28px; margin-bottom:8px;">💬</div>
                            <div style="font-weight:700; color:var(--dark);">No messages yet</div>
                            <div style="font-size:13px;">Type a message below to initiate support with <?= encode($selected_member->name) ?>.</div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($messages as $m): ?>
                            <?php $is_me = ($m->sender_role === 'Admin'); ?>
                            <div class="chat-bubble-row <?= $is_me ? 'me' : 'them' ?>" data-msg-id="<?= (int)$m->id ?>">
                                <span class="chat-sender-name"><?= $is_me ? 'Support Staff (You)' : encode($selected_member->name) ?></span>
                                <div class="chat-bubble"><?= encode($m->message) ?></div>
                                <span class="chat-time-tag"><?= date('h:i A', strtotime($m->created_at)) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Input Bar -->
                <form id="chat-send-form" class="chat-input-bar">
                    <input type="text" id="chat-message-input" name="message" maxlength="500" autocomplete="off"
                           placeholder="Reply to <?= encode($selected_member->name) ?>..." class="form-control">
                    <button type="submit" id="chat-send-btn" class="btn btn-primary" style="height:42px; padding:0 20px; display:inline-flex; align-items:center; gap:6px;">
                        Reply ➔
                    </button>
                </form>
                <div style="padding:4px 20px 8px 20px; display:flex; justify-content:space-between; font-size:11px; color:var(--gray); background:#fff;">
                    <span>Maximum 500 characters &bull; Rate limited to 1 msg/sec</span>
                    <span id="chat-char-count">0 / 500</span>
                </div>
            <?php else: ?>
                <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; height:100%; color:var(--gray); text-align:center; padding:40px;">
                    <div style="font-size:44px; margin-bottom:12px;">💬</div>
                    <h3 style="font-weight:700; color:var(--dark); margin-bottom:6px;">Select a Conversation</h3>
                    <p style="font-size:14px; max-width:320px;">
                        Choose an active conversation from the left pane or pick a member from the dropdown above.
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../_foot.php'; ?>
