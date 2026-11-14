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
            <h1 style="font-size:24px; font-weight:800; color:var(--dark);">User & Member Directory</h1>
            <p style="font-size:14px; color:var(--gray); margin-top:4px;">
                Directory of registered customers and staff administrators, account status, and order history.
            </p>
        </div>
        <div style="display:flex; gap:10px; align-items:center;">
            <a href="<?= base_url('admin/member_add.php') ?>" class="btn btn-primary-sm">+ Add New User / Staff</a>
            <span class="role-pill pill-member" style="font-size:13px; padding:6px 14px;">
                <?= $total_rows ?> User(s)
            </span>
        </div>
    </div>

    <!-- Search & Filter Controls -->
    <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:20px; margin-bottom:24px;">
        <form action="<?= base_url('admin/members.php') ?>" method="GET" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
            <div style="flex:2; min-width:240px;">
                <label for="q" class="form-label">Search Users</label>
                <input type="text" name="q" id="q" value="<?= encode($q) ?>" placeholder="Search by name, email, or phone number..." class="form-control">
            </div>

            <div style="flex:1; min-width:140px;">
                <label for="role" class="form-label">Role</label>
                <select name="role" id="role" class="form-select">
                    <option value="">All Roles</option>
                    <option value="Member" <?= $role_filter === 'Member' ? 'selected' : '' ?>>Members Only</option>
                    <option value="Admin" <?= $role_filter === 'Admin' ? 'selected' : '' ?>>Admins & Staff Only</option>
                </select>
            </div>

            <div style="flex:1; min-width:140px;">
                <label for="status" class="form-label">Account Status</label>
                <select name="status" id="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="Active" <?= $status_filter === 'Active' ? 'selected' : '' ?>>Active Only</option>
                    <option value="Blocked" <?= $status_filter === 'Blocked' ? 'selected' : '' ?>>Suspended / Blocked</option>
                </select>
            </div>

            <div style="display:flex; gap:8px;">
                <button type="submit" class="btn btn-primary-sm" style="height:38px;">Filter Directory</button>
                <a href="<?= base_url('admin/members.php') ?>" class="btn btn-outline-sm" style="height:38px; display:inline-flex; align-items:center;">Reset</a>
            </div>
        </form>
    </div>

    <!-- Results Count -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; font-size:14px; color:var(--gray);">
        <span>Showing <strong><?= count($members) ?></strong> of <strong><?= $total_rows ?></strong> member(s)</span>
        <span>Page <strong><?= $page ?></strong> of <strong><?= $total_pages ?></strong></span>
    </div>

    <!-- Members Table -->
    <div class="table-responsive">
        <table class="table-custom">
            <thead>
                <tr>
                    <th style="width:50px;">Avatar</th>
                    <th>Member Details</th>
                    <th>Contact Phone</th>
                    <th>Paid Spend</th>
                    <th>Status</th>
                    <th>Registered Date</th>
                    <th style="text-align:right; width:170px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($members)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center; padding:40px; color:var(--gray);">
                            No members found matching your search.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($members as $m): ?>
                        <tr>
                            <td>
                                <div style="width:40px; height:40px; border-radius:50%; overflow:hidden; background:#e2e8f0; border:1px solid var(--border);">
                                    <img src="<?= base_url('uploads/members/' . encode($m->photo ?? 'default_user.png')) ?>" 
                                         alt="<?= encode($m->name) ?>" 
                                         style="width:100%; height:100%; object-fit:cover;">
                                </div>
                            </td>
                            <td>
                                <div style="font-weight:700; display:flex; align-items:center; gap:6px;">
                                    <a href="<?= base_url('admin/member_detail.php?id=' . (int)$m->id) ?>" style="text-decoration:none; color:inherit;">
                                        <?= encode($m->name) ?>
                                    </a>
                                    <span class="role-pill <?= $m->role === 'Admin' ? 'pill-admin' : 'pill-member' ?>" style="font-size:10px; padding:1px 6px;">
                                        <?= encode($m->role) ?>
                                    </span>
                                </div>
                                <div style="font-size:12px; color:var(--gray);"><?= encode($m->email) ?></div>
                            </td>
                            <td><?= encode($m->phone ?? 'Not provided') ?></td>
                            <td>
                                <strong>RM <?= number_format((float)$m->total_spent, 2) ?></strong>
                                <div style="font-size:11px; color:var(--gray);"><?= (int)$m->total_orders ?> order(s)</div>
                            </td>
                            <td>
                                <?php if ($m->status === 'Active'): ?>
                                    <span class="role-pill" style="background:#dcfce7; color:#15803d;">Active</span>
                                <?php else: ?>
                                    <span class="role-pill pill-admin">Blocked</span>
                                <?php endif; ?>
                            </td>
                            <td><?= encode(date('d M Y', strtotime($m->created_at))) ?></td>
                            <td style="text-align:right;">
                                <div style="display:inline-flex; gap:6px;">
                                    <a href="<?= base_url('admin/member_detail.php?id=' . (int)$m->id) ?>" class="btn btn-outline-sm">
                                        View Details
                                    </a>
                                    <form action="<?= base_url('admin/member_toggle_status.php') ?>" method="POST" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int)$m->id ?>">
                                        <?php if ($m->status === 'Active'): ?>
                                            <button type="submit" class="btn btn-outline-sm" style="color:var(--error); border-color:#fca5a5;" data-confirm="Suspend this member account?">
                                                Block
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" class="btn btn-outline-sm" style="color:var(--success); border-color:#86efac;" data-confirm="Re-activate this member account?">
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
        <div style="display:flex; justify-content:center; gap:6px; margin-top:24px;">
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
