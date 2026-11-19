<?php
// ============================================================================
// File: orders.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Order Maintenance (Customer Order History)
// Description: Allows logged-in members to track personal past orders, review
//              delivery statuses, inspect tracking numbers, and view receipts.
// ============================================================================
require_once __DIR__ . '/_base.php';

auth('Member');

$user_id = (int)$_SESSION['user']->id;

// Pagination & Filtering settings
$limit = 10;
$page = max(1, (int)req('page', 1));
$offset = ($page - 1) * $limit;

$status_filter = trim((string)req('status'));
$valid_statuses = ['Pending', 'Processing', 'Shipped', 'Delivered', 'Cancelled'];

$where_clauses = ['o.user_id = ?'];
$params = [$user_id];

if ($status_filter !== '' && in_array($status_filter, $valid_statuses, true)) {
    $where_clauses[] = 'o.status = ?';
    $params[] = $status_filter;
}

$where_sql = implode(' AND ', $where_clauses);

// Count total matching orders for pagination
$count_stmt = $db->prepare("SELECT COUNT(*) FROM `order` o WHERE $where_sql");
$count_stmt->execute($params);
$total_rows  = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $limit));

// Adjust page if out of bounds
if ($page > $total_pages && $total_rows > 0) {
    $page = $total_pages;
    $offset = ($page - 1) * $limit;
}

// Fetch paginated member orders with total item counts
$sql = "
    SELECT o.*, COUNT(oi.id) AS total_items
    FROM `order` o
    LEFT JOIN `order_item` oi ON o.id = oi.order_id
    WHERE $where_sql
    GROUP BY o.id
    ORDER BY o.id DESC
    LIMIT $limit OFFSET $offset
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$title = 'My Orders';
require_once __DIR__ . '/_head.php';
?>

<div class="container container-history">
    <!-- Header -->
    <div class="flex-between flex-wrap gap-12 mb-24">
        <div>
            <h1 class="page-header-title">My Order History</h1>
            <p class="page-header-desc">Track shipments, download invoices, and inspect order details.</p>
        </div>
        <span class="role-pill pill-member pill-counter">
            <?= $total_rows ?> Order(s)
        </span>
    </div>

    <!-- Status Filter Control -->
    <div class="filter-panel mb-24">
        <form action="<?= base_url('orders.php') ?>" method="GET" class="filter-form">
            <div class="filter-group-status">
                <label for="status" class="form-label fs-12 fw-700 text-dark">Filter by Status</label>
                <select name="status" id="status" class="form-select">
                    <option value="">All Statuses</option>
                    <?php foreach ($valid_statuses as $st): ?>
                        <option value="<?= encode($st) ?>" <?= $status_filter === $st ? 'selected' : '' ?>><?= encode($st) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <button type="submit" class="btn btn-primary btn-filter">Filter</button>
                <?php if ($status_filter !== ''): ?>
                    <a href="<?= base_url('orders.php') ?>" class="btn btn-outline btn-filter ml-6">Reset</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <?php if (empty($orders)): ?>
        <div class="empty-state-panel">
            <div class="empty-state-icon">📦</div>
            <?php if ($status_filter !== ''): ?>
                <h2 class="empty-state-title">No orders found with status "<?= encode($status_filter) ?>"</h2>
                <p class="empty-state-desc mb-20">Try clearing your status filter to see all your past orders.</p>
                <a href="<?= base_url('orders.php') ?>" class="btn btn-primary">Clear Filter</a>
            <?php else: ?>
                <h2 class="empty-state-title">You haven't placed any orders yet</h2>
                <p class="empty-state-desc mb-20">Discover the latest GaN chargers, custom keyboards, and tech gear.</p>
                <a href="<?= base_url('index.php') ?>" class="btn btn-primary">Start Shopping &rarr;</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="table-responsive shadow-sm">
            <table class="table-custom">
                <thead>
                    <tr>
                        <th>Order Number</th>
                        <th>Order Date</th>
                        <th>Items</th>
                        <th>Total Paid</th>
                        <th>Payment</th>
                        <th>Delivery Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $o): ?>
                        <tr>
                            <td>
                                <strong><?= encode($o->order_no) ?></strong>
                                <?php if ($o->tracking_no): ?>
                                    <div class="fs-11 text-muted">Tracking: <code><?= encode($o->tracking_no) ?></code></div>
                                <?php endif; ?>
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
                                <a href="<?= base_url('order_detail.php?id=' . (int)$o->id) ?>" class="btn btn-outline-sm">
                                    View Receipt
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="flex-center gap-6 mt-24">
                <?php $p_params = $_GET; ?>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php $p_params['page'] = $i; ?>
                    <a href="<?= base_url('orders.php?' . http_build_query($p_params)) ?>" 
                       class="btn <?= $i === $page ? 'btn-primary-sm' : 'btn-outline-sm' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/_foot.php'; ?>
