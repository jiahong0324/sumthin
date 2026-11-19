<?php
// ============================================================================
// File: admin/vouchers.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Voucher Maintenance (Admin List, Search, Filter & Management)
// Description: Manage promotional discount vouchers, status indicators, and
//              safe deactivation / deletion safeguards.
// ============================================================================
require_once __DIR__ . '/../_base.php';

// Enforce Admin authorization
auth('Admin');

// Read search, filter and pagination parameters
$q           = trim(req('q'));
$status      = req('status', 'all');
$page        = max(1, (int)req('page', 1));
$limit       = 10;
$offset      = ($page - 1) * $limit;

// Build dynamic WHERE clause
$where_clauses = ["1=1"];
$params        = [];

if ($q !== '') {
    $where_clauses[] = "code LIKE ?";
    $params[]        = "%$q%";
}

if ($status === 'active') {
    $where_clauses[] = "is_active = 1 AND expires_at > NOW() AND used_count < usage_limit";
} elseif ($status === 'expired') {
    $where_clauses[] = "expires_at <= NOW()";
} elseif ($status === 'used_up') {
    $where_clauses[] = "used_count >= usage_limit";
} elseif ($status === 'inactive') {
    $where_clauses[] = "is_active = 0";
}

$where_sql = implode(' AND ', $where_clauses);

// Count total matching vouchers for pagination
$count_stmt = $db->prepare("SELECT COUNT(*) FROM `voucher` WHERE $where_sql");
$count_stmt->execute($params);
$total_rows  = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $limit));

