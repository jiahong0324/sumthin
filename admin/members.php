<?php
// ============================================================================
// File: admin/members.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Member Maintenance (Admin Listing & Searching)
// Description: Master member directory with search, status filtering, lockout
//              monitoring, and administrative account suspension controls.
// ============================================================================
require_once __DIR__ . '/../_base.php';

auth('Admin');

$q             = req('q');
$status_filter = req('status');
$role_filter   = req('role');
$page          = max(1, (int)req('page', 1));
$limit         = 10;
$offset        = ($page - 1) * $limit;

$where_clauses = ["1=1"];
$params        = [];

if ($q !== '') {
    $where_clauses[] = "(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $params[] = "%$q%";
}

if ($role_filter === 'Member' || $role_filter === 'Admin') {
    $where_clauses[] = "u.role = ?";
    $params[] = $role_filter;
}

if ($status_filter === 'Active' || $status_filter === 'Blocked') {
    $where_clauses[] = "u.status = ?";
    $params[] = $status_filter;
}

$where_sql = implode(' AND ', $where_clauses);

// Count matching records
$count_stmt = $db->prepare("SELECT COUNT(*) FROM `user` u WHERE $where_sql");
$count_stmt->execute($params);
$total_rows  = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $limit));

// Fetch paginated member records with total order counts
$sql = "
    SELECT u.*, 
           COUNT(o.id) AS total_orders,
           COALESCE(SUM(o.final_amount), 0) AS total_spent
    FROM `user` u
    LEFT JOIN `order` o ON u.id = o.user_id AND o.payment_status = 'Paid'
    WHERE $where_sql
    GROUP BY u.id
    ORDER BY u.id DESC
    LIMIT $limit OFFSET $offset
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$members = $stmt->fetchAll();

$title = 'Member Maintenance';
require_once __DIR__ . '/../_head.php';
?>

