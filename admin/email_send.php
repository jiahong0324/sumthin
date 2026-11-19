<?php
// ============================================================================
// File: admin/email_send.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Admin Email Dispatcher (Single & Bulk)
// Description: Allows site administrators to compose and send transactional &
//              announcement emails to individual customers or segmented audiences.
//              Supports templates, {name} and {email} placeholders, confirmation step,
//              Gmail ~500/day limit warnings, 100-recipient cap, and 20/min rate limits.
// ============================================================================
require_once __DIR__ . '/../_base.php';

auth('Admin');

$admin_user = current_user();
$admin_id   = (int)$admin_user->id;

// 1. Maintain sliding 60-second rate-limit window in admin session (Max 20/minute)
$now = time();
if (!isset($_SESSION['admin_email_timestamps']) || !is_array($_SESSION['admin_email_timestamps'])) {
    $_SESSION['admin_email_timestamps'] = [];
}
$_SESSION['admin_email_timestamps'] = array_values(array_filter(
    $_SESSION['admin_email_timestamps'],
    fn($t) => ($now - $t) < 60
));
$sent_this_minute = count($_SESSION['admin_email_timestamps']);
$rate_limit_remaining = max(0, 20 - $sent_this_minute);

// 2. Fetch email templates for dropdown
$tpl_stmt = $db->query('SELECT `id`, `name`, `subject`, `body` FROM `email_template` ORDER BY `name` ASC');
$templates = $tpl_stmt->fetchAll();

// 3. Request parameters
$mode       = req('mode', 'single'); // 'single' or 'bulk'
if (!in_array($mode, ['single', 'bulk'], true)) {
    $mode = 'single';
}

$member_id   = (int)req('member_id');
$segment     = req('segment', 'all_verified');
$subject     = req('subject');
$message     = req('message');
$template_id = (int)req('template_id');
$confirmed   = (int)req('confirmed');