// Fetch paginated records
$stmt = $db->prepare("
    SELECT * FROM `voucher`
    WHERE $where_sql
    ORDER BY id DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$vouchers = $stmt->fetchAll();

$title = 'Voucher Maintenance';
require_once __DIR__ . '/../_head.php';
?>

<div class="container">
    <!-- Top Action Bar -->
    <div class="admin-header-panel">
        <div>
            <h1 style="font-size:24px; font-weight:800; color:var(--dark);">Voucher Maintenance</h1>
            <p style="font-size:14px; color:var(--gray); margin-top:4px;">
                Manage customer discount codes, percentage and fixed savings, limits, and redemptions.
            </p>
        </div>
        <div>
            <a href="<?= base_url('admin/voucher_add.php') ?>" class="btn btn-primary" style="height:40px; display:inline-flex; align-items:center; gap:6px;">
                + Create New Voucher
            </a>
        </div>
    </div>

    <!-- Search & Filter Controls -->
    <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:20px; margin-bottom:24px;">
        <form action="<?= base_url('admin/vouchers.php') ?>" method="GET" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
            <!-- Keyword Search -->
            <div style="flex:2; min-width:200px;">
                <label for="q" class="form-label">Search Voucher Code</label>
                <input type="text" name="q" id="q" value="<?= encode($q) ?>" placeholder="e.g. SUMTHIN10, TECHSAVE..." class="form-control">
            </div>

            <!-- Status Filter -->
            <div style="flex:1; min-width:160px;">
                <label for="status" class="form-label">Status Filter</label>
                <select name="status" id="status" class="form-select">
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All Vouchers</option>
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="expired" <?= $status === 'expired' ? 'selected' : '' ?>>Expired</option>
                    <option value="used_up" <?= $status === 'used_up' ? 'selected' : '' ?>>Used Up</option>
                    <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>

            <!-- Buttons -->
            <div style="display:flex; gap:8px;">
                <button type="submit" class="btn btn-primary-sm" style="height:38px;">Filter</button>
                <a href="<?= base_url('admin/vouchers.php') ?>" class="btn btn-outline-sm" style="height:38px; display:inline-flex; align-items:center;">Reset</a>
            </div>
        </form>
    </div>

    <!-- Results Count Bar -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; font-size:14px; color:var(--gray);">
        <span>Showing <strong><?= count($vouchers) ?></strong> of <strong><?= $total_rows ?></strong> matching voucher(s)</span>
        <span>Page <strong><?= $page ?></strong> of <strong><?= $total_pages ?></strong></span>
    </div>

    <!-- Vouchers Table -->
    <div class="table-responsive">
        <table class="table-custom">
            <thead>
                <tr>
                    <th>Voucher Code</th>
                    <th>Discount</th>
                    <th>Min Spend</th>
                    <th>Max Discount</th>
                    <th>Usage / Limit</th>
                    <th>Expires At</th>
                    <th>Status</th>
                    <th style="text-align:right; width:200px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($vouchers)): ?>
                    <tr>
                        <td colspan="8" style="text-align:center; padding:40px; color:var(--gray);">
                            No discount vouchers found matching your search criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($vouchers as $v): ?>
                        <?php
                        $now = time();
                        $expiry_time = strtotime($v->expires_at);
                        $is_expired  = ($expiry_time <= $now);
                        $is_used_up  = ($v->used_count >= $v->usage_limit);

                        // Determine status
                        if (!$v->is_active) {
                            $badge_class = 'pill-admin';
                            $badge_style = 'background:#fee2e2; color:#b91c1c;';
                            $badge_text  = 'Inactive';
                        } elseif ($is_expired) {
                            $badge_class = 'pill-member';
                            $badge_style = 'background:#f1f5f9; color:#64748b;';
                            $badge_text  = 'Expired';
                        } elseif ($is_used_up) {
                            $badge_class = 'pill-member';
                            $badge_style = 'background:#fef3c7; color:#b45309;';
                            $badge_text  = 'Used up';
                        } else {
                            $badge_class = 'pill-member';
                            $badge_style = 'background:#dcfce7; color:#15803d;';
                            $badge_text  = 'Active';
                        }
                        ?>
                        <tr>
                            <!-- Code -->
                            <td>
                                <span style="font-family:monospace; font-size:14px; font-weight:800; color:var(--primary); background:rgba(37,99,235,0.08); padding:3px 8px; border-radius:4px;">
                                    <?= encode($v->code) ?>
                                </span>
                            </td>

                            <!-- Discount Value & Type -->
                            <td style="font-weight:600; color:var(--dark);">
                                <?php if ($v->discount_type === 'percentage'): ?>
                                    <?= number_format((float)$v->discount_value, 0) ?>% Off
                                <?php else: ?>
                                    RM <?= number_format((float)$v->discount_value, 2) ?> Off
                                <?php endif; ?>
                            </td>

                            <!-- Min Spend -->
                            <td>
                                <?= (float)$v->min_spend > 0 ? 'RM ' . number_format((float)$v->min_spend, 2) : '<span style="color:var(--gray);">No Min</span>' ?>
                            </td>

                            <!-- Max Discount -->
                            <td>
                                <?php if ($v->discount_type === 'percentage' && $v->max_discount): ?>
                                    RM <?= number_format((float)$v->max_discount, 2) ?>
                                <?php else: ?>
                                    <span style="color:var(--gray);">&mdash;</span>
                                <?php endif; ?>
                            </td>

                            <!-- Usage count / limit -->
                            <td>
                                <strong><?= (int)$v->used_count ?></strong> / <?= (int)$v->usage_limit ?>
                                <div style="font-size:11px; color:var(--gray);">
                                    <?= round(((int)$v->used_count / max(1, (int)$v->usage_limit)) * 100) ?>% redeemed
                                </div>
                            </td>

                            <!-- Expiration -->
                            <td>
                                <div style="<?= $is_expired ? 'color:var(--error); font-weight:600;' : '' ?>">
                                    <?= date('d M Y, h:i A', $expiry_time) ?>
                                </div>
                                <?php if ($is_expired): ?>
                                    <span style="font-size:11px; color:var(--error);">(Expired)</span>
                                <?php endif; ?>
                            </td>

                            <!-- Status Badge -->
                            <td>
                                <span class="role-pill <?= $badge_class ?>" style="<?= $badge_style ?>">
                                    <?= $badge_text ?>
                                </span>
                            </td>

                            <!-- Action Buttons -->
                            <td style="text-align:right;">
                                <div style="display:inline-flex; gap:6px;">
                                    <a href="<?= base_url('admin/voucher_edit.php?id=' . (int)$v->id) ?>" class="btn btn-outline-sm" title="Edit Voucher">
                                        Edit
                                    </a>

                                    <!-- Quick Toggle Active Status -->
                                    <form action="<?= base_url('admin/voucher_toggle.php') ?>" method="POST" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int)$v->id ?>">
                                        <button type="submit" class="btn btn-outline-sm" title="<?= $v->is_active ? 'Deactivate' : 'Activate' ?>">
                                            <?= $v->is_active ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                    </form>

                                    <!-- Delete Button (Only permitted if used_count == 0) -->
                                    <?php if ($v->used_count == 0): ?>
                                        <form action="<?= base_url('admin/voucher_delete.php') ?>" method="POST" style="display:inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= (int)$v->id ?>">
                                            <button type="submit" class="btn btn-outline-sm" style="color:var(--error); border-color:#fca5a5;" data-confirm="Delete voucher '<?= encode($v->code) ?>'? This cannot be undone.">
                                                Delete
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-outline-sm" style="opacity:0.4; cursor:not-allowed;" title="Cannot delete: Voucher has been used by customers. You may deactivate it instead.">
                                            Delete
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div style="display:flex; justify-content:center; gap:6px; margin-top:24px;">
            <?php
            $query_params = $_GET;
            ?>
            <?php if ($page > 1): ?>
                <?php $query_params['page'] = $page - 1; ?>
                <a href="<?= base_url('admin/vouchers.php?' . http_build_query($query_params)) ?>" class="btn btn-outline-sm">&laquo; Previous</a>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <?php $query_params['page'] = $i; ?>
                <a href="<?= base_url('admin/vouchers.php?' . http_build_query($query_params)) ?>" 
                   class="btn <?= $i === $page ? 'btn-primary-sm' : 'btn-outline-sm' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>

            <?php if ($page < $total_pages): ?>
                <?php $query_params['page'] = $page + 1; ?>
                <a href="<?= base_url('admin/vouchers.php?' . http_build_query($query_params)) ?>" class="btn btn-outline-sm">Next &raquo;</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../_foot.php'; ?>
