<?php
// ============================================================================
// File: admin/order_detail.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Order Maintenance (Admin Order Inspection & Status Dispatch)
// Description: Allows administrators to inspect customer order items, shipping
//              address, update fulfillment status, and assign courier tracking.
// ============================================================================
require_once __DIR__ . '/../_base.php';

auth('Admin');

$id = (int)req('id');
if ($id <= 0) {
    set_flash('error', 'Invalid order ID.');
    redirect(base_url('admin/orders.php'));
}

// Fetch order with customer details
$stmt = $db->prepare('
    SELECT o.*, u.name AS customer_name, u.email AS customer_email, u.phone AS customer_phone
    FROM `order` o
    JOIN `user` u ON o.user_id = u.id
    WHERE o.id = ?
');
$stmt->execute([$id]);
$o = $stmt->fetch();

if (!$o) {
    set_flash('error', 'Order not found.');
    redirect(base_url('admin/orders.php'));
}

// Handle Status & Tracking Number Update
if (is_post() && req('action') === 'update_status') {
    verify_csrf();

    $new_status         = req('status');
    $new_payment_status = req('payment_status');
    $tracking_no        = req('tracking_no');

    $allowed_statuses = ['Pending', 'Processing', 'Shipped', 'Delivered', 'Cancelled'];
    $allowed_payments = ['Unpaid', 'Paid', 'Refunded'];

    if (in_array($new_status, $allowed_statuses, true) && in_array($new_payment_status, $allowed_payments, true)) {
        $up = $db->prepare('
            UPDATE `order` 
            SET `status` = ?, `payment_status` = ?, `tracking_no` = ? 
            WHERE `id` = ?
        ');
        $up->execute([$new_status, $new_payment_status, $tracking_no ?: null, $id]);

        // Log notification email to customer
        $body = "Dear {$o->customer_name},\n\nYour sumthin order {$o->order_no} status has been updated to: {$new_status}.\n";
        if ($tracking_no) {
            $body .= "Consignment Tracking Number: {$tracking_no}\n";
        }
        $body .= "\nThank you for choosing sumthin.";
        log_email($o->customer_email, "Order Status Update - {$o->order_no}", $body);

        set_flash('success', "Order #{$o->order_no} updated successfully! Status: {$new_status}");
        redirect(base_url('admin/order_detail.php?id=' . $id));
    }
}

// Fetch line items
$items_stmt = $db->prepare("
    SELECT oi.*, p.name, c.name AS category_name,
           COALESCE(pp.photo_url, 'default_product.png') AS photo_url
    FROM `order_item` oi
    JOIN `product` p ON oi.product_id = p.id
    JOIN `category` c ON p.category_id = c.id
    LEFT JOIN `product_photo` pp ON p.id = pp.product_id AND pp.is_primary = 1
    WHERE oi.order_id = ?
");
$items_stmt->execute([$id]);
$items = $items_stmt->fetchAll();

// Fetch shipping address
$addr = null;
if ($o->address_id) {
    $a_stmt = $db->prepare('SELECT * FROM `address` WHERE `id` = ?');
    $a_stmt->execute([$o->address_id]);
    $addr = $a_stmt->fetch();
}

$title = 'Manage Order: ' . $o->order_no;
require_once __DIR__ . '/../_head.php';
?>

<div class="container" style="max-width: 950px;">
    <!-- Top Action Bar -->
    <div style="margin-bottom: 24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <a href="<?= base_url('admin/orders.php') ?>" class="form-link">&larr; Back to Order Directory</a>
            <h1 style="font-size:26px; font-weight:800; color:var(--dark); margin-top:6px;">Manage Order #<?= encode($o->order_no) ?></h1>
            <span style="font-size:13px; color:var(--gray);">Customer: <strong><?= encode($o->customer_name) ?></strong> (<?= encode($o->customer_email) ?>)</span>
        </div>
        <div>
            <span class="role-pill pill-member" style="font-size:13px; padding:6px 14px;">
                Placed: <?= encode(date('d M Y, h:i A', strtotime($o->created_at))) ?>
            </span>
        </div>
    </div>

    <!-- Admin Status Control Panel -->
    <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:24px; margin-bottom:28px; box-shadow:var(--shadow-sm);">
        <h2 style="font-size:16px; font-weight:800; color:var(--dark); margin-bottom:16px; border-bottom:1px solid var(--border); padding-bottom:10px;">
            Update Fulfillment & Tracking
        </h2>

        <form action="<?= base_url('admin/order_detail.php?id=' . $id) ?>" method="POST" style="display:flex; gap:16px; flex-wrap:wrap; align-items:flex-end;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_status">

            <div style="flex:1; min-width:180px;">
                <label for="status" class="form-label">Order Fulfillment Status</label>
                <select name="status" id="status" class="form-select">
                    <option value="Processing" <?= $o->status === 'Processing' ? 'selected' : '' ?>>Processing</option>
                    <option value="Shipped" <?= $o->status === 'Shipped' ? 'selected' : '' ?>>Shipped</option>
                    <option value="Delivered" <?= $o->status === 'Delivered' ? 'selected' : '' ?>>Delivered</option>
                    <option value="Pending" <?= $o->status === 'Pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="Cancelled" <?= $o->status === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>

            <div style="flex:1; min-width:160px;">
                <label for="payment_status" class="form-label">Payment Status</label>
                <select name="payment_status" id="payment_status" class="form-select">
                    <option value="Paid" <?= $o->payment_status === 'Paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="Unpaid" <?= $o->payment_status === 'Unpaid' ? 'selected' : '' ?>>Unpaid</option>
                    <option value="Refunded" <?= $o->payment_status === 'Refunded' ? 'selected' : '' ?>>Refunded</option>
                </select>
            </div>

            <div style="flex:1.5; min-width:220px;">
                <label for="tracking_no" class="form-label">Tracking Number</label>
                <input type="text" name="tracking_no" id="tracking_no" value="<?= encode($o->tracking_no ?? '') ?>" placeholder="e.g. JNT-MY-99881122" class="form-control">
            </div>

            <button type="submit" class="btn btn-primary" style="height:42px;">Save Status Update</button>
        </form>
    </div>

    <!-- Order Items and Customer Info Grid -->
    <div style="display:grid; grid-template-columns: 1.8fr 1.2fr; gap:28px; align-items:flex-start;">
        <!-- Purchased Items -->
        <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:24px; box-shadow:var(--shadow-sm);">
            <h3 style="font-size:16px; font-weight:800; color:var(--dark); margin-bottom:16px; border-bottom:1px solid var(--border); padding-bottom:8px;">
                Order Line Items (<?= count($items) ?>)
            </h3>

            <div style="display:flex; flex-direction:column; gap:16px;">
                <?php foreach ($items as $it): ?>
                    <div style="display:flex; gap:14px; align-items:center; border-bottom:1px solid #f1f5f9; padding-bottom:14px;">
                        <div style="width:55px; height:55px; background:#0b0f19; border-radius:6px; overflow:hidden; flex-shrink:0; display:flex; align-items:center; justify-content:center; border:1px solid #1e293b;">
                            <img src="<?= base_url('uploads/products/' . encode($it->photo_url)) ?>" alt="<?= encode($it->name) ?>" style="max-width:100%; max-height:100%; object-fit:contain;">
                        </div>
                        <div style="flex:1;">
                            <h4 style="font-size:14px; font-weight:700; color:var(--dark); margin-bottom:2px;"><?= encode($it->name) ?></h4>
                            <div style="font-size:12px; color:var(--gray);"><?= (int)$it->quantity ?> &times; RM <?= number_format((float)$it->price, 2) ?></div>
                        </div>
                        <div style="font-weight:800; font-size:15px; color:var(--dark);">
                            RM <?= number_format((float)$it->subtotal, 2) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Customer & Financial Card -->
        <div style="display:flex; flex-direction:column; gap:24px;">
            <!-- Shipping Card -->
            <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:20px; box-shadow:var(--shadow-sm);">
                <h4 style="font-size:13px; font-weight:700; color:var(--dark-muted); text-transform:uppercase; margin-bottom:10px;">
                    Shipping Recipient
                </h4>
                <?php if ($addr): ?>
                    <div style="font-size:14px; line-height:1.6; color:var(--dark);">
                        <strong><?= encode($addr->recipient_name) ?></strong><br>
                        Phone: <?= encode($addr->phone) ?><br>
                        <?= encode($addr->address_line1) ?><?= $addr->address_line2 ? ', ' . encode($addr->address_line2) : '' ?><br>
                        <?= encode($addr->postal_code) ?> <?= encode($addr->city) ?>, <?= encode($addr->state) ?>
                    </div>
                <?php else: ?>
                    <div style="font-size:13px; color:var(--gray);">No explicit address recorded.</div>
                <?php endif; ?>
            </div>

            <!-- Financial Summary -->
            <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:20px; box-shadow:var(--shadow-sm);">
                <h4 style="font-size:13px; font-weight:700; color:var(--dark-muted); text-transform:uppercase; margin-bottom:12px;">
                    Order Financials
                </h4>
                <div style="display:flex; flex-direction:column; gap:8px; font-size:14px; border-bottom:1px solid var(--border); padding-bottom:12px; margin-bottom:12px;">
                    <div style="display:flex; justify-content:space-between;">
                        <span style="color:var(--gray);">Items Total:</span>
                        <strong>RM <?= number_format((float)$o->total_amount, 2) ?></strong>
                    </div>
                    <?php if ($o->discount_amount > 0): ?>
                        <div style="display:flex; justify-content:space-between; color:var(--success);">
                            <span>Discount:</span>
                            <strong>- RM <?= number_format((float)$o->discount_amount, 2) ?></strong>
                        </div>
                    <?php endif; ?>
                    <div style="display:flex; justify-content:space-between;">
                        <span style="color:var(--gray);">Delivery:</span>
                        <span><?= ($o->total_amount >= 150) ? 'RM 0.00' : 'RM 15.00' ?></span>
                    </div>
                </div>
                <div style="display:flex; justify-content:space-between; align-items:baseline; font-size:18px; font-weight:900;">
                    <span>Grand Total:</span>
                    <span style="color:var(--primary);">RM <?= number_format((float)$o->final_amount, 2) ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../_foot.php'; ?>
