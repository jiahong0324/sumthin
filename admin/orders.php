<?php
// ============================================================================
// File: admin/orders.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Order Maintenance (Admin Master Order Directory)
// Description: Comprehensive order management allowing administrators to filter
//              by fulfillment stage, review transactions, and inspect consignments.
// ============================================================================
require_once __DIR__ . '/../_base.php';

auth('Admin');

$q             = req('q');
$status_filter = req('status');
$page          = max(1, (int)req('page', 1));
$limit         = 10;
$offset        = ($page - 1) * $limit;

$where_clauses = ["1=1"];
$params        = [];

if ($q !== '') {
    $where_clauses[] = "(o.order_no LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR o.tracking_no LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $params[] = "%$q%";
}

if ($status_filter !== '' && in_array($status_filter, ['Pending', 'Processing', 'Shipped', 'Delivered', 'Cancelled'], true)) {
    $where_clauses[] = "o.status = ?";
    $params[] = $status_filter;
}

$where_sql = implode(' AND ', $where_clauses);

// Count total orders matching criteria
$count_stmt = $db->prepare("SELECT COUNT(*) FROM `order` o JOIN `user` u ON o.user_id = u.id WHERE $where_sql");
$count_stmt->execute($params);
$total_rows  = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $limit));

// Fetch paginated order records
$sql = "
    SELECT o.*, u.name AS customer_name, u.email AS customer_email,
           COUNT(oi.id) AS total_items
    FROM `order` o
    JOIN `user` u ON o.user_id = u.id
    LEFT JOIN `order_item` oi ON o.id = oi.order_id
    WHERE $where_sql
    GROUP BY o.id
    ORDER BY o.id DESC
    LIMIT $limit OFFSET $offset
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$title = 'Order Maintenance';
require_once __DIR__ . '/../_head.php';
?>

<div class="container">
    <!-- Header -->
    <div class="admin-header-panel">
        <div>
            <h1 style="font-size:24px; font-weight:800; color:var(--dark);">Order Fulfillment & Dispatch</h1>
            <p style="font-size:14px; color:var(--gray); margin-top:4px;">
                Manage customer gadget orders, assign tracking numbers, and update shipping progress.
            </p>
        </div>
        <span class="role-pill pill-member" style="font-size:13px; padding:6px 14px;">
            <?= $total_rows ?> Total Order(s)
        </span>
    </div>

    <!-- Search & Filter Controls -->
    <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:20px; margin-bottom:24px;">
        <form action="<?= base_url('admin/orders.php') ?>" method="GET" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
            <div style="flex:2; min-width:240px;">
                <label for="q" class="form-label">Search Orders</label>
                <input type="text" name="q" id="q" value="<?= encode($q) ?>" placeholder="Search by Order #, Customer Name, or Tracking #..." class="form-control">
            </div>

            <div style="flex:1; min-width:160px;">
                <label for="status" class="form-label">Fulfillment Status</label>
                <select name="status" id="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="Processing" <?= $status_filter === 'Processing' ? 'selected' : '' ?>>Processing</option>
                    <option value="Shipped" <?= $status_filter === 'Shipped' ? 'selected' : '' ?>>Shipped</option>
                    <option value="Delivered" <?= $status_filter === 'Delivered' ? 'selected' : '' ?>>Delivered</option>
                    <option value="Pending" <?= $status_filter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="Cancelled" <?= $status_filter === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>

            <div style="display:flex; gap:8px;">
                <button type="submit" class="btn btn-primary-sm" style="height:38px;">Filter Orders</button>
                <a href="<?= base_url('admin/orders.php') ?>" class="btn btn-outline-sm" style="height:38px; display:inline-flex; align-items:center;">Reset</a>
            </div>
        </form>
    </div>

    <!-- Results Count -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; font-size:14px; color:var(--gray);">
        <span>Showing <strong><?= count($orders) ?></strong> of <strong><?= $total_rows ?></strong> matching order(s)</span>
        <span>Page <strong><?= $page ?></strong> of <strong><?= $total_pages ?></strong></span>
    </div>

    <!-- Orders Table -->
    <div class="table-responsive">
        <table class="table-custom">
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Customer Name</th>
                    <th>Date Placed</th>
                    <th>Items</th>
                    <th>Total Amount</th>
                    <th>Payment</th>
                    <th>Fulfillment</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)): ?>
                    <tr><td colspan="8" style="text-align:center; padding:40px; color:var(--gray);">No orders found.</td></tr>
                <?php else: ?>
                    <?php foreach ($orders as $o): ?>
                        <tr>
                            <td>
                                <strong><?= encode($o->order_no) ?></strong>
                                <?php if ($o->tracking_no): ?>
                                    <div style="font-size:11px; color:var(--gray);"><code><?= encode($o->tracking_no) ?></code></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-weight:700;"><?= encode($o->customer_name) ?></div>
                                <div style="font-size:12px; color:var(--gray);"><?= encode($o->customer_email) ?></div>
                            </td>
                            <td><?= encode(date('d M Y, h:i A', strtotime($o->created_at))) ?></td>
                            <td><?= (int)$o->total_items ?> item(s)</td>
                            <td style="font-weight:800; color:var(--dark);">
                                RM <?= number_format((float)$o->final_amount, 2) ?>
                            </td>
                            <td>
                                <span class="role-pill" style="background:#dcfce7; color:#15803d; font-size:11px;">
                                    <?= encode($o->payment_status) ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                $status_badge_style = match ($o->status) {
                                    'Delivered'  => 'background:#dcfce7; color:#15803d;',
                                    'Shipped'    => 'background:#e0f2fe; color:#0369a1;',
                                    'Processing' => 'background:#fef3c7; color:#b45309;',
                                    'Cancelled'  => 'background:#fee2e2; color:#b91c1c;',
                                    default      => 'background:#f1f5f9; color:#475569;',
                                };
                                ?>
                                <span class="role-pill" style="<?= $status_badge_style ?> font-size:11px;">
                                    <?= encode($o->status) ?>
                                </span>
                            </td>
                            <td style="text-align:right;">
                                <a href="<?= base_url('admin/order_detail.php?id=' . (int)$o->id) ?>" class="btn btn-outline-sm">
                                    Manage &rarr;
                                </a>
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
                <a href="<?= base_url('admin/orders.php?' . http_build_query($params)) ?>" 
                   class="btn <?= $i === $page ? 'btn-primary-sm' : 'btn-outline-sm' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../_foot.php'; ?>
