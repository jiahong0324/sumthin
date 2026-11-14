<?php
// ============================================================================
// File: checkout.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Shopping Cart (Hardened Checkout & Order Creation)
// Description: Atomically evaluates inventory with SELECT FOR UPDATE, enforces
//              strict voucher re-validation, verifies address ownership,
//              creates orders in Pending/Unpaid state, and decrements stock.
// ============================================================================
require_once __DIR__ . '/_base.php';

auth('Member');

$user_id = (int)$_SESSION['user']->id;

// Fetch persistent cart items for display
$stmt = $db->prepare('
    SELECT ci.*, p.name, p.price, p.stock 
    FROM `cart_item` ci
    JOIN `product` p ON ci.product_id = p.id
    WHERE ci.user_id = ?
');
$stmt->execute([$user_id]);
$cart_items = $stmt->fetchAll();

if (empty($cart_items)) {
    set_flash('warning', 'Your shopping cart is empty.');
    redirect(base_url('cart.php'));
}

// Compute preview financial figures
$subtotal = 0;
foreach ($cart_items as $item) {
    $subtotal += ((float)$item->price * (int)$item->quantity);
}

// Preview Voucher Discount if applicable
$discount_amount = 0;
$applied_code = $_SESSION['applied_voucher'] ?? null;
$applied_voucher = null;

if ($applied_code && $subtotal > 0) {
    $v_stmt = $db->prepare('SELECT * FROM `voucher` WHERE `code` = ? AND `is_active` = 1 AND `expires_at` > NOW() LIMIT 1');
    $v_stmt->execute([$applied_code]);
    $v_check = $v_stmt->fetch();

    if ($v_check && $v_check->used_count < $v_check->usage_limit && $subtotal >= (float)$v_check->min_spend) {
        $applied_voucher = $v_check;
        if ($v_check->discount_type === 'percentage') {
            $discount_amount = ($subtotal * ((float)$v_check->discount_value / 100));
            if ($v_check->max_discount) {
                $discount_amount = min($discount_amount, (float)$v_check->max_discount);
            }
        } else {
            $discount_amount = min($subtotal, (float)$v_check->discount_value);
        }
    } else {
        unset($_SESSION['applied_voucher']);
    }
}

$free_shipping_threshold = (float)setting('free_shipping_threshold', '150');
$standard_shipping_fee   = (float)setting('standard_shipping_fee', '15');
$shipping_fee = ($subtotal >= $free_shipping_threshold) ? 0 : $standard_shipping_fee;
$final_total  = max(0, ($subtotal - $discount_amount) + $shipping_fee);

// Fetch saved shipping addresses
$addr_stmt = $db->prepare('SELECT * FROM `address` WHERE `user_id` = ? ORDER BY `is_default` DESC, `id` DESC');
$addr_stmt->execute([$user_id]);
$saved_addresses = $addr_stmt->fetchAll();

$recipient_name = $_SESSION['user']->name;
$phone          = '';
$address_line1  = '';
$address_line2  = '';
$city           = 'Kuala Lumpur';
$state          = 'Wilayah Persekutuan';
$postal_code    = '';
$selected_addr  = !empty($saved_addresses) ? $saved_addresses[0]->id : 'new';
$payment_method = 'pay_later';
$malaysian_states = get_malaysian_states();

if (is_post()) {
    verify_csrf();

    $address_choice = req('address_choice');
    $payment_method = req('payment_method', 'pay_later');
    $target_address_id = null;

    // 1. Payment Method Validation against strict allowed list
    $allowed_payment_methods = ['stripe', 'pay_later'];
    if (!in_array($payment_method, $allowed_payment_methods, true)) {
        $errors['payment_method'] = 'Please select a valid payment method.';
    }

    // 2. Address Ownership Verification & Input Validation
    if ($address_choice === 'new' || empty($saved_addresses)) {
        $recipient_name = req('recipient_name');
        $phone          = req('phone');
        $address_line1  = req('address_line1');
        $address_line2  = req('address_line2');
        $city           = req('city');
        $state          = req('state');
        $postal_code    = req('postal_code');

        if ($err = validate_name($recipient_name, 2, 100)) {
            $errors['recipient_name'] = $err;
        }
        if ($err = validate_phone($phone)) {
            $errors['phone'] = $err;
        }
        if ($address_line1 === '') $errors['address_line1'] = 'Street address is required.';
        if ($city === '') $errors['city'] = 'City is required.';
        if ($postal_code === '' || !preg_match('/^[0-9]{5}$/', $postal_code)) {
            $errors['postal_code'] = 'Postal code must be 5 digits (e.g. 53300).';
        }
        if (!array_key_exists($state, $malaysian_states)) {
            $errors['state'] = 'Please select a valid Malaysian state from the dropdown.';
        }
    } else {
        // Enforce ownership: Verify address belongs strictly to the authenticated user
        $check_addr = $db->prepare('SELECT `id` FROM `address` WHERE `id` = ? AND `user_id` = ? LIMIT 1');
        $check_addr->execute([(int)$address_choice, $user_id]);
        $verified_addr_id = $check_addr->fetchColumn();

        if (!$verified_addr_id) {
            $errors['address_choice'] = 'Invalid shipping address selected.';
        } else {
            $target_address_id = (int)$verified_addr_id;
        }
    }

    // 3. Process Transactional Checkout
    if (empty($errors)) {
        try {
            $db->beginTransaction();

            // A. Fetch current user cart items inside transaction
            $c_stmt = $db->prepare('SELECT `product_id`, `quantity` FROM `cart_item` WHERE `user_id` = ?');
            $c_stmt->execute([$user_id]);
            $current_cart = $c_stmt->fetchAll();

            if (empty($current_cart)) {
                $db->rollBack();
                set_flash('warning', 'Your shopping cart is empty.');
                redirect(base_url('cart.php'));
            }

            // B. Re-read each product with SELECT ... FOR UPDATE and compute live prices
            $live_subtotal = 0;
            $order_line_items = [];
            $prod_lock_stmt = $db->prepare('SELECT `id`, `name`, `price`, `stock` FROM `product` WHERE `id` = ? FOR UPDATE');

            foreach ($current_cart as $item) {
                $prod_lock_stmt->execute([$item->product_id]);
                $prod = $prod_lock_stmt->fetch();

                if (!$prod) {
                    $db->rollBack();
                    $errors['order'] = 'A product in your cart is no longer available.';
                    break;
                }

                $req_qty = (int)$item->quantity;
                $avail_stock = (int)$prod->stock;

                if ($avail_stock < $req_qty) {
                    $db->rollBack();
                    $errors['order'] = "Only {$avail_stock} left of {$prod->name}. Please adjust your quantity.";
                    break;
                }

                $item_price = (float)$prod->price;
                $line_subtotal = $item_price * $req_qty;
                $live_subtotal += $line_subtotal;

                $order_line_items[] = [
                    'product_id' => (int)$prod->id,
                    'price'      => $item_price,
                    'quantity'   => $req_qty,
                    'subtotal'   => $line_subtotal,
                ];
            }

            if (!empty($errors['order'])) {
                // Transaction rolled back above; preserve cart for user adjustment
            } else {
                // C. Voucher: Re-fetch and re-validate from database inside transaction
                $voucher_discount = 0;
                $voucher_id_to_increment = null;
                $applied_code = $_SESSION['applied_voucher'] ?? null;

                if ($applied_code) {
                    $v_lock = $db->prepare('SELECT * FROM `voucher` WHERE `code` = ? FOR UPDATE');
                    $v_lock->execute([$applied_code]);
                    $v_row = $v_lock->fetch();

                    $is_valid_v = $v_row 
                        && (int)$v_row->is_active === 1
                        && strtotime($v_row->expires_at) > time()
                        && (int)$v_row->used_count < (int)$v_row->usage_limit
                        && $live_subtotal >= (float)$v_row->min_spend;

                    if (!$is_valid_v) {
                        $db->rollBack();
                        unset($_SESSION['applied_voucher']);
                        $errors['order'] = "Voucher '{$applied_code}' is invalid, expired, or has reached its redemption limit.";
                    } else {
                        $voucher_id_to_increment = (int)$v_row->id;
                        if ($v_row->discount_type === 'percentage') {
                            $voucher_discount = ($live_subtotal * ((float)$v_row->discount_value / 100));
                            if ($v_row->max_discount) {
                                $voucher_discount = min($voucher_discount, (float)$v_row->max_discount);
                            }
                        } else {
                            $voucher_discount = min($live_subtotal, (float)$v_row->discount_value);
                        }

                        // Atomic increment of voucher usage
                        $v_up = $db->prepare('UPDATE `voucher` SET `used_count` = `used_count` + 1 WHERE `id` = ? AND `used_count` < `usage_limit`');
                        $v_up->execute([$voucher_id_to_increment]);

                        if ($v_up->rowCount() !== 1) {
                            $db->rollBack();
                            unset($_SESSION['applied_voucher']);
                            $errors['order'] = "Voucher '{$applied_code}' has just reached its maximum redemption limit.";
                        }
                    }
                }

                if (empty($errors['order'])) {
                    // D. Calculate Shipping & Final Payable
                    $live_shipping_fee = ($live_subtotal >= $free_shipping_threshold) ? 0 : $standard_shipping_fee;
                    $live_final_total  = max(0, ($live_subtotal - $voucher_discount) + $live_shipping_fee);

                    // E. Save new address if selected
                    if ($address_choice === 'new' || empty($saved_addresses)) {
                        $ins_addr = $db->prepare('
                            INSERT INTO `address` (`user_id`, `recipient_name`, `phone`, `address_line1`, `address_line2`, `city`, `state`, `postal_code`, `is_default`) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
                        ');
                        $ins_addr->execute([$user_id, $recipient_name, $phone, $address_line1, $address_line2, $city, $state, $postal_code]);
                        $target_address_id = (int)$db->lastInsertId();
                    }

                    // F. Generate unique order number
                    $order_no = 'ORD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

                    // G. Create master order record: status 'Pending', payment_status 'Unpaid', tracking_no NULL
                    $ins_ord = $db->prepare('
                        INSERT INTO `order` (`order_no`, `user_id`, `address_id`, `total_amount`, `discount_amount`, `final_amount`, `status`, `payment_status`, `tracking_no`) 
                        VALUES (?, ?, ?, ?, ?, ?, "Pending", "Unpaid", NULL)
                    ');
                    $ins_ord->execute([
                        $order_no,
                        $user_id,
                        $target_address_id,
                        number_format($live_subtotal, 2, '.', ''),
                        number_format($voucher_discount, 2, '.', ''),
                        number_format($live_final_total, 2, '.', '')
                    ]);
                    $order_id = (int)$db->lastInsertId();

                    // H. Insert order items and atomically decrement inventory stock
                    $ins_item = $db->prepare('
                        INSERT INTO `order_item` (`order_id`, `product_id`, `price`, `quantity`, `subtotal`) 
                        VALUES (?, ?, ?, ?, ?)
                    ');
                    $dec_stock = $db->prepare('UPDATE `product` SET `stock` = `stock` - ? WHERE `id` = ? AND `stock` >= ?');

                    foreach ($order_line_items as $line) {
                        $ins_item->execute([
                            $order_id,
                            $line['product_id'],
                            number_format($line['price'], 2, '.', ''),
                            $line['quantity'],
                            number_format($line['subtotal'], 2, '.', '')
                        ]);

                        $dec_stock->execute([$line['quantity'], $line['product_id'], $line['quantity']]);
                        if ($dec_stock->rowCount() !== 1) {
                            throw new Exception("Inventory stock allocation failed for product ID {$line['product_id']}.");
                        }
                    }

                    // I. Flush user shopping cart and session voucher
                    $flush_cart = $db->prepare('DELETE FROM `cart_item` WHERE `user_id` = ?');
                    $flush_cart->execute([$user_id]);
                    unset($_SESSION['applied_voucher']);

                    $db->commit();

                    // J. Dispatch e-receipt notification email
                    if (function_exists('send_mail')) {
                        $mail_body = "<p>Dear " . encode($_SESSION['user']->name) . ",</p>"
                            . "<p>Thank you for shopping at sumthin! Your order has been placed successfully.</p>"
                            . "<ul>"
                            . "<li><strong>Order Number:</strong> " . encode($order_no) . "</li>"
                            . "<li><strong>Total Payable:</strong> RM " . number_format($live_final_total, 2) . "</li>"
                            . "<li><strong>Order Status:</strong> Pending (Unpaid)</li>"
                            . "<li><strong>Payment Method:</strong> " . ($payment_method === 'stripe' ? 'Stripe Gateway' : 'Pay Later / Bank Transfer') . "</li>"
                            . "</ul>"
                            . "<p>We will notify you as soon as your payment is confirmed and parcel dispatched.</p>";

                        send_mail($_SESSION['user']->email, $_SESSION['user']->name, "Order Confirmation - {$order_no}", $mail_body, 'order');
                    }

                    set_flash('success', "Order placed successfully! Order reference: {$order_no}. Please proceed to payment.");
                    redirect(base_url('order_detail.php?id=' . $order_id));
                }
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $errors['order'] = app_error_message('Checkout failed. Please try again.', $e);
        }
    }
}

$title = 'Checkout';
require_once __DIR__ . '/_head.php';
?>

<div class="container container-detail">
    <!-- Title -->
    <div class="mb-24">
        <a href="<?= base_url('cart.php') ?>" class="form-link">&larr; Return to Cart</a>
        <h1 class="checkout-heading">Order Checkout</h1>
        <p class="checkout-subheading">Confirm your delivery address and complete your order.</p>
    </div>

    <?php if (isset($errors['order'])): ?>
        <div class="flash-alert flash-error mb-20">
            <div class="flash-icon">✕</div>
            <div class="flash-message"><?= encode($errors['order']) ?></div>
        </div>
    <?php endif; ?>

    <form action="<?= base_url('checkout.php') ?>" method="POST" novalidate>
        <?= csrf_field() ?>

        <div class="checkout-grid">
            <!-- Left: Delivery Address Form -->
            <div class="checkout-section-card">
                <h2 class="checkout-section-title">
                    1. Shipping Address
                </h2>

                <?php if (isset($errors['address_choice'])): ?>
                    <div class="checkout-error-msg">
                        <?= encode($errors['address_choice']) ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($saved_addresses)): ?>
                    <!-- Saved Addresses Picker -->
                    <div class="mb-20">
                        <label class="form-label">Select a Saved Address</label>
                        <?php foreach ($saved_addresses as $sa): ?>
                            <label class="address-choice-item">
                                <input type="radio" name="address_choice" value="<?= (int)$sa->id ?>" <?= ((string)$selected_addr === (string)$sa->id) ? 'checked' : '' ?>>
                                <strong class="ml-6"><?= encode($sa->recipient_name) ?></strong> (<?= encode($sa->phone) ?>)
                                <div class="address-detail-text">
                                    <?= encode($sa->address_line1) ?><?= $sa->address_line2 ? ', ' . encode($sa->address_line2) : '' ?>, <?= encode($sa->postal_code) ?> <?= encode($sa->city) ?>, <?= encode($sa->state) ?>
                                </div>
                            </label>
                        <?php endforeach; ?>

                        <label class="address-new-choice">
                            <input type="radio" name="address_choice" value="new" <?= ($selected_addr === 'new') ? 'checked' : '' ?>>
                            <strong class="ml-6">+ Enter a New Delivery Address</strong>
                        </label>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="address_choice" value="new">
                <?php endif; ?>

                <!-- New Address Fields (Rendered if chosen or no saved address) -->
                <div id="new-address-fields" class="<?= (!empty($saved_addresses) && $selected_addr !== 'new') ? 'd-none' : '' ?>">
                    <div class="form-group">
                        <label for="recipient_name" class="form-label">Recipient Name *</label>
                        <?= html_text('recipient_name', $recipient_name, ['placeholder' => 'Full Name']) ?>
                        <?= err('recipient_name') ?>
                    </div>

                    <div class="form-group">
                        <label for="phone" class="form-label">Contact Phone *</label>
                        <?= html_text('phone', $phone, ['placeholder' => '012-3456789']) ?>
                        <?= err('phone') ?>
                    </div>

                    <div class="form-group">
                        <label for="address_line1" class="form-label">Street Address *</label>
                        <?= html_text('address_line1', $address_line1, ['placeholder' => 'Unit number, building, street']) ?>
                        <?= err('address_line1') ?>
                    </div>

                    <div class="form-group">
                        <label for="address_line2" class="form-label">Address Line 2 (Optional)</label>
                        <?= html_text('address_line2', $address_line2, ['placeholder' => 'Apartment, suite, or area']) ?>
                    </div>

                    <div class="address-row-flex">
                        <div class="form-group flex-1">
                            <label for="city" class="form-label">City *</label>
                            <?= html_text('city', $city) ?>
                            <?= err('city') ?>
                        </div>
                        <div class="form-group flex-1">
                            <label for="postal_code" class="form-label">Postal Code *</label>
                            <?= html_text('postal_code', $postal_code, ['placeholder' => '53300']) ?>
                            <?= err('postal_code') ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="state" class="form-label">State *</label>
                        <select name="state" id="state" class="form-select">
                            <?php foreach ($malaysian_states as $s_val => $s_label): ?>
                                <option value="<?= encode($s_val) ?>" <?= ($state === $s_val) ? 'selected' : '' ?>>
                                    <?= encode($s_label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?= err('state') ?>
                    </div>
                </div>

                <!-- Payment Selection -->
                <h2 class="checkout-section-title mt-28">
                    2. Payment Method
                </h2>

                <?php if (isset($errors['payment_method'])): ?>
                    <div class="checkout-error-msg">
                        <?= encode($errors['payment_method']) ?>
                    </div>
                <?php endif; ?>

                <div class="payment-methods-list">
                    <label class="payment-choice-item">
                        <input type="radio" name="payment_method" value="pay_later" <?= ($payment_method === 'pay_later') ? 'checked' : '' ?>>
                        <div>
                            <strong>Pay Later / Cash on Delivery / Bank Transfer</strong>
                            <div class="payment-desc-text">Manual confirmation and bank transfer option for demo.</div>
                        </div>
                    </label>

                    <label class="payment-choice-item">
                        <input type="radio" name="payment_method" value="stripe" <?= ($payment_method === 'stripe') ? 'checked' : '' ?>>
                        <div>
                            <strong>Stripe Card Payment (Test Mode)</strong>
                            <div class="payment-desc-text">Secure checkout via Stripe payment gateway.</div>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Right: Order Summary Confirmation -->
            <div class="checkout-summary-card">
                <h2 class="checkout-section-title">
                    Review Order (<?= count($cart_items) ?> Items)
                </h2>

                <div class="checkout-items-scroll">
                    <?php foreach ($cart_items as $ci): ?>
                        <div class="checkout-item-line">
                            <div>
                                <strong><?= encode($ci->name) ?></strong>
                                <div class="checkout-item-meta">Qty: <?= (int)$ci->quantity ?> &times; RM <?= number_format((float)$ci->price, 2) ?></div>
                            </div>
                            <span class="fw-700">RM <?= number_format((float)($ci->price * $ci->quantity), 2) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="checkout-totals-block">
                    <div class="flex-between">
                        <span class="text-muted">Subtotal:</span>
                        <strong>RM <?= number_format((float)$subtotal, 2) ?></strong>
                    </div>

                    <?php if ($discount_amount > 0): ?>
                        <div class="flex-between text-success">
                            <span>Voucher Discount (<?= encode($applied_voucher->code ?? '') ?>):</span>
                            <strong>- RM <?= number_format((float)$discount_amount, 2) ?></strong>
                        </div>
                    <?php endif; ?>

                    <div class="flex-between">
                        <span class="text-muted">Express Delivery:</span>
                        <span><?= ($shipping_fee == 0) ? '<strong class="text-success">FREE</strong>' : 'RM ' . number_format($standard_shipping_fee, 2) ?></span>
                    </div>

                    <div class="checkout-grand-total-row">
                        <span>Total Payable:</span>
                        <span class="text-primary">RM <?= number_format((float)$final_total, 2) ?></span>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block btn-place-order">
                    Place Order &rarr;
                </button>
            </div>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/_foot.php'; ?>