// Fetch active members for single mode dropdown
$member_list = $db->query("
    SELECT `id`, `name`, `email`, `role`, `status`, `email_verified_at`
    FROM `user`
    WHERE `role` = 'Member' AND `status` = 'Active'
    ORDER BY `name` ASC
")->fetchAll();

// Helper to query recipients by segment
function get_segment_recipients(PDO $db, string $segment): array {
    switch ($segment) {
        case 'paid_orders':
            // Members with at least one Paid order (Active and with email)
            $sql = "
                SELECT DISTINCT u.`id`, u.`name`, u.`email`
                FROM `user` u
                INNER JOIN `order` o ON u.`id` = o.`user_id`
                WHERE u.`role` = 'Member' 
                  AND u.`status` = 'Active' 
                  AND o.`payment_status` = 'Paid'
                  AND u.`email` IS NOT NULL AND u.`email` != ''
                ORDER BY u.`name` ASC
            ";
            break;

        case 'no_orders':
            // Members with no orders yet (Active and with email)
            $sql = "
                SELECT u.`id`, u.`name`, u.`email`
                FROM `user` u
                WHERE u.`role` = 'Member' 
                  AND u.`status` = 'Active' 
                  AND u.`id` NOT IN (SELECT DISTINCT `user_id` FROM `order` WHERE `user_id` IS NOT NULL)
                  AND u.`email` IS NOT NULL AND u.`email` != ''
                ORDER BY u.`name` ASC
            ";
            break;

        case 'all_verified':
        default:
            // All active verified members
            $sql = "
                SELECT u.`id`, u.`name`, u.`email`
                FROM `user` u
                WHERE u.`role` = 'Member' 
                  AND u.`status` = 'Active' 
                  AND u.`email_verified_at` IS NOT NULL
                  AND u.`email` IS NOT NULL AND u.`email` != ''
                ORDER BY u.`name` ASC
            ";
            break;
    }
    return $db->query($sql)->fetchAll();
}

// 4. Handle POST form submission
$show_confirmation = false;
$preview_recipients = [];
$preview_count      = 0;
$bulk_result        = null;

if (is_post()) {
    verify_csrf();

    // Subject validation (3 - 150 chars)
    $subject_len = mb_strlen(trim($subject));
    if (trim($subject) === '') {
        $errors['subject'] = 'Subject is required.';
    } elseif ($subject_len < 3 || $subject_len > 150) {
        $errors['subject'] = 'Subject must be between 3 and 150 characters.';
    }

    // Message validation (10 - 5000 chars)
    $msg_len = mb_strlen(trim($message));
    if (trim($message) === '') {
        $errors['message'] = 'Message is required.';
    } elseif ($msg_len < 10 || $msg_len > 5000) {
        $errors['message'] = 'Message must be between 10 and 5000 characters.';
    }

    if ($mode === 'single') {
        // Single member validation
        if ($member_id <= 0) {
            $errors['member_id'] = 'Please select a recipient member.';
        } else {
            $m_stmt = $db->prepare('SELECT `id`, `name`, `email`, `role`, `status` FROM `user` WHERE `id` = ?');
            $m_stmt->execute([$member_id]);
            $target = $m_stmt->fetch();

            if (!$target) {
                $errors['member_id'] = 'Selected member does not exist.';
            } elseif ($target->status === 'Blocked') {
                $errors['member_id'] = 'Cannot dispatch email to a suspended or blocked member.';
            } elseif (empty($target->email) || !filter_var($target->email, FILTER_VALIDATE_EMAIL)) {
                $errors['member_id'] = 'Selected member does not have a valid email address.';
            }
        }

        // Check 20/min rate limit
        if (empty($errors)) {
            if ($rate_limit_remaining <= 0) {
                $errors['rate_limit'] = 'Admin session rate limit reached: maximum 20 emails per minute. Please wait a moment before sending more.';
            }
        }

        // Dispatch single email if validation passed
        if (empty($errors)) {
            $to_name  = $target->name;
            $to_email = $target->email;

            // Replace placeholders server-side
            $final_subject = str_replace(['{name}', '{email}'], [$to_name, $to_email], $subject);
            $final_message = str_replace(['{name}', '{email}'], [$to_name, $to_email], $message);

            // Escape message and convert with nl2br into HTML email
            $html_body = nl2br(encode($final_message));

            $sent = send_mail($to_email, $to_name, $final_subject, $html_body, 'admin', $admin_id);
            $_SESSION['admin_email_timestamps'][] = time();

            if ($sent) {
                set_flash('success', "Email successfully dispatched to {$to_name} ({$to_email}).");
            } else {
                set_flash('warning', "Email recorded in audit log. (Note: Check SMTP settings or email_log).");
            }
            redirect(base_url('admin/email_send.php?member_id=' . $member_id));
        }

    } elseif ($mode === 'bulk') {
        // Bulk segment recipient resolution
        $raw_recipients = get_segment_recipients($db, $segment);
        // Exclude members without valid email
        $valid_recipients = array_values(array_filter($raw_recipients, function($r) {
            return !empty($r->email) && filter_var($r->email, FILTER_VALIDATE_EMAIL);
        }));

        $preview_count = count($valid_recipients);
        if ($preview_count === 0) {
            $errors['segment'] = 'The selected audience segment currently has no eligible active members with valid email addresses.';
        }

        if (empty($errors)) {
            // Apply hard cap of 100 recipients per send
            $capped_recipients = array_slice($valid_recipients, 0, 100);

            // Step 1: Confirmation step if not confirmed yet
            if (!$confirmed) {
                $show_confirmation  = true;
                $preview_recipients = $capped_recipients;
            } else {
                // Step 2: Confirmed dispatch
                // Check available rate limit slots
                if ($rate_limit_remaining <= 0) {
                    $errors['rate_limit'] = 'Admin session rate limit reached: maximum 20 emails per minute. Please wait before executing bulk dispatch.';
                } else {
                    $sent_count   = 0;
                    $failed_count = 0;

                    foreach ($capped_recipients as $rec) {
                        // Re-check rate limit during loop
                        $current_now = time();
                        $_SESSION['admin_email_timestamps'] = array_values(array_filter(
                            $_SESSION['admin_email_timestamps'],
                            fn($t) => ($current_now - $t) < 60
                        ));
                        if (count($_SESSION['admin_email_timestamps']) >= 20) {
                            $failed_count += (count($capped_recipients) - ($sent_count + $failed_count));
                            break;
                        }

                        if (empty($rec->email) || !filter_var($rec->email, FILTER_VALIDATE_EMAIL)) {
                            $failed_count++;
                            continue;
                        }

                        // Replace placeholders server-side per recipient
                        $final_subject = str_replace(['{name}', '{email}'], [$rec->name, $rec->email], $subject);
                        $final_message = str_replace(['{name}', '{email}'], [$rec->name, $rec->email], $message);
                        $html_body     = nl2br(encode($final_message));

                        $ok = send_mail($rec->email, $rec->name, $final_subject, $html_body, 'admin', $admin_id);
                        $_SESSION['admin_email_timestamps'][] = time();

                        if ($ok) {
                            $sent_count++;
                        } else {
                            $failed_count++;
                        }
                    }

                    $bulk_result = [
                        'sent'   => $sent_count,
                        'failed' => $failed_count,
                        'total'  => count($capped_recipients),
                    ];

                    set_flash('success', "Bulk campaign completed: {$sent_count} sent, {$failed_count} failed.");
                }
            }
        }
    }
}

$title = 'Send Email to Customers';
require_once __DIR__ . '/../_head.php';
?>

<div class="container" style="max-width: 900px;">
    <!-- Top Breadcrumb & Navigation Tabs -->
    <div style="margin-bottom: 24px;">
        <h1 style="font-size:26px; font-weight:800; color:var(--dark);">Customer Email Communications</h1>
        <p style="font-size:14px; color:var(--gray); margin-top:4px;">
            Send transactional messages, promotional announcements, and support notices to customers.
        </p>

        <!-- Navigation Sub-Tabs -->
        <div style="display:flex; gap:10px; margin-top:16px; border-bottom:1px solid var(--border); padding-bottom:12px;">
            <a href="<?= base_url('admin/email_send.php') ?>" class="btn btn-primary-sm">Compose & Send</a>
            <a href="<?= base_url('admin/email_templates.php') ?>" class="btn btn-outline-sm">Email Templates</a>
            <a href="<?= base_url('admin/email_log.php') ?>" class="btn btn-outline-sm">Email Audit Log</a>
        </div>
    </div>

    <!-- Rate Limit Status Pill -->
    <div style="display:flex; justify-content:space-between; align-items:center; background:#f8fafc; border:1px solid var(--border); border-radius:var(--radius); padding:12px 18px; margin-bottom:24px; font-size:13px;">
        <div>
            <span>⚡ Rate Limit: <strong><?= $sent_this_minute ?> / 20</strong> emails used this minute</span>
            <span style="color:var(--gray); margin-left:8px;">(<?= $rate_limit_remaining ?> remaining in active session)</span>
        </div>
        <div style="font-size:12px; color:var(--gray);">
            Max 20 emails / min &bull; Hard cap 100 / bulk batch
        </div>
    </div>

    <?php if (isset($errors['rate_limit'])): ?>
        <div class="flash-alert flash-error" style="margin-bottom: 20px;">
            <div class="flash-icon">✕</div>
            <div class="flash-message"><?= encode($errors['rate_limit']) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($bulk_result): ?>
        <div class="flash-alert flash-success" style="margin-bottom: 20px;">
            <div class="flash-icon">✓</div>
            <div class="flash-message">
                <strong>Campaign Executed:</strong> <?= (int)$bulk_result['sent'] ?> sent successfully, <?= (int)$bulk_result['failed'] ?> failed out of <?= (int)$bulk_result['total'] ?> recipients.
                <a href="<?= base_url('admin/email_log.php?type=admin') ?>" class="form-link" style="margin-left:8px; text-decoration:underline;">View Audit Log &rarr;</a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Mode Selector Tabs (Single / Bulk) -->
    <div style="display:flex; gap:8px; margin-bottom:20px;">
        <a href="<?= base_url('admin/email_send.php?mode=single' . ($member_id ? '&member_id=' . $member_id : '')) ?>" 
           class="btn <?= $mode === 'single' ? 'btn-primary-sm' : 'btn-outline-sm' ?>" 
           style="flex:1; text-align:center;">
            Mode: Single Customer
        </a>
        <a href="<?= base_url('admin/email_send.php?mode=bulk') ?>" 
           class="btn <?= $mode === 'bulk' ? 'btn-primary-sm' : 'btn-outline-sm' ?>" 
           style="flex:1; text-align:center;">
            Mode: Bulk Audience Segment
        </a>
    </div>

    <!-- Main Compose Card -->
    <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:28px; box-shadow:var(--shadow-sm);">

        <?php if ($show_confirmation): ?>
            <!-- Confirmation Step for Bulk Send -->
            <div style="border: 2px dashed #3b82f6; background:#eff6ff; border-radius:8px; padding:22px; margin-bottom:24px;">
                <h3 style="font-size:18px; font-weight:800; color:#1e40af; margin-top:0;">
                    Confirm Bulk Email Dispatch
                </h3>
                <p style="font-size:14px; color:#1e3a8a; line-height:1.6; margin:8px 0 16px 0;">
                    You are about to dispatch an email campaign to <strong><?= count($preview_recipients) ?></strong> recipient(s).
                </p>

                <div style="background:#fff; border:1px solid #bfdbfe; border-radius:6px; padding:12px; margin-bottom:16px; font-size:13px; color:#1e40af;">
                    <strong>⚠ Gmail Dispatch Warning:</strong> Standard Gmail SMTP accounts enforce an approximate daily threshold of ~500 emails/day. Bulk sends are hard-capped at a maximum of 100 recipients per execution.
                </div>

                <div style="max-height:160px; overflow-y:auto; background:#fff; border:1px solid var(--border); border-radius:6px; padding:10px 14px; margin-bottom:18px; font-size:12px; font-family:monospace;">
                    <?php foreach ($preview_recipients as $idx => $pr): ?>
                        <div><?= ($idx + 1) ?>. <?= encode($pr->name) ?> &lt;<?= encode($pr->email) ?>&gt;</div>
                    <?php endforeach; ?>
                </div>

                <form action="<?= base_url('admin/email_send.php?mode=bulk') ?>" method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="mode" value="bulk">
                    <input type="hidden" name="segment" value="<?= encode($segment) ?>">
                    <input type="hidden" name="subject" value="<?= encode($subject) ?>">
                    <input type="hidden" name="message" value="<?= encode($message) ?>">
                    <input type="hidden" name="confirmed" value="1">

                    <div style="display:flex; gap:12px;">
                        <button type="submit" class="btn btn-primary">
                            ✓ Confirm & Send to <?= count($preview_recipients) ?> Recipient(s)
                        </button>
                        <a href="<?= base_url('admin/email_send.php?mode=bulk') ?>" class="btn btn-outline">
                            Cancel & Edit
                        </a>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <form action="<?= base_url('admin/email_send.php?mode=' . $mode) ?>" method="POST" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="mode" value="<?= encode($mode) ?>">

            <!-- Recipient Selection by Mode -->
            <?php if ($mode === 'single'): ?>
                <div class="form-group">
                    <label for="member_id" class="form-label">Recipient Member</label>
                    <select name="member_id" id="member_id" class="form-select">
                        <option value="">-- Choose Member --</option>
                        <?php foreach ($member_list as $m): ?>
                            <option value="<?= (int)$m->id ?>" <?= $member_id === (int)$m->id ? 'selected' : '' ?>>
                                <?= encode($m->name) ?> (<?= encode($m->email) ?>) - <?= !empty($m->email_verified_at) ? 'Verified' : 'Unverified' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= err('member_id') ?>
                    <span class="form-help">Select an active registered member from the database.</span>
                </div>
            <?php else: ?>
                <div class="form-group">
                    <label for="segment" class="form-label">Target Audience Segment</label>
                    <select name="segment" id="segment" class="form-select">
                        <option value="all_verified" <?= $segment === 'all_verified' ? 'selected' : '' ?>>
                            All Active Verified Members (email_verified_at IS NOT NULL)
                        </option>
                        <option value="paid_orders" <?= $segment === 'paid_orders' ? 'selected' : '' ?>>
                            Members with at least one Paid order
                        </option>
                        <option value="no_orders" <?= $segment === 'no_orders' ? 'selected' : '' ?>>
                            Members with no orders yet (Prospective Buyers)
                        </option>
                    </select>
                    <?= err('segment') ?>
                    <span class="form-help">Blocked accounts and members with missing emails are automatically excluded. Hard cap: 100 recipients.</span>
                </div>
            <?php endif; ?>

            <!-- Predefined Template Dropdown -->
            <div class="form-group">
                <label for="template_select" class="form-label">Load Template (Optional)</label>
                <select id="template_select" class="form-select">
                    <option value="">-- Choose a pre-defined template to populate below --</option>
                    <?php foreach ($templates as $tpl): ?>
                        <option value="<?= (int)$tpl->id ?>"><?= encode($tpl->name) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="form-help">Selecting a template automatically populates the Subject and Message fields.</span>
            </div>

            <!-- Subject Line -->
            <div class="form-group">
                <label for="subject" class="form-label">Email Subject Line</label>
                <?= html_text('subject', $subject, [
                    'id'          => 'subject',
                    'placeholder' => 'Enter email subject (3-150 characters)',
                    'maxlength'   => '150',
                ]) ?>
                <?= err('subject') ?>
                <span class="form-help">Supports placeholders: <code>{name}</code> and <code>{email}</code>. (3 - 150 characters)</span>
            </div>

            <!-- Message Body -->
            <div class="form-group">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <label for="message" class="form-label">Message Body</label>
                    <span style="font-size:12px; color:var(--gray);">Characters: <span id="char-count">0</span> / 5000</span>
                </div>
                <?= html_textarea('message', $message, [
                    'id'          => 'message',
                    'rows'        => 9,
                    'placeholder' => "Write your message here...\n\nSupports {name} and {email} placeholders.\nMessage is safely HTML-escaped and line breaks are converted with nl2br.",
                ]) ?>
                <?= err('message') ?>
                <span class="form-help">Placeholders: <code>{name}</code> is replaced with customer's name, <code>{email}</code> is replaced with customer's email. (10 - 5000 characters)</span>
            </div>

            <!-- Submit Button -->
            <div style="margin-top:24px; display:flex; justify-content:flex-end; gap:12px;">
                <?php if ($mode === 'single'): ?>
                    <button type="submit" class="btn btn-primary" style="padding:10px 24px;">
                        ✉ Dispatch Email Now
                    </button>
                <?php else: ?>
                    <button type="submit" class="btn btn-primary" style="padding:10px 24px;">
                        Preview Audience & Confirm Dispatch &rarr;
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Embedded JSON template storage for jQuery retrieval (No inline JS) -->
<script type="application/json" id="email-templates-json">
<?= json_encode($templates, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
</script>

<?php require_once __DIR__ . '/../_foot.php'; ?>
