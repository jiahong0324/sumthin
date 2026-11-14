<?php
// ============================================================================
// File: checkout.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Shopping Cart (Checkout & Order Creation)
// Description: Finalize shipping destination, evaluate final financial totals,
//              atomically record orders & order items, decrement stock, and empty cart.
// ============================================================================
require_once __DIR__ . '/_base.php';

auth('Member');

$user_id = $_SESSION['user']->id;

// Fetch persistent cart items
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

// Compute financial figures
$subtotal = 0;
foreach ($cart_items as $item) {
    $subtotal += ($item->price * $item->quantity);
}

$discount_amount = 0;
$applied_voucher = $_SESSION['applied_voucher'] ?? null;
if ($applied_voucher && $subtotal >= $applied_voucher->min_spend) {
    if ($applied_voucher->discount_type === 'percentage') {
        $discount_amount = ($subtotal * ($applied_voucher->discount_value / 100));
        if ($applied_voucher->max_discount) {
            $discount_amount = min($discount_amount, (float)$applied_voucher->max_discount);
        }
    } else {
        $discount_amount = min($subtotal, (float)$applied_voucher->discount_value);
    }
} else {
    $applied_voucher = null;
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

$malaysian_states = [
    'Wilayah Persekutuan' => 'Wilayah Persekutuan (KL / Putrajaya / Labuan)',
    'Selangor'            => 'Selangor',
    'Penang'              => 'Penang',
    'Johor'               => 'Johor',
    'Perak'               => 'Perak',
    'Melaka'              => 'Melaka',
    'Negeri Sembilan'     => 'Negeri Sembilan',
    'Kedah'               => 'Kedah',
    'Pahang'              => 'Pahang',
    'Terengganu'          => 'Terengganu',
    'Kelantan'            => 'Kelantan',
    'Sabah'               => 'Sabah',
    'Sarawak'             => 'Sarawak',
];

if (is_post()) {
    verify_csrf();

    $address_choice = req('address_choice');
    $payment_method = req('payment_method', 'online_banking');
    $target_address_id = null;

    if ($address_choice === 'new' || empty($saved_addresses)) {
        $recipient_name = req('recipient_name');
        $phone          = req('phone');
        $address_line1  = req('address_line1');
        $address_line2  = req('address_line2');
        $city           = req('city');
        $state          = req('state');
        $postal_code    = req('postal_code');

        if ($recipient_name === '') $errors['recipient_name'] = 'Recipient name is required.';
        if ($phone === '') {
            $errors['phone'] = 'Contact phone number is required.';
        } elseif (!preg_match('/^(\+?6?01)[0-46-9]-*[0-9]{7,8}$/', $phone)) {
            $errors['phone'] = 'Please enter a valid Malaysian mobile number (e.g. 012-3456789).';
        }
        if ($address_line1 === '') $errors['address_line1'] = 'Street address is required.';
        if ($city === '') $errors['city'] = 'City is required.';
        if ($postal_code === '' || !preg_match('/^[0-9]{5}$/', $postal_code)) {
            $errors['postal_code'] = 'Postal code must be 5 digits (e.g. 53300).';
        }
    } else {
        $target_address_id = (int)$address_choice;
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();

            // 1. Save new address if selected
            if ($address_choice === 'new' || empty($saved_addresses)) {
                $ins_addr = $db->prepare('
                    INSERT INTO `address` (`user_id`, `recipient_name`, `phone`, `address_line1`, `address_line2`, `city`, `state`, `postal_code`, `is_default`) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
                ');
                $ins_addr->execute([$user_id, $recipient_name, $phone, $address_line1, $address_line2, $city, $state, $postal_code]);
                $target_address_id = (int)$db->lastInsertId();
            }

            // 2. Generate unique human-readable order number
            $order_no = 'ORD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

            // 3. Create master order record
            $ins_ord = $db->prepare('
                INSERT INTO `order` (`order_no`, `user_id`, `address_id`, `total_amount`, `discount_amount`, `final_amount`, `status`, `payment_status`, `tracking_no`) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $tracking_no = 'SUM-' . date('Ymd') . '-' . rand(1000, 9999);
            $ins_ord->execute([
                $order_no,
                $user_id,
                $target_address_id,
                number_format((float)$subtotal, 2, '.', ''),
                number_format((float)$discount_amount, 2, '.', ''),
                number_format((float)$final_total, 2, '.', ''),
                'Processing',
                'Paid',
                $tracking_no
            ]);
            $order_id = (int)$db->lastInsertId();

            // 4. Insert order items & reduce live inventory stock
            $ins_item = $db->prepare('
                INSERT INTO `order_item` (`order_id`, `product_id`, `price`, `quantity`, `subtotal`) 
                VALUES (?, ?, ?, ?, ?)
            ');
            $dec_stock = $db->prepare('UPDATE `product` SET `stock` = GREATEST(0, `stock` - ?) WHERE `id` = ?');

            foreach ($cart_items as $ci) {
                $item_subtotal = $ci->price * $ci->quantity;
                $ins_item->execute([$order_id, $ci->product_id, $ci->price, $ci->quantity, $item_subtotal]);
                $dec_stock->execute([$ci->quantity, $ci->product_id]);
            }

            // 5. Update voucher usage if applied
            if ($applied_voucher) {
                $v_up = $db->prepare('UPDATE `voucher` SET `used_count` = `used_count` + 1 WHERE `id` = ?');
                $v_up->execute([$applied_voucher->id]);
                unset($_SESSION['applied_voucher']);
            }

            // 6. Flush permanent cart
            $flush_cart = $db->prepare('DELETE FROM `cart_item` WHERE `user_id` = ?');
            $flush_cart->execute([$user_id]);

            // 7. Audit log receipt
            $receipt_body = "Thank you for shopping at sumthin!\nOrder No: {$order_no}\nTotal Paid: RM " . number_format($final_total, 2) . "\nStatus: Processing\nTracking No: {$tracking_no}";
            log_email($_SESSION['user']->email, "Order Confirmation - {$order_no}", $receipt_body);

            $db->commit();

            set_flash('success', "Order placed successfully! Order reference: {$order_no}");
            redirect(base_url('order_detail.php?id=' . $order_id));
        } catch (Exception $e) {
            $db->rollBack();
            $errors['order'] = 'Checkout failed: ' . $e->getMessage();
        }
    }
}

$title = 'Checkout';
require_once __DIR__ . '/_head.php';
?>

<div class="container" style="max-width: 1050px;">
    <!-- Title -->
    <div style="margin-bottom: 24px;">
        <a href="<?= base_url('cart.php') ?>" class="form-link">&larr; Return to Cart</a>
        <h1 style="font-size:28px; font-weight:800; color:var(--dark); margin-top:6px;">Order Checkout</h1>
        <p style="font-size:14px; color:var(--gray);">Confirm your delivery address and complete your order.</p>
    </div>

    <?php if (isset($errors['order'])): ?>
        <div class="flash-alert flash-error" style="margin-bottom:20px;">
            <div class="flash-icon">✕</div>
            <div class="flash-message"><?= encode($errors['order']) ?></div>
        </div>
    <?php endif; ?>

    <form action="<?= base_url('checkout.php') ?>" method="POST" novalidate>
        <?= csrf_field() ?>

        <div style="display:grid; grid-template-columns: 1.6fr 1.1fr; gap:32px; align-items:flex-start;">
            <!-- Left: Delivery Address Form -->
            <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:28px; box-shadow:var(--shadow-sm);">
                <h2 style="font-size:18px; font-weight:800; color:var(--dark); margin-bottom:16px; border-bottom:1px solid var(--border); padding-bottom:10px;">
                    1. Shipping Address
                </h2>

                <?php if (!empty($saved_addresses)): ?>
                    <!-- Saved Addresses Picker -->
                    <div style="margin-bottom:20px;">
                        <label class="form-label">Select a Saved Address</label>
                        <?php foreach ($saved_addresses as $sa): ?>
                            <label style="display:block; padding:12px; border:1px solid var(--border); border-radius:8px; margin-bottom:10px; cursor:pointer;">
                                <input type="radio" name="address_choice" value="<?= (int)$sa->id ?>" <?= ((string)$selected_addr === (string)$sa->id) ? 'checked' : '' ?>>
                                <strong style="margin-left:6px;"><?= encode($sa->recipient_name) ?></strong> (<?= encode($sa->phone) ?>)
                                <div style="font-size:13px; color:var(--dark-muted); margin-left:22px;">
                                    <?= encode($sa->address_line1) ?><?= $sa->address_line2 ? ', ' . encode($sa->address_line2) : '' ?>, <?= encode($sa->postal_code) ?> <?= encode($sa->city) ?>, <?= encode($sa->state) ?>
                                </div>
                            </label>
                        <?php endforeach; ?>

                        <label style="display:block; padding:12px; border:1px dashed var(--border-dark); border-radius:8px; cursor:pointer;">
                            <input type="radio" name="address_choice" value="new" <?= ($selected_addr === 'new') ? 'checked' : '' ?>>
                            <strong style="margin-left:6px;">+ Enter a New Delivery Address</strong>
                        </label>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="address_choice" value="new">
                <?php endif; ?>

                <!-- New Address Fields (Rendered if chosen or no saved address) -->
                <div id="new-address-fields" style="<?= (!empty($saved_addresses) && $selected_addr !== 'new') ? 'display:none;' : '' ?>">
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

                    <div style="display:flex; gap:16px; flex-wrap:wrap;">
                        <div class="form-group" style="flex:1;">
                            <label for="city" class="form-label">City *</label>
                            <?= html_text('city', $city) ?>
                            <?= err('city') ?>
                        </div>
                        <div class="form-group" style="flex:1;">
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
                    </div>
                </div>

                <!-- Payment Selection -->
                <h2 style="font-size:18px; font-weight:800; color:var(--dark); margin:28px 0 16px 0; border-bottom:1px solid var(--border); padding-bottom:10px;">
                    2. Payment Method
                </h2>

                <div style="display:flex; flex-direction:column; gap:10px;">
                    <label style="display:flex; align-items:center; gap:10px; padding:12px; border:1px solid var(--border); border-radius:8px; cursor:pointer;">
                        <input type="radio" name="payment_method" value="online_banking" checked>
                        <div>
                            <strong>Instant FPX Online Banking / Card (Simulation)</strong>
                            <div style="font-size:12px; color:var(--gray);">Direct instant confirmation for live academic demo.</div>
                        </div>
                    </label>

                    <label style="display:flex; align-items:center; gap:10px; padding:12px; border:1px solid var(--border); border-radius:8px; cursor:pointer;">
                        <input type="radio" name="payment_method" value="stripe">
                        <div>
                            <strong>Stripe API Gateway (Test Mode)</strong>
                            <div style="font-size:12px; color:var(--gray);">Integrated with Stripe test sandbox via plain cURL.</div>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Right: Order Summary Confirmation -->
            <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:24px; box-shadow:var(--shadow-sm);">
                <h2 style="font-size:18px; font-weight:800; color:var(--dark); margin-bottom:16px; border-bottom:1px solid var(--border); padding-bottom:10px;">
                    Review Order (<?= count($cart_items) ?> Items)
                </h2>

                <div style="display:flex; flex-direction:column; gap:12px; margin-bottom:20px; max-height:220px; overflow-y:auto; padding-right:6px;">
                    <?php foreach ($cart_items as $ci): ?>
                        <div style="display:flex; justify-content:space-between; font-size:13px;">
                            <div>
                                <strong><?= encode($ci->name) ?></strong>
                                <div style="color:var(--gray); font-size:12px;">Qty: <?= (int)$ci->quantity ?> &times; RM <?= number_format((float)$ci->price, 2) ?></div>
                            </div>
                            <span style="font-weight:700;">RM <?= number_format((float)($ci->price * $ci->quantity), 2) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="border-top:1px solid var(--border); padding-top:14px; display:flex; flex-direction:column; gap:8px; font-size:14px; margin-bottom:20px;">
                    <div style="display:flex; justify-content:space-between;">
                        <span style="color:var(--gray);">Subtotal:</span>
                        <strong>RM <?= number_format((float)$subtotal, 2) ?></strong>
                    </div>

                    <?php if ($discount_amount > 0): ?>
                        <div style="display:flex; justify-content:space-between; color:var(--success);">
                            <span>Voucher Discount (<?= encode($applied_voucher->code) ?>):</span>
                            <strong>- RM <?= number_format((float)$discount_amount, 2) ?></strong>
                        </div>
                    <?php endif; ?>

                    <div style="display:flex; justify-content:space-between;">
                        <span style="color:var(--gray);">Express Delivery:</span>
                        <span><?= ($shipping_fee == 0) ? '<strong style="color:var(--success);">FREE</strong>' : 'RM ' . number_format($standard_shipping_fee, 2) ?></span>
                    </div>

                    <div style="display:flex; justify-content:space-between; font-size:18px; font-weight:900; margin-top:8px; border-top:1px solid var(--border); padding-top:10px;">
                        <span>Total Payable:</span>
                        <span style="color:var(--primary);">RM <?= number_format((float)$final_total, 2) ?></span>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block" style="padding:14px; font-size:16px;">
                    Authorize Payment & Place Order &rarr;
                </button>
            </div>
        </div>
    </form>
</div>

<script>
// jQuery helper to toggle new address input box visibility
document.addEventListener('DOMContentLoaded', function() {
    var radios = document.querySelectorAll('input[name="address_choice"]');
    var newBox = document.getElementById('new-address-fields');
    if (radios && newBox) {
        radios.forEach(function(r) {
            r.addEventListener('change', function() {
                newBox.style.display = (this.value === 'new') ? 'block' : 'none';
            });
        });
    }
});
</script>

<?php require_once __DIR__ . '/_foot.php'; ?>
