<?php
// ============================================================================
// File: admin/index.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Security & Admin Dashboard (TASK D6)
// Description: Comprehensive executive control center with summary KPI cards,
//              Chart.js analytics (revenue trend, category share, top 5 selling,
//              status distribution), and live low-stock inventory alert monitoring.
// ============================================================================
require_once __DIR__ . '/../_base.php';

auth('Admin');

$user = current_user();

// ----------------------------------------------------------------------------
// 1. Summary Cards KPI Metrics
// ----------------------------------------------------------------------------
// (a) Today's orders count
$today_orders = (int)$db->query("SELECT COUNT(*) FROM `order` WHERE DATE(`created_at`) = CURDATE()")->fetchColumn();

// (b) Revenue this month from Paid non-cancelled orders
$stmt_rev = $db->query("
    SELECT COALESCE(SUM(`final_amount`), 0) 
    FROM `order` 
    WHERE `payment_status` = 'Paid' 
      AND `status` != 'Cancelled' 
      AND YEAR(`created_at`) = YEAR(CURDATE()) 
      AND MONTH(`created_at`) = MONTH(CURDATE())
");
$revenue_this_month = (float)$stmt_rev->fetchColumn();

// (c) New members registered this month
$stmt_mem = $db->query("
    SELECT COUNT(*) 
    FROM `user` 
    WHERE `role` = 'Member' 
      AND YEAR(`created_at`) = YEAR(CURDATE()) 
      AND MONTH(`created_at`) = MONTH(CURDATE())
");
$new_members_this_month = (int)$stmt_mem->fetchColumn();

// (d) Low-stock count (stock <= low_stock_threshold)
$low_stock_count = (int)$db->query("SELECT COUNT(*) FROM `product` WHERE `stock` <= `low_stock_threshold`")->fetchColumn();

// ----------------------------------------------------------------------------
// 2. Chart Analytics Queries
// ----------------------------------------------------------------------------

// (a) Revenue per day, last 14 days (Line chart)
$days_map = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $days_map[$d] = 0.0;
}

$rev_stmt = $db->query("
    SELECT DATE(`created_at`) AS dt, COALESCE(SUM(`final_amount`), 0) AS rev
    FROM `order`
    WHERE `payment_status` = 'Paid'
      AND `status` != 'Cancelled'
      AND `created_at` >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
    GROUP BY DATE(`created_at`)
");
while ($row = $rev_stmt->fetch()) {
    if (isset($days_map[$row->dt])) {
        $days_map[$row->dt] = (float)$row->rev;
    }
}

$chart_rev_labels = [];
$chart_rev_data   = [];
foreach ($days_map as $dt => $val) {
    $chart_rev_labels[] = date('M j', strtotime($dt));
    $chart_rev_data[]   = $val;
}

// (b) Revenue by category (Doughnut chart)
$cat_stmt = $db->query("
    SELECT c.`name`, COALESCE(SUM(oi.`subtotal`), 0) AS cat_rev
    FROM `category` c
    JOIN `product` p ON c.`id` = p.`category_id`
    JOIN `order_item` oi ON p.`id` = oi.`product_id`
    JOIN `order` o ON oi.`order_id` = o.`id`
    WHERE o.`payment_status` = 'Paid' AND o.`status` != 'Cancelled'
    GROUP BY c.`id`
    HAVING cat_rev > 0
    ORDER BY cat_rev DESC
");
$chart_cat_labels = [];
$chart_cat_data   = [];
while ($row = $cat_stmt->fetch()) {
    $chart_cat_labels[] = $row->name;
    $chart_cat_data[]   = (float)$row->cat_rev;
}

// (c) Top 5 selling products by quantity from non-cancelled orders (Bar chart)
$top5_stmt = $db->query("
    SELECT p.`name`, COALESCE(SUM(oi.`quantity`), 0) AS total_qty
    FROM `product` p
    JOIN `order_item` oi ON p.`id` = oi.`product_id`
    JOIN `order` o ON oi.`order_id` = o.`id`
    WHERE o.`status` != 'Cancelled'
    GROUP BY p.`id`
    ORDER BY total_qty DESC
    LIMIT 5
");
$chart_top_labels = [];
$chart_top_data   = [];
while ($row = $top5_stmt->fetch()) {
    $chart_top_labels[] = mb_strimwidth($row->name, 0, 18, '...');
    $chart_top_data[]   = (int)$row->total_qty;
}

// (d) Orders by status (Bar chart)
$status_order = ['Pending', 'Processing', 'Shipped', 'Delivered', 'Cancelled'];
$status_map   = array_fill_keys($status_order, 0);

$stat_order_stmt = $db->query("SELECT `status`, COUNT(*) AS cnt FROM `order` GROUP BY `status`");
while ($row = $stat_order_stmt->fetch()) {
    if (isset($status_map[$row->status])) {
        $status_map[$row->status] = (int)$row->cnt;
    }
}
$chart_status_labels = array_keys($status_map);
$chart_status_data   = array_values($status_map);

// ----------------------------------------------------------------------------
// 3. Low-Stock Products Table Data (product.stock <= low_stock_threshold)
// ----------------------------------------------------------------------------
$low_stock_stmt = $db->query("
    SELECT p.*, c.`name` AS category_name,
           COALESCE(pp.`photo_url`, 'default_product.png') AS photo_url
    FROM `product` p
    JOIN `category` c ON p.`category_id` = c.`id`
    LEFT JOIN `product_photo` pp ON p.`id` = pp.`product_id` AND pp.`is_primary` = 1
    WHERE p.`stock` <= p.`low_stock_threshold`
    ORDER BY p.`stock` ASC, p.`id` ASC
");
$low_stock_products = $low_stock_stmt->fetchAll();

// Charts bundle for JSON transport
$charts_payload = [
    'revenue_14_days' => [
        'labels' => $chart_rev_labels,
        'data'   => $chart_rev_data,
    ],
    'revenue_by_category' => [
        'labels' => $chart_cat_labels,
        'data'   => $chart_cat_data,
    ],
    'top_5_products' => [
        'labels' => $chart_top_labels,
        'data'   => $chart_top_data,
    ],
    'orders_by_status' => [
        'labels' => $chart_status_labels,
        'data'   => $chart_status_data,
    ],
];

$title = 'Admin Dashboard';
require_once __DIR__ . '/../_head.php';
?>

<div class="container">
    <!-- Top Action Bar -->
    <div class="admin-header-panel">
        <div>
            <h1 class="page-title">Admin Control Center</h1>
            <p class="page-subtitle mt-4">
                Role verified: Signed in as <strong><?= encode($user->name) ?></strong> (<?= encode($user->role) ?>)
            </p>
        </div>
        <div class="d-flex gap-10 items-center flex-wrap">
            <a href="<?= base_url('admin/product_add.php') ?>" class="btn btn-primary-sm">+ Add Product</a>
            <a href="<?= base_url('admin/product_batch.php') ?>" class="btn btn-outline-sm">Batch Operations</a>
            <a href="<?= base_url('admin/email_send.php') ?>" class="btn btn-outline-sm">Email Center</a>
            <a href="<?= base_url('admin/settings.php') ?>" class="btn btn-outline-sm">⚙ Settings</a>
        </div>
    </div>

    <!-- Summary Cards KPI Grid (TASK D6) -->
    <div class="admin-stats-grid">
        <div class="stat-card">
            <div class="stat-label">Today's Orders</div>
            <div class="stat-value"><?= $today_orders ?></div>
            <div class="fs-12 text-muted mt-4">Orders created today</div>
        </div>

        <div class="stat-card">
            <div class="stat-label">Revenue This Month</div>
            <div class="stat-value">RM <?= number_format($revenue_this_month, 2) ?></div>
            <div class="fs-12 text-muted mt-4">Paid, non-cancelled orders</div>
        </div>

        <div class="stat-card">
            <div class="stat-label">New Members This Month</div>
            <div class="stat-value"><?= $new_members_this_month ?></div>
            <div class="fs-12 text-muted mt-4">Registered customer accounts</div>
        </div>

        <a href="<?= base_url('admin/products.php?stock_filter=low') ?>" class="stat-card stat-card-link <?= $low_stock_count > 0 ? 'stat-card-danger' : 'stat-card-success' ?>">
            <div class="stat-label">Low Stock Alerts &rarr;</div>
            <div class="stat-value <?= $low_stock_count > 0 ? 'stat-value-danger' : 'stat-value-success' ?>">
                <?= $low_stock_count ?>
            </div>
            <div class="fs-12 text-muted mt-4">Stock &le; threshold threshold</div>
        </a>
    </div>

    <!-- Charts Analytics Grid (TASK D6: Line, Doughnut, 2x Bar) -->
    <div class="charts-grid">
        <!-- Chart A: Revenue per Day (Last 14 Days) Line Chart -->
        <div class="chart-card">
            <div class="chart-card-header">
                <h3 class="chart-card-title">Daily Revenue (Last 14 Days)</h3>
                <span class="fs-12 text-muted">Paid orders (RM)</span>
            </div>
            <div class="chart-container">
                <canvas id="chart-revenue-14-days"></canvas>
            </div>
        </div>

        <!-- Chart B: Revenue by Category Doughnut Chart -->
        <div class="chart-card">
            <div class="chart-card-header">
                <h3 class="chart-card-title">Revenue Share by Category</h3>
                <span class="fs-12 text-muted">Sales breakdown</span>
            </div>
            <div class="chart-container">
                <?php if (empty($chart_cat_data)): ?>
                    <div class="chart-empty-msg">
                        No paid category sales recorded yet.
                    </div>
                <?php else: ?>
                    <canvas id="chart-revenue-by-category"></canvas>
                <?php endif; ?>
            </div>
        </div>

        <!-- Chart C: Top 5 Selling Products Bar Chart -->
        <div class="chart-card">
            <div class="chart-card-header">
                <h3 class="chart-card-title">Top 5 Selling Products</h3>
                <span class="fs-12 text-muted">Units sold (non-cancelled)</span>
            </div>
            <div class="chart-container">
                <?php if (empty($chart_top_data)): ?>
                    <div class="chart-empty-msg">
                        No product order items recorded yet.
                    </div>
                <?php else: ?>
                    <canvas id="chart-top-5-products"></canvas>
                <?php endif; ?>
            </div>
        </div>

        <!-- Chart D: Orders by Status Bar Chart -->
        <div class="chart-card">
            <div class="chart-card-header">
                <h3 class="chart-card-title">Orders by Fulfillment Status</h3>
                <span class="fs-12 text-muted">Total orders</span>
            </div>
            <div class="chart-container">
                <canvas id="chart-orders-by-status"></canvas>
            </div>
        </div>
    </div>

    <!-- Low-Stock Alert Inventory Table (TASK D6) -->
    <div class="card-panel p-26 mb-36">
        <div class="flex-between mb-16 border-bottom pb-12">
            <div>
                <h2 class="card-title-sm m-0">
                    ⚠ Low-Stock Inventory Monitor (<?= count($low_stock_products) ?>)
                </h2>
                <span class="fs-13 text-muted">
                    Products at or below their configured warning threshold (<code>stock &le; low_stock_threshold</code>).
                </span>
            </div>
            <a href="<?= base_url('admin/products.php?stock_filter=low') ?>" class="btn btn-outline-sm">
                View in Products Catalog &rarr;
            </a>
        </div>

        <?php if (empty($low_stock_products)): ?>
            <div class="inventory-healthy-box">
                ✓ <strong>All inventory levels are healthy!</strong> No products currently at or below their low-stock threshold.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th class="w-60">Photo</th>
                            <th>Product Model</th>
                            <th>Category</th>
                            <th>Price</th>
                            <th>Current Stock</th>
                            <th>Threshold</th>
                            <th class="text-right w-120">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($low_stock_products as $p): ?>
                            <tr>
                                <td>
                                    <div class="product-table-thumb">
                                        <img src="<?= product_photo_url($p->photo_url) ?>" 
                                             alt="<?= encode($p->name) ?>" 
                                             class="order-thumb-img">
                                    </div>
                                </td>
                                <td>
                                    <strong><?= encode($p->name) ?></strong>
                                </td>
                                <td><?= encode($p->category_name) ?></td>
                                <td>RM <?= number_format((float)$p->price, 2) ?></td>
                                <td>
                                    <?php if ((int)$p->stock === 0): ?>
                                        <span class="role-pill badge-stock-out">Out of Stock (0)</span>
                                    <?php else: ?>
                                        <span class="role-pill badge-stock-low"><?= (int)$p->stock ?> units</span>
                                    <?php endif; ?>
                                </td>
                                <td>&le; <?= (int)$p->low_stock_threshold ?></td>
                                <td class="text-right">
                                    <a href="<?= base_url('admin/product_edit.php?id=' . (int)$p->id) ?>" class="btn btn-primary-sm">
                                        Restock
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Embedded Charts Dataset for js/admin.js (No inline scripts) -->
<script type="application/json" id="dashboard-charts-data">
<?= json_encode($charts_payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>
</script>

<?php require_once __DIR__ . '/../_foot.php'; ?>
