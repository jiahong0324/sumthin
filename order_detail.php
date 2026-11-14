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

    if ($o->status === 'Processing' || $o->status === 'Pending') {
        try {
            $db->beginTransaction();

            // 1. Mark order as Cancelled
            $up = $db->prepare("UPDATE `order` SET `status` = 'Cancelled', `payment_status` = 'Refunded' WHERE `id` = ?");
            $up->execute([$order_id]);

            // 2. Restore inventory quantities back to products
            $items_stmt = $db->prepare('SELECT `product_id`, `quantity` FROM `order_item` WHERE `order_id` = ?');
            $items_stmt->execute([$order_id]);
            $cancel_items = $items_stmt->fetchAll();

            $restore_stmt = $db->prepare('UPDATE `product` SET `stock` = `stock` + ? WHERE `id` = ?');
            foreach ($cancel_items as $ci) {
                $restore_stmt->execute([$ci->quantity, $ci->product_id]);
            }

            // 3. Log audit notification
            log_email($_SESSION['user']->email, "Order Cancelled - {$o->order_no}", "Your order {$o->order_no} has been cancelled and refunded.");

            $db->commit();

            set_flash('info', "Order {$o->order_no} has been cancelled successfully. Your payment will be refunded.");
            redirect(base_url('order_detail.php?id=' . $order_id));
        } catch (Exception $e) {
            $db->rollBack();
            set_flash('error', 'Cancellation failed: ' . $e->getMessage());
        }
    } else {
        set_flash('warning', 'Orders that have already been shipped or delivered cannot be cancelled.');
    }
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