<div class="container">
    <!-- Header -->
    <div class="admin-header-panel">
        <div>
            <h1 class="page-title">User & Member Directory</h1>
            <p class="page-subtitle mt-4">
                Directory of registered customers and staff administrators, account status, and order history.
            </p>
        </div>
        <div class="d-flex gap-10 items-center">
            <a href="<?= base_url('admin/member_add.php') ?>" class="btn btn-primary-sm">+ Add New User / Staff</a>
            <span class="role-pill pill-member pill-counter">
                <?= $total_rows ?> User(s)
            </span>
        </div>
    </div>

    <!-- Search & Filter Controls -->
    <div class="filter-panel p-20 mb-24">
        <form action="<?= base_url('admin/members.php') ?>" method="GET" class="filter-form">
            <div class="flex-2 min-w-240">
                <label for="q" class="form-label">Search Users</label>
                <input type="text" name="q" id="q" value="<?= encode($q) ?>" placeholder="Search by name, email, or phone number..." class="form-control">
            </div>

            <div class="flex-1 min-w-140">
                <label for="role" class="form-label">Role</label>
                <select name="role" id="role" class="form-select">
                    <option value="">All Roles</option>
                    <option value="Member" <?= $role_filter === 'Member' ? 'selected' : '' ?>>Members Only</option>
                    <option value="Admin" <?= $role_filter === 'Admin' ? 'selected' : '' ?>>Admins & Staff Only</option>
                </select>
            </div>

            <div class="flex-1 min-w-140">
                <label for="status" class="form-label">Account Status</label>
                <select name="status" id="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="Active" <?= $status_filter === 'Active' ? 'selected' : '' ?>>Active Only</option>
                    <option value="Blocked" <?= $status_filter === 'Blocked' ? 'selected' : '' ?>>Suspended / Blocked</option>
                </select>
            </div>

            <div class="d-flex gap-8">
                <button type="submit" class="btn btn-primary-sm btn-filter-h">Filter Directory</button>
                <a href="<?= base_url('admin/members.php') ?>" class="btn btn-outline-sm btn-filter-h d-inline-flex items-center">Reset</a>
            </div>
        </form>
    </div>

    <!-- Results Count -->
    <div class="flex-between mb-14 fs-14 text-muted">
        <span>Showing <strong><?= count($members) ?></strong> of <strong><?= $total_rows ?></strong> member(s)</span>
        <span>Page <strong><?= $page ?></strong> of <strong><?= $total_pages ?></strong></span>
    </div>

    <!-- Members Table -->
    <div class="table-responsive">
        <table class="table-custom">
            <thead>
                <tr>
                    <th class="w-50">Avatar</th>
                    <th>Member Details</th>
                    <th>Contact Phone</th>
                    <th>Paid Spend</th>
                    <th>Status</th>
                    <th>Registered Date</th>
                    <th class="text-right min-w-170">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($members)): ?>
                    <tr>
                        <td colspan="7" class="table-empty-cell">
                            No members found matching your search.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($members as $m): ?>
                        <tr>
                            <td>
                                <div class="member-avatar-sm">
                                    <img src="<?= base_url('uploads/members/' . encode($m->photo ?? 'default_user.png')) ?>" 
                                         alt="<?= encode($m->name) ?>" 
                                         class="img-cover-full">
                                </div>
                            </td>
                            <td>
                                <div class="fw-700 d-flex items-center gap-6">
                                    <a href="<?= base_url('admin/member_detail.php?id=' . (int)$m->id) ?>" class="text-decoration-none color-inherit">
                                        <?= encode($m->name) ?>
                                    </a>
                                    <span class="role-pill <?= $m->role === 'Admin' ? 'pill-admin' : 'pill-member' ?> badge-xs">
                                        <?= encode($m->role) ?>
                                    </span>
                                </div>
                                <div class="fs-12 text-muted"><?= encode($m->email) ?></div>
                            </td>
                            <td><?= encode($m->phone ?? 'Not provided') ?></td>
                            <td>
                                <strong>RM <?= number_format((float)$m->total_spent, 2) ?></strong>
                                <div class="fs-11 text-muted"><?= (int)$m->total_orders ?> order(s)</div>
                            </td>
                            <td>
                                <div>
                                    <?php if ($m->status === 'Active'): ?>
                                        <span class="role-pill badge-stock-healthy">Active</span>
                                    <?php else: ?>
                                        <span class="role-pill pill-admin">Blocked</span>
                                    <?php endif; ?>
                                </div>
                                <div class="mt-4">
                                    <?php if (!empty($m->email_verified_at)): ?>
                                        <span class="role-pill badge-info badge-xs" title="Verified at <?= encode($m->email_verified_at) ?>">✓ Verified</span>
                                    <?php else: ?>
                                        <span class="role-pill badge-warning badge-xs">⚠ Unverified</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?= encode(date('d M Y', strtotime($m->created_at))) ?></td>
                            <td class="text-right">
                                <div class="d-inline-flex gap-6">
                                    <a href="<?= base_url('admin/member_detail.php?id=' . (int)$m->id) ?>" class="btn btn-outline-sm">
                                        View Details
                                    </a>
                                    <form action="<?= base_url('admin/member_toggle_status.php') ?>" method="POST" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int)$m->id ?>">
                                        <?php if ($m->status === 'Active'): ?>
                                            <button type="submit" class="btn btn-outline-sm btn-outline-danger" data-confirm="Suspend this member account?">
                                                Block
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" class="btn btn-outline-sm btn-outline-success" data-confirm="Re-activate this member account?">
                                                Unblock
                                            </button>
                                        <?php endif; ?>
                                    </form>
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
        <div class="pagination-wrapper flex-center gap-6 mt-24">
            <?php $params = $_GET; ?>
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <?php $params['page'] = $i; ?>
                <a href="<?= base_url('admin/members.php?' . http_build_query($params)) ?>" 
                   class="btn <?= $i === $page ? 'btn-primary-sm' : 'btn-outline-sm' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../_foot.php'; ?>
