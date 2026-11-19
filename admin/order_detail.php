<?php
// ============================================================================
// File: admin/order_detail.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Order Maintenance (Admin Order Inspection & Status Dispatch)
// Description: Allows administrators to inspect customer order items, shipping
//              address, update fulfillment status via strict transition rules,
//              record payments, and assign courier tracking with validation.
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

// Define valid fulfillment transition matrix (Terminal states have no outgoing transitions)
$status_transitions = [
    'Pending'    => ['Processing', 'Cancelled'],
    'Processing' => ['Shipped', 'Cancelled'],
    'Shipped'    => ['Delivered'],
    'Delivered'  => [],
    'Cancelled'  => [],
];

$allowed_next_statuses = $status_transitions[$o->status] ?? [];
$is_terminal = empty($allowed_next_statuses);

// Handle Status & Tracking Number Update
if (is_post() && req('action') === 'update_status') {
    verify_csrf();

    $new_status         = req('status', $o->status);
    $new_payment_status = req('payment_status', $o->payment_status);
    $tracking_no        = trim((string)req('tracking_no', $o->tracking_no ?? ''));

    // 1. Validate status transition
    if ($new_status !== $o->status) {
        if ($is_terminal) {
            $errors['status'] = "Order is in terminal state '{$o->status}' and cannot be modified.";
        } elseif (!in_array($new_status, $allowed_next_statuses, true)) {
            $errors['status'] = "Invalid status transition from '{$o->status}' to '{$new_status}'. Allowed: " . implode(', ', $allowed_next_statuses) . '.';
        } elseif ($new_status === 'Shipped' && $new_payment_status !== 'Paid') {
            $errors['status'] = 'Cannot move order to Shipped unless payment status is Paid.';
        }
    }

    // 2. Validate tracking number
    if ($new_status === 'Shipped') {
        if ($tracking_no === '') {
            $errors['tracking_no'] = 'Consignment tracking number is required when shipping an order.';
        } elseif (!preg_match('/^[A-Za-z0-9\-]{3,100}$/', $tracking_no)) {
            $errors['tracking_no'] = 'Tracking number must be 3-100 alphanumeric characters or hyphens.';
        }
    } else {
        if (in_array($o->status, ['Shipped', 'Delivered'], true)) {
            // Tracking number is locked/read-only once Shipped or Delivered
            $tracking_no = $o->tracking_no ?? '';
        } elseif ($tracking_no !== '' && !preg_match('/^[A-Za-z0-9\-]{3,100}$/', $tracking_no)) {
            $errors['tracking_no'] = 'Tracking number must be 3-100 alphanumeric characters or hyphens.';
        }
    }

    // 3. Validate payment status transition (Admin can only change Unpaid -> Paid)
    if ($new_payment_status !== $o->payment_status) {
        if ($o->payment_status === 'Unpaid' && $new_payment_status === 'Paid') {
            // Valid transition
        } else {
            $errors['payment_status'] = "Payment status can only be manually changed from 'Unpaid' to 'Paid'.";
        }
    }

    // 4. Execute transition if valid
    if (empty($errors)) {
        if ($new_status === 'Cancelled' && $o->status !== 'Cancelled') {
            // Cancellation workflow with inventory restoral & email dispatch
            $res = cancel_order($id, null, 'Admin');
            if ($res['ok']) {
                set_flash('success', $res['message']);
                redirect(base_url('admin/order_detail.php?id=' . $id));
            } else {
                $errors['status'] = $res['message'];
            }
        } else {
            // Forward progression or payment update
            try {
                $db->beginTransaction();

                $up = $db->prepare('
                    UPDATE `order` 
                    SET `status` = ?, `payment_status` = ?, `tracking_no` = ? 
                    WHERE `id` = ?
                ');
                $up->execute([
                    $new_status,
                    $new_payment_status,
                    $tracking_no !== '' ? $tracking_no : null,
                    $id
                ]);

                // Update payment record if transition was Unpaid -> Paid
                if ($o->payment_status === 'Unpaid' && $new_payment_status === 'Paid') {
                    $up_pay = $db->prepare('UPDATE `payment` SET `status` = "Paid", `paid_at` = NOW() WHERE `order_id` = ? AND `status` = "Pending"');
                    $up_pay->execute([$id]);
                }

                $db->commit();

                // Send email notification on status change
                if ($new_status !== $o->status) {
                    $admin_user_id = (int)($_SESSION['user']->id ?? 0);
                    $email_body = "<p>Dear <strong>" . encode($o->customer_name) . "</strong>,</p>"
                                . "<p>Your <strong>sumthin</strong> order <strong>#" . encode($o->order_no) . "</strong> fulfillment status has been updated to: <strong style='color:#2563eb;'>" . encode($new_status) . "</strong>.</p>";
                    if ($tracking_no !== '') {
                        $email_body .= "<p>Courier Tracking Reference: <code style='background:#f1f5f9; padding:2px 8px; border-radius:4px; font-weight:700;'>" . encode($tracking_no) . "</code></p>";
                    }
                    $email_body .= "<p>You can track your order history and dispatch details in your member portal.</p>"
                                . "<p>Thank you for choosing sumthin.</p>";

                    send_mail(
                        $o->customer_email,
                        $o->customer_name,
                        "Order Status Update - #" . $o->order_no . " (" . $new_status . ")",
                        $email_body,
                        'order',
                        $admin_user_id > 0 ? $admin_user_id : null
                    );
                }

                set_flash('success', "Order #{$o->order_no} status updated to {$new_status} successfully!");
                redirect(base_url('admin/order_detail.php?id=' . $id));
            } catch (Exception $e) {
                $db->rollBack();
                $errors['db'] = app_error_message('Failed to update order status.', $e);
            }
        }
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

<div class="container container-history">
    <!-- Top Action Bar -->
    <div class="flex-between flex-wrap gap-12 mb-24">
        <div>
            <a href="<?= base_url('admin/orders.php') ?>" class="form-link">&larr; Back to Order Directory</a>
            <h1 class="order-title">Manage Order #<?= encode($o->order_no) ?></h1>
            <span class="fs-13 text-muted">Customer: <strong><?= encode($o->customer_name) ?></strong> (<?= encode($o->customer_email) ?>)</span>
        </div>
        <div class="d-flex items-center gap-8">
            <span class="role-pill pill-member pill-counter">
                Placed: <?= encode(date('d M Y, h:i A', strtotime($o->created_at))) ?>
            </span>
        </div>
    </div>

    <!-- Admin Status Control Panel -->
    <div class="card-panel p-24 mb-28">
        <h2 class="settings-section-title">
            Fulfillment & Tracking Workflow
        </h2>

        <?= err('db') ?>

        <?php if ($is_terminal && $o->payment_status !== 'Unpaid'): ?>
            <div class="order-status-locked-banner">
                <div class="d-flex items-center gap-12">
                    <span class="badge badge-terminal-status <?= $o->status === 'Delivered' ? 'badge-delivered-terminal' : 'badge-cancelled-terminal' ?>">
                        <?= encode($o->status) ?>
                    </span>
                    <span class="fs-13 text-muted">
                        This order has reached its final state and is locked from further workflow modifications.
                    </span>
                </div>
                <?php if ($o->tracking_no): ?>
                    <div class="fs-13">
                        <span class="text-muted">Tracking Number:</span>
                        <code class="fw-700 text-dark ml-4"><?= encode($o->tracking_no) ?></code>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <form action="<?= base_url('admin/order_detail.php?id=' . $id) ?>" method="POST" class="d-flex flex-col gap-16">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_status">

                <div class="d-flex gap-16 flex-wrap items-start">
                    <!-- Fulfillment Status Select -->
                    <div class="flex-1 min-w-200">
                        <label for="status" class="form-label">Order Fulfillment Status</label>
                        <?php if ($is_terminal): ?>
                            <input type="text" value="<?= encode($o->status) ?>" readonly class="form-control">
                            <input type="hidden" name="status" value="<?= encode($o->status) ?>">
                        <?php else: ?>
                            <select name="status" id="status" class="form-select <?= isset($errors['status']) ? 'form-control-error' : '' ?>">
                                <option value="<?= encode($o->status) ?>" selected><?= encode($o->status) ?> (Current)</option>
                                <?php foreach ($allowed_next_statuses as $st): ?>
                                    <option value="<?= encode($st) ?>" <?= (req('status') === $st) ? 'selected' : '' ?>>
                                        &rarr; Move to <?= encode($st) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?= err('status') ?>
                        <?php endif; ?>
                    </div>

                    <!-- Payment Status Select -->
                    <div class="flex-1 min-w-180">
                        <label for="payment_status" class="form-label">Payment Status</label>
                        <?php if ($o->payment_status === 'Unpaid'): ?>
                            <select name="payment_status" id="payment_status" class="form-select <?= isset($errors['payment_status']) ? 'form-control-error' : '' ?>">
                                <option value="Unpaid" <?= (req('payment_status', $o->payment_status) === 'Unpaid') ? 'selected' : '' ?>>Unpaid</option>
                                <option value="Paid" <?= (req('payment_status', $o->payment_status) === 'Paid') ? 'selected' : '' ?>>Paid (Mark Received)</option>
                            </select>
                            <?= err('payment_status') ?>
                        <?php else: ?>
                            <input type="text" value="<?= encode($o->payment_status) ?>" readonly class="form-control">
                            <input type="hidden" name="payment_status" value="<?= encode($o->payment_status) ?>">
                            <small class="form-hint-sm">Payment status cannot be reversed manually.</small>
                        <?php endif; ?>
                    </div>

                    <!-- Tracking Number Input -->
                    <div class="flex-1-5 min-w-240">
                        <label for="tracking_no" class="form-label">Courier Tracking Reference</label>
                        <?php if (in_array($o->status, ['Shipped', 'Delivered'], true)): ?>
                            <input type="text" name="tracking_no" id="tracking_no" value="<?= encode($o->tracking_no ?? '') ?>" readonly class="form-control">
                            <small class="form-hint-sm">Tracking number is permanent after consignment dispatch.</small>
                        <?php else: ?>
                            <input type="text" name="tracking_no" id="tracking_no" value="<?= encode(req('tracking_no', $o->tracking_no ?? '')) ?>" placeholder="e.g. JNT-MY-99881122" class="form-control <?= isset($errors['tracking_no']) ? 'form-control-error' : '' ?>">
                            <?= err('tracking_no') ?>
                            <small class="form-hint-sm">Required when moving to Shipped (3-100 alphanumeric or hyphen).</small>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <button type="submit" class="btn btn-primary btn-save-fulfillment">
                        Apply Workflow Transition
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <!-- Order Items and Customer Info Grid -->
    <div class="order-detail-grid">
        <!-- Purchased Items -->
        <div class="order-items-card">
            <h3 class="order-section-title">
                Order Line Items (<?= count($items) ?>)
            </h3>

            <div class="d-flex flex-col gap-16">
                <?php foreach ($items as $it): ?>
                    <div class="order-item-row">
                        <div class="order-item-thumb-admin">
                            <img src="<?= base_url('uploads/products/' . encode($it->photo_url)) ?>" alt="<?= encode($it->name) ?>" class="order-thumb-img">
                        </div>
                        <div class="flex-1">
                            <h4 class="order-item-name"><?= encode($it->name) ?></h4>
                            <div class="fs-12 text-muted"><?= (int)$it->quantity ?> &times; RM <?= number_format((float)$it->price, 2) ?></div>
                        </div>
                        <div class="fw-800 fs-15 text-dark">
                            RM <?= number_format((float)$it->subtotal, 2) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Customer & Financial Card -->
        <div class="d-flex flex-col gap-24">
            <!-- Shipping Card -->
            <div class="order-sidebar-card">
                <h4 class="order-sidebar-title fs-13 mb-10">
                    Shipping Recipient
                </h4>
                <?php if ($addr): ?>
                    <div class="order-address-text">
                        <strong><?= encode($addr->recipient_name) ?></strong><br>
                        Phone: <?= encode($addr->phone) ?><br>
                        <?= encode($addr->address_line1) ?><?= $addr->address_line2 ? ', ' . encode($addr->address_line2) : '' ?><br>
                        <?= encode($addr->postal_code) ?> <?= encode($addr->city) ?>, <?= encode($addr->state) ?>
                    </div>
                <?php else: ?>
                    <div class="fs-13 text-muted">No explicit address recorded.</div>
                <?php endif; ?>
            </div>

            <!-- Financial Summary -->
            <div class="order-sidebar-card">
                <h4 class="order-sidebar-title fs-13 mb-12">
                    Order Financials
                </h4>
                <div class="order-breakdown-list">
                    <div class="flex-between">
                        <span class="text-muted">Payment Method:</span>
                        <strong><?= ($o->payment_method ?? 'pay_later') === 'stripe' ? 'Stripe Gateway' : 'Pay Later' ?></strong>
                    </div>
                    <div class="flex-between">
                        <span class="text-muted">Items Total:</span>
                        <strong>RM <?= number_format((float)$o->total_amount, 2) ?></strong>
                    </div>
                    <?php if ($o->discount_amount > 0): ?>
                        <div class="flex-between text-success">
                            <span>Discount:</span>
                            <strong>- RM <?= number_format((float)$o->discount_amount, 2) ?></strong>
                        </div>
                    <?php endif; ?>
                    <div class="flex-between">
                        <span class="text-muted">Delivery:</span>
                        <span><?= ($o->total_amount >= 150) ? 'RM 0.00' : 'RM 15.00' ?></span>
                    </div>
                </div>
                <div class="order-total-row">
                    <span>Grand Total:</span>
                    <span class="text-primary">RM <?= number_format((float)$o->final_amount, 2) ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../_foot.php'; ?>