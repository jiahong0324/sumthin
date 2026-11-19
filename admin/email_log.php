<?php
// ============================================================================
// File: admin/email_log.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Admin Email Audit Log
// Description: Searchable and filterable audit trail of all outbound emails.
//              Supports pagination, status/type filters, search keyword, and
//              detail inspection. Strictly redacts bodies of type 'auth' for security.
// ============================================================================
require_once __DIR__ . '/../_base.php';

auth('Admin');

$q             = req('q');
$status_filter = req('status');
$type_filter   = req('type');
$detail_id     = (int)req('id');
$page          = max(1, (int)req('page', 1));
$limit         = 15;
$offset        = ($page - 1) * $limit;

// 1. Fetch single log entry if viewing details
$detail_item = null;
if ($detail_id > 0) {
    $stmt = $db->prepare('
        SELECT el.*, u.name AS admin_name, u.email AS admin_email
        FROM `email_log` el
        LEFT JOIN `user` u ON el.sent_by = u.id
        WHERE el.id = ?
    ');
    $stmt->execute([$detail_id]);
    $detail_item = $stmt->fetch();
}

// 2. Build filter conditions
$where_clauses = ['1=1'];
$params        = [];

if ($q !== '') {
    $where_clauses[] = '(el.recipient LIKE ? OR el.subject LIKE ?)';
    $params[] = "%$q%";
    $params[] = "%$q%";
}

if ($status_filter === 'Sent' || $status_filter === 'Failed') {
    $where_clauses[] = 'el.status = ?';
    $params[] = $status_filter;
}

if ($type_filter !== '' && in_array($type_filter, ['admin', 'auth', 'order', 'marketing', 'support', 'system'], true)) {
    $where_clauses[] = 'el.type = ?';
    $params[] = $type_filter;
}

$where_sql = implode(' AND ', $where_clauses);

// Count total matching records
$count_stmt = $db->prepare("SELECT COUNT(*) FROM `email_log` el WHERE $where_sql");
$count_stmt->execute($params);
$total_rows  = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $limit));

// Fetch paginated log records
$sql = "
    SELECT el.*, u.name AS admin_name
    FROM `email_log` el
    LEFT JOIN `user` u ON el.sent_by = u.id
    WHERE $where_sql
    ORDER BY el.id DESC
    LIMIT $limit OFFSET $offset
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$title = 'Email Audit Log';
require_once __DIR__ . '/../_head.php';
?>

