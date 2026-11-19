<?php
// ============================================================================
// File: admin/member_detail.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Member Maintenance (Admin Member Detail)
// Description: Detailed member profile inspection with past purchasing history,
//              shipping addresses, and administrative account controls.
// ============================================================================
require_once __DIR__ . '/../_base.php';

auth('Admin');

$id = (int)req('id');
if ($id <= 0) {
    set_flash('error', 'Invalid member ID.');
    redirect(base_url('admin/members.php'));
}

// Fetch user (Member or Admin)
$stmt = $db->prepare('SELECT * FROM `user` WHERE `id` = ?');
$stmt->execute([$id]);
$m = $stmt->fetch();

if (!$m) {
    set_flash('error', 'User account not found.');
    redirect(base_url('admin/members.php'));
}

// Fetch member orders
$order_stmt = $db->prepare('
    SELECT o.*, COUNT(oi.id) AS total_items
    FROM `order` o
    LEFT JOIN `order_item` oi ON o.id = oi.order_id
    WHERE o.user_id = ?
    GROUP BY o.id
    ORDER BY o.id DESC
');
$order_stmt->execute([$id]);
$orders = $order_stmt->fetchAll();

// Fetch addresses
$addr_stmt = $db->prepare('SELECT * FROM `address` WHERE `user_id` = ? ORDER BY `is_default` DESC');
$addr_stmt->execute([$id]);
$addresses = $addr_stmt->fetchAll();

$title = 'Member: ' . $m->name;
require_once __DIR__ . '/../_head.php';
?>

<div class="container container-history">
    <!-- Breadcrumb & Top Bar -->
    <div class="flex-between flex-wrap gap-12 mb-24">
        <div>
            <a href="<?= base_url('admin/members.php') ?>" class="form-link">&larr; Back to Member Directory</a>
            <h1 class="page-title mt-6"><?= encode($m->name) ?></h1>
            <span class="fs-13 text-muted"><?= encode($m->email) ?></span>
        </div>
        <div class="d-flex gap-8 items-center">
            <a href="<?= base_url('admin/member_edit.php?id=' . (int)$m->id) ?>" class="btn btn-outline-sm">
                ✏ Edit Member
            </a>
            <a href="<?= base_url('admin/email_send.php?member_id=' . (int)$m->id) ?>" class="btn btn-primary-sm">
                ✉ Send Email
            </a>
            <form action="<?= base_url('admin/member_toggle_status.php') ?>" method="POST" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$m->id ?>">
                <?php if ($m->status === 'Active'): ?>
                    <button type="submit" class="btn btn-outline-sm btn-outline-danger" data-confirm="Suspend this member account?">
                        Suspend Account
                    </button>
                <?php else: ?>
                    <button type="submit" class="btn btn-outline-sm btn-outline-success" data-confirm="Reactivate this member account?">
                        Reactivate Account
                    </button>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Member Profile Overview Card -->
    <div class="card-panel p-28 mb-30 admin-member-detail-grid">
        <div class="text-center">
            <div class="member-avatar-lg">
                <img src="<?= base_url('uploads/members/' . encode($m->photo ?? 'default_user.png')) ?>" 
                     alt="<?= encode($m->name) ?>" 
                     class="img-cover-full">
            </div>
            <span class="role-pill <?= $m->status === 'Active' ? 'pill-member' : 'pill-admin' ?> fs-12">
                Status: <?= encode($m->status) ?>
            </span>
            <div class="mt-8">
                <?php if (!empty($m->email_verified_at)): ?>
                    <span class="role-pill badge-info fs-11">✓ Verified</span>
                <?php else: ?>
                    <span class="role-pill badge-warning fs-11">⚠ Unverified</span>
                <?php endif; ?>
            </div>
        </div>

        <div>
            <h3 class="card-title-sm fs-16 mb-12 border-bottom pb-8">
                Customer Account Information
            </h3>
            <table class="table-custom fs-13">
                <tr>
                    <td class="fw-700 min-w-160 text-dark-muted">Customer ID</td>
                    <td>#<?= (int)$m->id ?></td>
                </tr>
                <tr>
                    <td class="fw-700 text-dark-muted">Email Verification</td>
                    <td>
                        <?php if (!empty($m->email_verified_at)): ?>
                            <span class="role-pill badge-info fs-11">Verified</span>
                            <span class="fs-12 text-muted ml-6">(Verified on <?= encode(date('d F Y, h:i A', strtotime($m->email_verified_at))) ?>)</span>
                        <?php else: ?>
                            <span class="role-pill badge-warning fs-11">Unverified</span>
                            <span class="fs-12 text-muted ml-6">(Pending user verification)</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td class="fw-700 text-dark-muted">Phone Number</td>
                    <td><?= encode($m->phone ?? 'Not provided') ?></td>
                </tr>
                <tr>
                    <td class="fw-700 text-dark-muted">Registration Date</td>
                    <td><?= encode(date('d F Y, h:i A', strtotime($m->created_at))) ?></td>
                </tr>
                <tr>
                    <td class="fw-700 text-dark-muted">Failed Logins</td>
                    <td>
                        <?= (int)$m->failed_attempts ?> attempt(s)
                        <?php if ($m->locked_until && strtotime($m->locked_until) > time()): ?>
                            <span class="text-danger fw-700"> (Locked until <?= encode($m->locked_until) ?>)</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Order History for this Member -->
    <div class="card-panel p-28">
        <div class="section-title-wrap">
            <h2 class="section-title">Order History (<?= count($orders) ?>)</h2>
            <span class="fs-13 text-muted">Purchases made by <?= encode($m->name) ?></span>
        </div>

        <div class="table-responsive">
            <table class="table-custom">
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Date</th>
                        <th>Items</th>
                        <th>Total Amount</th>
                        <th>Order Status</th>
                        <th>Payment Status</th>
                        <th style="text-align:right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr><td colspan="7" class="table-empty-cell">No orders placed yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($orders as $ord): ?>
                            <tr>
                                <td><strong><?= encode($ord->order_no) ?></strong></td>
                                <td><?= encode(date('d M Y, h:i A', strtotime($ord->created_at))) ?></td>
                                <td><?= (int)$ord->total_items ?> item(s)</td>
                                <td><strong>RM <?= number_format((float)$ord->final_amount, 2) ?></strong></td>
                                <td>
                                    <span class="role-pill pill-member fs-11"><?= encode($ord->status) ?></span>
                                </td>
                                <td>
                                    <span class="role-pill badge-stock-healthy fs-11"><?= encode($ord->payment_status) ?></span>
                                </td>
                                <td style="text-align:right;">
                                    <a href="<?= base_url('admin/order_detail.php?id=' . (int)$ord->id) ?>" class="btn btn-outline-sm">
                                        View Order
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../_foot.php'; ?>
