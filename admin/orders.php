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
            <h1 class="page-title">Order Fulfillment & Dispatch</h1>
            <p class="page-subtitle mt-4">
                Manage customer gadget orders, assign tracking numbers, and update shipping progress.
            </p>
        </div>
        <span class="role-pill pill-member pill-counter">
            <?= $total_rows ?> Total Order(s)
        </span>
    </div>

    <!-- Search & Filter Controls -->
    <div class="filter-panel mb-24">
        <form action="<?= base_url('admin/orders.php') ?>" method="GET" class="filter-form">
            <div class="filter-group-search">
                <label for="q" class="form-label">Search Orders</label>
                <input type="text" name="q" id="q" value="<?= encode($q) ?>" placeholder="Search by Order #, Customer Name, or Tracking #..." class="form-control">
            </div>

            <div class="min-w-160 flex-1">
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

            <div class="filter-group-actions">
                <button type="submit" class="btn btn-primary-sm btn-filter-submit">Filter Orders</button>
                <a href="<?= base_url('admin/orders.php') ?>" class="btn btn-outline-sm btn-filter-reset">Reset</a>
            </div>
        </form>
    </div>

    <!-- Results Count -->
    <div class="flex-between mb-14 fs-14 text-muted">
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
                    <th class="text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)): ?>
                    <tr><td colspan="8" class="table-empty-cell p-40">No orders found.</td></tr>
                <?php else: ?>
                    <?php foreach ($orders as $o): ?>
                        <tr>
                            <td>
                                <strong><?= encode($o->order_no) ?></strong>
                                <?php if ($o->tracking_no): ?>
                                    <div class="fs-11 text-muted"><code><?= encode($o->tracking_no) ?></code></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-700"><?= encode($o->customer_name) ?></div>
                                <div class="fs-12 text-muted"><?= encode($o->customer_email) ?></div>
                            </td>
                            <td><?= encode(date('d M Y, h:i A', strtotime($o->created_at))) ?></td>
                            <td><?= (int)$o->total_items ?> item(s)</td>
                            <td class="fw-800 text-dark">
                                RM <?= number_format((float)$o->final_amount, 2) ?>
                            </td>
                            <td>
                                <span class="role-pill badge-payment-paid fs-11">
                                    <?= encode($o->payment_status) ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                $status_badge_class = match ($o->status) {
                                    'Delivered'  => 'badge-status-delivered',
                                    'Shipped'    => 'badge-status-shipped',
                                    'Processing' => 'badge-status-processing',
                                    'Cancelled'  => 'badge-status-cancelled',
                                    default      => 'badge-status-pending',
                                };
                                ?>
                                <span class="role-pill <?= $status_badge_class ?> fs-11">
                                    <?= encode($o->status) ?>
                                </span>
                            </td>
                            <td class="text-right">
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
        <div class="flex-center gap-6 mt-24">
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