<div class="container" style="max-width: 900px;">
    <!-- Top Action Nav -->
    <div style="margin-bottom: 24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <a href="<?= base_url('orders.php') ?>" class="form-link">&larr; Back to Orders</a>
            <h1 style="font-size:26px; font-weight:800; color:var(--dark); margin-top:6px;">Order #<?= encode($o->order_no) ?></h1>
            <span style="font-size:13px; color:var(--gray);">Placed on <?= encode(date('d F Y, h:i A', strtotime($o->created_at))) ?></span>
        </div>
        <div style="display:flex; gap:10px; align-items:center;">
            <?php if ($o->status === 'Processing' || $o->status === 'Pending'): ?>
                <form action="<?= base_url('order_detail.php?id=' . $order_id) ?>" method="POST" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cancel">
                    <button type="submit" class="btn btn-outline-sm" style="color:var(--error); border-color:#fca5a5;" data-confirm="Cancel this order? Stock will be restored and payment refunded.">
                        Cancel Order
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Status Banner -->
    <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:20px; margin-bottom:24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; box-shadow:var(--shadow-sm);">
        <div>
            <span style="font-size:12px; font-weight:700; color:var(--gray); text-transform:uppercase;">Fulfillment Status</span>
            <div style="margin-top:4px;">
                <span class="role-pill pill-member" style="font-size:13px; padding:4px 12px;"><?= encode($o->status) ?></span>
                <span class="role-pill" style="background:#dcfce7; color:#15803d; font-size:13px; padding:4px 12px; margin-left:6px;"><?= encode($o->payment_status) ?></span>
            </div>
        </div>
        <?php if ($o->tracking_no): ?>
            <div>
                <span style="font-size:12px; font-weight:700; color:var(--gray); text-transform:uppercase;">Consignment Tracking</span>
                <div style="font-size:14px; font-weight:700; color:var(--dark); margin-top:4px;">
                    <code><?= encode($o->tracking_no) ?></code>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Details Grid -->
    <div style="display:grid; grid-template-columns: 1.8fr 1.2fr; gap:28px; align-items:flex-start; margin-bottom:32px;">
        <!-- Purchased Items List -->
        <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:24px; box-shadow:var(--shadow-sm);">
            <h2 style="font-size:16px; font-weight:800; color:var(--dark); margin-bottom:16px; border-bottom:1px solid var(--border); padding-bottom:8px;">
                Items in This Shipment
            </h2>

            <div style="display:flex; flex-direction:column; gap:16px;">
                <?php foreach ($items as $item): ?>
                    <div style="display:flex; gap:14px; align-items:center; border-bottom:1px solid #f1f5f9; padding-bottom:14px;">
                        <div style="width:60px; height:60px; background:#0b0f19; border-radius:6px; overflow:hidden; flex-shrink:0; display:flex; align-items:center; justify-content:center; border:1px solid #1e293b;">
                            <img src="<?= base_url('uploads/products/' . encode($item->photo_url)) ?>" alt="<?= encode($item->name) ?>" style="max-width:100%; max-height:100%; object-fit:contain;">
                        </div>
                        <div style="flex:1;">
                            <h3 style="font-size:14px; font-weight:700; color:var(--dark); margin-bottom:2px;">
                                <a href="<?= base_url('product.php?id=' . (int)$item->product_id) ?>" style="text-decoration:none; color:inherit;">
                                    <?= encode($item->name) ?>
                                </a>
                            </h3>
                            <div style="font-size:12px; color:var(--gray);">
                                Qty: <?= (int)$item->quantity ?> &times; RM <?= number_format((float)$item->price, 2) ?>
                            </div>
                        </div>
                        <div style="font-weight:800; color:var(--dark); font-size:15px;">
                            RM <?= number_format((float)$item->subtotal, 2) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Shipping Destination & Financial Totals -->
        <div style="display:flex; flex-direction:column; gap:24px;">
            <!-- Shipping Card -->
            <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:20px; box-shadow:var(--shadow-sm);">
                <h3 style="font-size:14px; font-weight:700; color:var(--dark-muted); text-transform:uppercase; margin-bottom:10px;">
                    Delivery Address
                </h3>
                <?php if ($addr): ?>
                    <div style="font-size:14px; line-height:1.6; color:var(--dark);">
                        <strong><?= encode($addr->recipient_name) ?></strong><br>
                        Phone: <?= encode($addr->phone) ?><br>
                        <?= encode($addr->address_line1) ?><?= $addr->address_line2 ? ', ' . encode($addr->address_line2) : '' ?><br>
                        <?= encode($addr->postal_code) ?> <?= encode($addr->city) ?>, <?= encode($addr->state) ?>
                    </div>
                <?php else: ?>
                    <p style="font-size:13px; color:var(--gray);">Standard express ground delivery.</p>
                <?php endif; ?>
            </div>

            <!-- Totals Card -->
            <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:20px; box-shadow:var(--shadow-sm);">
                <h3 style="font-size:14px; font-weight:700; color:var(--dark-muted); text-transform:uppercase; margin-bottom:12px;">
                    Payment Summary
                </h3>

                <div style="display:flex; flex-direction:column; gap:8px; font-size:14px; border-bottom:1px solid var(--border); padding-bottom:12px; margin-bottom:12px;">
                    <div style="display:flex; justify-content:space-between;">
                        <span style="color:var(--gray);">Items Subtotal:</span>
                        <strong>RM <?= number_format((float)$o->total_amount, 2) ?></strong>
                    </div>

                    <?php if ($o->discount_amount > 0): ?>
                        <div style="display:flex; justify-content:space-between; color:var(--success);">
                            <span>Voucher Discount:</span>
                            <strong>- RM <?= number_format((float)$o->discount_amount, 2) ?></strong>
                        </div>
                    <?php endif; ?>

                    <div style="display:flex; justify-content:space-between;">
                        <span style="color:var(--gray);">Shipping Fee:</span>
                        <span><?= ($o->total_amount >= 150) ? '<strong style="color:var(--success);">FREE</strong>' : 'RM 15.00' ?></span>
                    </div>
                </div>

                <div style="display:flex; justify-content:space-between; align-items:baseline; font-size:18px; font-weight:900;">
                    <span>Total Amount:</span>
                    <span style="color:var(--primary);">RM <?= number_format((float)$o->final_amount, 2) ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/_foot.php'; ?>