<div class="container">
    <!-- Header -->
    <div style="margin-bottom: 24px;">
        <h1 style="font-size:26px; font-weight:800; color:var(--dark);">Email Audit & Dispatch Logs</h1>
        <p style="font-size:14px; color:var(--gray); margin-top:4px;">
            Auditing records of all outbound emails dispatched via PHPMailer SMTP.
        </p>

        <!-- Navigation Sub-Tabs -->
        <div style="display:flex; gap:10px; margin-top:16px; border-bottom:1px solid var(--border); padding-bottom:12px;">
            <a href="<?= base_url('admin/email_send.php') ?>" class="btn btn-outline-sm">Compose & Send</a>
            <a href="<?= base_url('admin/email_templates.php') ?>" class="btn btn-outline-sm">Email Templates</a>
            <a href="<?= base_url('admin/email_log.php') ?>" class="btn btn-primary-sm">Email Audit Log</a>
        </div>
    </div>

    <!-- Detail View Card (if inspection requested) -->
    <?php if ($detail_item): ?>
        <div style="background:#fff; border:2px solid var(--primary); border-radius:var(--radius); padding:26px; margin-bottom:28px; box-shadow:var(--shadow-md);">
            <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border); padding-bottom:12px; margin-bottom:18px;">
                <h2 style="font-size:18px; font-weight:800; color:var(--dark); margin:0;">
                    Email Audit Inspection #<?= (int)$detail_item->id ?>
                </h2>
                <a href="<?= base_url('admin/email_log.php?' . http_build_query(array_diff_key($_GET, ['id' => '']))) ?>" class="btn btn-outline-sm">
                    &times; Close Inspection
                </a>
            </div>

            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:14px; font-size:13px; margin-bottom:18px;">
                <div>
                    <span style="color:var(--gray); display:block; font-size:11px; text-transform:uppercase; font-weight:700;">Recipient</span>
                    <strong><?= encode($detail_item->recipient) ?></strong>
                </div>
                <div>
                    <span style="color:var(--gray); display:block; font-size:11px; text-transform:uppercase; font-weight:700;">Subject</span>
                    <strong><?= encode($detail_item->subject) ?></strong>
                </div>
                <div>
                    <span style="color:var(--gray); display:block; font-size:11px; text-transform:uppercase; font-weight:700;">Classification</span>
                    <span class="role-pill pill-member" style="font-size:11px;"><?= encode($detail_item->type) ?></span>
                </div>
                <div>
                    <span style="color:var(--gray); display:block; font-size:11px; text-transform:uppercase; font-weight:700;">Status</span>
                    <span class="role-pill" style="font-size:11px; <?= $detail_item->status === 'Sent' ? 'background:#dcfce7; color:#15803d;' : 'background:#fee2e2; color:#dc2626;' ?>">
                        <?= encode($detail_item->status) ?>
                    </span>
                </div>
                <div>
                    <span style="color:var(--gray); display:block; font-size:11px; text-transform:uppercase; font-weight:700;">Sent By</span>
                    <?= $detail_item->admin_name ? encode($detail_item->admin_name) . ' (Admin)' : 'System Dispatcher' ?>
                </div>
                <div>
                    <span style="color:var(--gray); display:block; font-size:11px; text-transform:uppercase; font-weight:700;">Timestamp</span>
                    <?= encode(date('d M Y, h:i:s A', strtotime($detail_item->sent_at))) ?>
                </div>
            </div>

            <div style="margin-top:16px;">
                <label class="form-label" style="font-weight:700;">Recorded Email Content Body</label>
                <?php if ($detail_item->type === 'auth'): ?>
                    <!-- Security Requirement: Bodies of type 'auth' are redacted, never display them -->
                    <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:6px; padding:16px; color:#b91c1c; font-size:13px; font-weight:600; display:flex; align-items:center; gap:8px;">
                        <span>🔒</span>
                        <span>[REDACTED FOR SECURITY - Authentication tokens and password reset credentials are concealed]</span>
                    </div>
                <?php else: ?>
                    <div style="background:#f8fafc; border:1px solid var(--border); border-radius:6px; padding:16px; font-size:13px; line-height:1.6; white-space:pre-wrap; font-family:monospace; max-height:280px; overflow-y:auto;">
                        <?= encode($detail_item->body) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Search & Filter Controls -->
    <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:20px; margin-bottom:24px;">
        <form action="<?= base_url('admin/email_log.php') ?>" method="GET" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
            <div style="flex:2; min-width:240px;">
                <label for="q" class="form-label">Search Recipient or Subject</label>
                <input type="text" name="q" id="q" value="<?= encode($q) ?>" placeholder="Search recipient email or subject..." class="form-control">
            </div>

            <div style="flex:1; min-width:140px;">
                <label for="type" class="form-label">Email Type</label>
                <select name="type" id="type" class="form-select">
                    <option value="">All Types</option>
                    <option value="admin" <?= $type_filter === 'admin' ? 'selected' : '' ?>>admin</option>
                    <option value="auth" <?= $type_filter === 'auth' ? 'selected' : '' ?>>auth</option>
                    <option value="order" <?= $type_filter === 'order' ? 'selected' : '' ?>>order</option>
                    <option value="marketing" <?= $type_filter === 'marketing' ? 'selected' : '' ?>>marketing</option>
                    <option value="support" <?= $type_filter === 'support' ? 'selected' : '' ?>>support</option>
                    <option value="system" <?= $type_filter === 'system' ? 'selected' : '' ?>>system</option>
                </select>
            </div>

            <div style="flex:1; min-width:140px;">
                <label for="status" class="form-label">Dispatch Status</label>
                <select name="status" id="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="Sent" <?= $status_filter === 'Sent' ? 'selected' : '' ?>>Sent</option>
                    <option value="Failed" <?= $status_filter === 'Failed' ? 'selected' : '' ?>>Failed</option>
                </select>
            </div>

            <div style="display:flex; gap:8px;">
                <button type="submit" class="btn btn-primary-sm" style="height:38px;">Filter Logs</button>
                <a href="<?= base_url('admin/email_log.php') ?>" class="btn btn-outline-sm" style="height:38px; display:inline-flex; align-items:center;">Reset</a>
            </div>
        </form>
    </div>

    <!-- Results Count -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; font-size:14px; color:var(--gray);">
        <span>Showing <strong><?= count($logs) ?></strong> of <strong><?= $total_rows ?></strong> audit log record(s)</span>
        <span>Page <strong><?= $page ?></strong> of <strong><?= $total_pages ?></strong></span>
    </div>

    <!-- Logs Table -->
    <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; box-shadow:var(--shadow-sm);">
        <div class="table-responsive">
            <table class="table-custom">
                <thead>
                    <tr>
                        <th style="width:60px;">ID</th>
                        <th>Recipient</th>
                        <th>Subject Line</th>
                        <th style="width:100px;">Type</th>
                        <th style="width:90px;">Status</th>
                        <th>Sent By</th>
                        <th>Date & Time</th>
                        <th style="text-align:right; width:90px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="8" style="text-align:center; padding:40px; color:var(--gray);">
                                No email logs found matching your filter criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td>#<?= (int)$log->id ?></td>
                                <td>
                                    <strong style="color:var(--dark);"><?= encode($log->recipient) ?></strong>
                                </td>
                                <td>
                                    <div style="font-weight:600; color:var(--dark);">
                                        <?= encode($log->subject) ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="role-pill pill-member" style="font-size:10px;">
                                        <?= encode($log->type) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($log->status === 'Sent'): ?>
                                        <span class="role-pill" style="background:#dcfce7; color:#15803d; font-size:10px;">Sent</span>
                                    <?php else: ?>
                                        <span class="role-pill" style="background:#fee2e2; color:#dc2626; font-size:10px;">Failed</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-size:12px;">
                                        <?= $log->admin_name ? encode($log->admin_name) : 'System' ?>
                                    </span>
                                </td>
                                <td>
                                    <span style="font-size:12px; color:var(--gray);">
                                        <?= encode(date('d M Y, h:i A', strtotime($log->sent_at))) ?>
                                    </span>
                                </td>
                                <td style="text-align:right;">
                                    <?php
                                    $view_params = $_GET;
                                    $view_params['id'] = (int)$log->id;
                                    ?>
                                    <a href="<?= base_url('admin/email_log.php?' . http_build_query($view_params)) ?>" class="btn btn-outline-sm">
                                        View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div style="display:flex; justify-content:center; gap:6px; margin-top:24px;">
            <?php $params = $_GET; ?>
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <?php $params['page'] = $i; ?>
                <a href="<?= base_url('admin/email_log.php?' . http_build_query($params)) ?>" 
                   class="btn <?= $i === $page ? 'btn-primary-sm' : 'btn-outline-sm' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../_foot.php'; ?>
