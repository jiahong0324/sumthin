<?php
// ============================================================================
// File: chat.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Member Customer Support Chat (TASK D11)
// Description: Real-time customer support chat interface for members.
//              Synchronized via AJAX polling without external dependencies.
// ============================================================================
require_once __DIR__ . '/_base.php';

// Enforce Member authorization
auth('Member');

$user = current_user();

// Preload existing conversation
$stmt = $db->prepare('
    SELECT * FROM `chat_message`
    WHERE `member_id` = ?
    ORDER BY `id` ASC
');
$stmt->execute([$user->id]);
$messages = $stmt->fetchAll();

// Mark any unread admin messages as read upon opening
$db->prepare("UPDATE `chat_message` SET `is_read` = 1 WHERE `member_id` = ? AND `sender_role` = 'Admin' AND `is_read` = 0")
   ->execute([$user->id]);

$last_message_id = 0;
if (!empty($messages)) {
    $last_message_id = (int)end($messages)->id;
}

$title = 'Live Support Chat';
require_once __DIR__ . '/_head.php';
?>

<div class="container" style="max-width:860px; margin:0 auto;">
    <div style="margin-bottom:20px;">
        <h1 style="font-size:24px; font-weight:800; color:var(--dark); margin:0;">Live Customer Support</h1>
        <p style="font-size:14px; color:var(--gray); margin:4px 0 0 0;">
            Chat directly with sumthin specialists regarding orders, technical specifications, and warranty assistance.
        </p>
    </div>

    <!-- Chat Box Card -->
    <div class="chat-page-wrapper" style="height:560px;">
        <div class="chat-main-pane">
            <!-- Chat Header -->
            <div class="chat-header">
                <div>
                    <div class="chat-header-title">sumthin Help Desk</div>
                    <div style="font-size:12px; color:var(--success); font-weight:600; display:flex; align-items:center; gap:5px; margin-top:2px;">
                        <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#10b981;"></span>
                        Specialists Online
                    </div>
                </div>
                <div style="font-size:12px; color:var(--gray);">
                    Polling active &bull; Auto-sync
                </div>
            </div>

            <!-- Messages Container -->
            <div class="chat-messages-container" id="chat-messages-box" 
                 data-member-id="<?= (int)$user->id ?>" 
                 data-last-id="<?= $last_message_id ?>">
                <?php if (empty($messages)): ?>
                    <div class="chat-empty-notice" style="text-align:center; padding:40px 20px; color:var(--gray); margin:auto;">
                        <div style="font-size:32px; margin-bottom:10px;">👋</div>
                        <div style="font-weight:700; color:var(--dark); font-size:15px; margin-bottom:4px;">How can we help you today?</div>
                        <div style="font-size:13px;">Send a message below and our team will get back to you shortly.</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($messages as $m): ?>
                        <?php $is_me = ($m->sender_role === 'Member'); ?>
                        <div class="chat-bubble-row <?= $is_me ? 'me' : 'them' ?>" data-msg-id="<?= (int)$m->id ?>">
                            <span class="chat-sender-name"><?= $is_me ? 'You' : 'sumthin Support' ?></span>
                            <div class="chat-bubble"><?= encode($m->message) ?></div>
                            <span class="chat-time-tag"><?= date('h:i A', strtotime($m->created_at)) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Input Bar -->
            <form id="chat-send-form" class="chat-input-bar">
                <input type="text" id="chat-message-input" name="message" maxlength="500" autocomplete="off"
                       placeholder="Type your message here (Press Enter to send)..." class="form-control">
                <button type="submit" id="chat-send-btn" class="btn btn-primary" style="height:42px; padding:0 20px; display:inline-flex; align-items:center; gap:6px;">
                    Send ➔
                </button>
            </form>
            <div style="padding:4px 20px 8px 20px; display:flex; justify-content:space-between; font-size:11px; color:var(--gray); background:#fff;">
                <span>Maximum 500 characters &bull; 1 message per second limit</span>
                <span id="chat-char-count">0 / 500</span>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/_foot.php'; ?>
