<?php
// ============================================================================
// File: order_detail.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Order Maintenance (Customer Order Detail & Cancellation)
// Description: Full itemized e-receipt, delivery tracking, and self-service
//              order cancellation before dispatch with automatic stock restoral.
// ============================================================================
require_once __DIR__ . '/_base.php';

auth('Member');

$user_id  = $_SESSION['user']->id;
$order_id = (int)req('id');

if ($order_id <= 0) {
    redirect(base_url('orders.php'));
}

// Fetch order verifying ownership
$stmt = $db->prepare('SELECT * FROM `order` WHERE `id` = ? AND `user_id` = ?');
$stmt->execute([$order_id, $user_id]);
$o = $stmt->fetch();

if (!$o) {
    set_flash('error', 'Order not found.');
    redirect(base_url('orders.php'));
}

// Handle Order Cancellation (Allowed while Pending or Processing)
if (is_post() && req('action') === 'cancel') {
    verify_csrf();

    $cancel_res = cancel_order($order_id, $user_id, 'Member');
    if ($cancel_res['ok']) {
        set_flash('info', $cancel_res['message']);
    } else {
        set_flash('error', $cancel_res['message']);
    }
    redirect(base_url('order_detail.php?id=' . $order_id));
}

// Fetch order items with product details and primary photos
$items_stmt = $db->prepare("
    SELECT oi.*, p.name, p.slug, c.name AS category_name,
           COALESCE(pp.photo_url, 'default_product.png') AS photo_url
    FROM `order_item` oi
    JOIN `product` p ON oi.product_id = p.id
    JOIN `category` c ON p.category_id = c.id
    LEFT JOIN `product_photo` pp ON p.id = pp.product_id AND pp.is_primary = 1
    WHERE oi.order_id = ?
");
$items_stmt->execute([$order_id]);
$items = $items_stmt->fetchAll();

// Fetch shipping address
$addr = null;
if ($o->address_id) {
    $a_stmt = $db->prepare('SELECT * FROM `address` WHERE `id` = ?');
    $a_stmt->execute([$o->address_id]);
    $addr = $a_stmt->fetch();
}

$title = 'Receipt: ' . $o->order_no;
require_once __DIR__ . '/_head.php';
?>

<div class="container container-order-detail">
    <!-- Top Action Nav -->
    <div class="flex-between flex-wrap gap-12 mb-24">
        <div>
            <a href="<?= base_url('orders.php') ?>" class="form-link">&larr; Back to Orders</a>
            <h1 class="order-title">Order #<?= encode($o->order_no) ?></h1>
            <span class="fs-13 text-muted">Placed on <?= encode(date('d F Y, h:i A', strtotime($o->created_at))) ?></span>
        </div>
        <div class="d-flex items-center gap-10">
            <?php if ($o->status === 'Processing' || $o->status === 'Pending'): ?>
                <form action="<?= base_url('order_detail.php?id=' . $order_id) ?>" method="POST" class="d-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cancel">
                    <button type="submit" class="btn btn-outline-sm btn-outline-danger" data-confirm="Are you sure you want to cancel this order? Stock will be restored.">
                        Cancel Order
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Status Banner -->
    <div class="order-status-banner">
        <div>
            <span class="status-banner-label">Fulfillment Status</span>
            <div class="mt-4">
                <span class="role-pill pill-member pill-lg"><?= encode($o->status) ?></span>
                <span class="role-pill badge-payment-paid pill-lg ml-6"><?= encode($o->payment_status) ?></span>
            </div>
        </div>
        <?php if ($o->tracking_no): ?>
            <div>
                <span class="status-banner-label">Consignment Tracking</span>
                <div class="fs-14 fw-700 text-dark mt-4">
                    <code><?= encode($o->tracking_no) ?></code>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Details Grid -->
    <div class="order-detail-grid">
        <!-- Purchased Items List -->
        <div class="order-items-card">
            <h2 class="order-section-title">
                Items in This Shipment
            </h2>

            <div class="d-flex flex-col gap-16">
                <?php foreach ($items as $item): ?>
                    <div class="order-item-row">
                        <div class="order-item-thumb">
                            <img src="<?= base_url('uploads/products/' . encode($item->photo_url)) ?>" alt="<?= encode($item->name) ?>" class="order-thumb-img">
                        </div>
                        <div class="flex-1">
                            <h3 class="order-item-name">
                                <a href="<?= base_url('product.php?id=' . (int)$item->product_id) ?>" class="product-title-link">
                                    <?= encode($item->name) ?>
                                </a>
                            </h3>
                            <div class="fs-12 text-muted">
                                Qty: <?= (int)$item->quantity ?> &times; RM <?= number_format((float)$item->price, 2) ?>
                            </div>
                            <?php if ($o->status === 'Delivered'): ?>
                                <?php
                                $rev_chk = $db->prepare('SELECT id FROM review WHERE user_id = ? AND product_id = ? AND order_id = ? LIMIT 1');
                                $rev_chk->execute([$user_id, $item->product_id, $order_id]);
                                $already_reviewed = (bool)$rev_chk->fetch();
                                ?>
                                <div class="mt-6">
                                    <?php if ($already_reviewed): ?>
                                        <span class="badge-reviewed">✓ Reviewed</span>
                                    <?php else: ?>
                                        <a href="<?= base_url('review_add.php?product_id=' . (int)$item->product_id) ?>" class="btn btn-outline-sm btn-write-review">★ Write Review</a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="fs-15 fw-800 text-dark">
                            RM <?= number_format((float)$item->subtotal, 2) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Shipping Destination & Financial Totals -->
        <div class="d-flex flex-col gap-24">
            <!-- Shipping Card -->
            <div class="order-sidebar-card">
                <h3 class="order-sidebar-title mb-10">
                    Delivery Address
                </h3>
                <?php if ($addr): ?>
                    <div class="order-address-text">
                        <strong><?= encode($addr->recipient_name) ?></strong><br>
                        Phone: <?= encode($addr->phone) ?><br>
                        <?= encode($addr->address_line1) ?><?= $addr->address_line2 ? ', ' . encode($addr->address_line2) : '' ?><br>
                        <?= encode($addr->postal_code) ?> <?= encode($addr->city) ?>, <?= encode($addr->state) ?>
                    </div>
                <?php else: ?>
                    <p class="fs-13 text-muted">Standard express ground delivery.</p>
                <?php endif; ?>
            </div>

            <!-- Totals Card -->
            <div class="order-sidebar-card">
                <h3 class="order-sidebar-title mb-12">
                    Payment Summary
                </h3>

                <div class="order-breakdown-list">
                    <div class="flex-between">
                        <span class="text-muted">Payment Method:</span>
                        <strong><?= ($o->payment_method ?? 'pay_later') === 'stripe' ? 'Stripe Gateway' : 'Pay Later' ?></strong>
                    </div>
                    <div class="flex-between">
                        <span class="text-muted">Items Subtotal:</span>
                        <strong>RM <?= number_format((float)$o->total_amount, 2) ?></strong>
                    </div>

                    <?php if ($o->discount_amount > 0): ?>
                        <div class="flex-between text-success">
                            <span>Voucher Discount:</span>
                            <strong>- RM <?= number_format((float)$o->discount_amount, 2) ?></strong>
                        </div>
                    <?php endif; ?>

                    <div class="flex-between">
                        <span class="text-muted">Shipping Fee:</span>
                        <span><?= ($o->total_amount >= 150) ? '<strong class="text-success">FREE</strong>' : 'RM 15.00' ?></span>
                    </div>
                </div>

                <div class="order-total-row">
                    <span>Total Amount:</span>
                    <span class="text-primary">RM <?= number_format((float)$o->final_amount, 2) ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/_foot.php'; ?>
