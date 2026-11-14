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

$user_id = $_SESSION['user']->id;

// Fetch member orders with total item counts
$stmt = $db->prepare('
    SELECT o.*, COUNT(oi.id) AS total_items
    FROM `order` o
    LEFT JOIN `order_item` oi ON o.id = oi.order_id
    WHERE o.user_id = ?
    GROUP BY o.id
    ORDER BY o.id DESC
');
$stmt->execute([$user_id]);
$orders = $stmt->fetchAll();

$title = 'My Orders';
require_once __DIR__ . '/_head.php';
?>

<div class="container" style="max-width: 950px;">
    <!-- Header -->
    <div style="margin-bottom: 24px;">
        <h1 style="font-size:28px; font-weight:800; color:var(--dark);">My Order History</h1>
        <p style="font-size:14px; color:var(--gray);">Track shipments, download invoices, and inspect order details.</p>
    </div>

    <?php if (empty($orders)): ?>
        <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius-lg); padding:60px 20px; text-align:center; box-shadow:var(--shadow-sm);">
            <div style="font-size:48px; margin-bottom:12px;">📦</div>
            <h2 style="font-size:20px; font-weight:800; color:var(--dark); margin-bottom:8px;">You haven't placed any orders yet</h2>
            <p style="font-size:14px; color:var(--gray); margin-bottom:20px;">Discover the latest GaN chargers, custom keyboards, and tech gear.</p>
            <a href="<?= base_url('index.php') ?>" class="btn btn-primary">Start Shopping &rarr;</a>
        </div>
    <?php else: ?>
        <div class="table-responsive" style="box-shadow:var(--shadow-sm);">
            <table class="table-custom">
                <thead>
                    <tr>
                        <th>Order Number</th>
                        <th>Order Date</th>
                        <th>Items</th>
                        <th>Total Paid</th>
                        <th>Payment</th>
                        <th>Delivery Status</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $o): ?>
                        <tr>
                            <td>
                                <strong><?= encode($o->order_no) ?></strong>
                                <?php if ($o->tracking_no): ?>
                                    <div style="font-size:11px; color:var(--gray);">Tracking: <code><?= encode($o->tracking_no) ?></code></div>
                                <?php endif; ?>
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
                                $status_badge_class = match ($o->status) {
                                    'Delivered'  => 'background:#dcfce7; color:#15803d;',
                                    'Shipped'    => 'background:#e0f2fe; color:#0369a1;',
                                    'Processing' => 'background:#fef3c7; color:#b45309;',
                                    'Cancelled'  => 'background:#fee2e2; color:#b91c1c;',
                                    default      => 'background:#f1f5f9; color:#475569;',
                                };
                                ?>
                                <span class="role-pill" style="<?= $status_badge_class ?> font-size:11px;">
                                    <?= encode($o->status) ?>
                                </span>
                            </td>
                            <td style="text-align:right;">
                                <a href="<?= base_url('order_detail.php?id=' . (int)$o->id) ?>" class="btn btn-outline-sm">
                                    View Receipt
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/_foot.php'; ?>
