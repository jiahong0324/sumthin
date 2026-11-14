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

<div class="container" style="max-width: 950px;">
    <!-- Breadcrumb & Top Bar -->
    <div style="margin-bottom: 24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <a href="<?= base_url('admin/members.php') ?>" class="form-link">&larr; Back to Member Directory</a>
            <h1 style="font-size:26px; font-weight:800; color:var(--dark); margin-top:6px;"><?= encode($m->name) ?></h1>
            <span style="font-size:13px; color:var(--gray);"><?= encode($m->email) ?></span>
        </div>
        <div>
            <form action="<?= base_url('admin/member_toggle_status.php') ?>" method="POST" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$m->id ?>">
                <?php if ($m->status === 'Active'): ?>
                    <button type="submit" class="btn btn-outline-sm" style="color:var(--error); border-color:#fca5a5;" data-confirm="Suspend this member account?">
                        Suspend Account
                    </button>
                <?php else: ?>
                    <button type="submit" class="btn btn-outline-sm" style="color:var(--success); border-color:#86efac;" data-confirm="Reactivate this member account?">
                        Reactivate Account
                    </button>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Member Profile Overview Card -->
    <div style="display:grid; grid-template-columns: 180px 1fr; gap:24px; background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:28px; margin-bottom:30px; box-shadow:var(--shadow-sm);">
        <div style="text-align:center;">
            <div style="width:130px; height:130px; border-radius:50%; overflow:hidden; border:3px solid var(--primary); margin:0 auto 12px auto; background:#f1f5f9;">
                <img src="<?= base_url('uploads/members/' . encode($m->photo ?? 'default_user.png')) ?>" 
                     alt="<?= encode($m->name) ?>" 
                     style="width:100%; height:100%; object-fit:cover;">
            </div>
            <span class="role-pill <?= $m->status === 'Active' ? 'pill-member' : 'pill-admin' ?>" style="font-size:12px;">
                Status: <?= encode($m->status) ?>
            </span>
        </div>

        <div>
            <h3 style="font-size:16px; font-weight:700; color:var(--dark); margin-bottom:12px; border-bottom:1px solid var(--border); padding-bottom:8px;">
                Customer Account Information
            </h3>
            <table class="table-custom" style="font-size:13px;">
                <tr>
                    <td style="font-weight:700; width:160px; color:var(--dark-muted);">Customer ID</td>
                    <td>#<?= (int)$m->id ?></td>
                </tr>
                <tr>
                    <td style="font-weight:700; color:var(--dark-muted);">Phone Number</td>
                    <td><?= encode($m->phone ?? 'Not provided') ?></td>
                </tr>
                <tr>
                    <td style="font-weight:700; color:var(--dark-muted);">Registration Date</td>
                    <td><?= encode(date('d F Y, h:i A', strtotime($m->created_at))) ?></td>
                </tr>
                <tr>
                    <td style="font-weight:700; color:var(--dark-muted);">Failed Logins</td>
                    <td>
                        <?= (int)$m->failed_attempts ?> attempt(s)
                        <?php if ($m->locked_until && strtotime($m->locked_until) > time()): ?>
                            <span style="color:var(--error); font-weight:700;"> (Locked until <?= encode($m->locked_until) ?>)</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Order History for this Member -->
    <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:28px; box-shadow:var(--shadow-sm);">
        <div class="section-title-wrap">
            <h2 class="section-title">Order History (<?= count($orders) ?>)</h2>
            <span style="font-size:13px; color:var(--gray);">Purchases made by <?= encode($m->name) ?></span>
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
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr><td colspan="6" style="text-align:center; padding:30px; color:var(--gray);">No orders placed yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($orders as $ord): ?>
                            <tr>
                                <td><strong><?= encode($ord->order_no) ?></strong></td>
                                <td><?= encode(date('d M Y, h:i A', strtotime($ord->created_at))) ?></td>
                                <td><?= (int)$ord->total_items ?> item(s)</td>
                                <td><strong>RM <?= number_format((float)$ord->final_amount, 2) ?></strong></td>
                                <td>
                                    <span class="role-pill pill-member" style="font-size:11px;"><?= encode($ord->status) ?></span>
                                </td>
                                <td>
                                    <span class="role-pill" style="background:#dcfce7; color:#15803d; font-size:11px;"><?= encode($ord->payment_status) ?></span>
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
