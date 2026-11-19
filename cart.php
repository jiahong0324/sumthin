<?php
// ============================================================================
// File: cart.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Shopping Cart (Cart Review & Voucher Application)
// Description: Member cart inspection, quantity manipulation, discount voucher
//              application (percentage or fixed), and subtotal calculation.
// ============================================================================
require_once __DIR__ . '/_base.php';

auth('Member');

$user_id = $_SESSION['user']->id;

// Handle Voucher Code Application or Removal
if (is_post() && req('voucher_action') !== '') {
    verify_csrf();
    $v_action = req('voucher_action');

    if ($v_action === 'apply') {
        $code = strtoupper(trim(req('voucher_code')));
        if ($code === '') {
            set_flash('error', 'Please enter a voucher code.');
        } else {
            $stmt = $db->prepare('SELECT * FROM `voucher` WHERE `code` = ? AND `is_active` = 1 AND `expires_at` > NOW() LIMIT 1');
            $stmt->execute([$code]);
            $voucher = $stmt->fetch();

            if ($voucher && $voucher->used_count < $voucher->usage_limit) {
                // Store only voucher code in session per security hardening rules
                $_SESSION['applied_voucher'] = $voucher->code;
                set_flash('success', "Voucher '{$voucher->code}' applied successfully!");
            } else {
                unset($_SESSION['applied_voucher']);
                set_flash('error', 'Invalid, expired, inactive, or fully redeemed voucher code.');
            }
        }
    } elseif ($v_action === 'remove') {
        unset($_SESSION['applied_voucher']);
        set_flash('info', 'Voucher removed.');
    }
    redirect(base_url('cart.php'));
}

// Fetch all persistent cart items from database
$stmt = $db->prepare("
    SELECT ci.id AS cart_id, ci.quantity, p.id AS product_id, p.name, p.price, p.stock,
           c.name AS category_name,
           COALESCE(pp.photo_url, 'default_product.png') AS photo_url
    FROM `cart_item` ci
    JOIN `product` p ON ci.product_id = p.id
    JOIN `category` c ON p.category_id = c.id
    LEFT JOIN `product_photo` pp ON p.id = pp.product_id AND pp.is_primary = 1
    WHERE ci.user_id = ?
    ORDER BY ci.id DESC
");
$stmt->execute([$user_id]);
$cart_items = $stmt->fetchAll();

// Calculate financial figures
$subtotal = 0;
foreach ($cart_items as $item) {
    $subtotal += ($item->price * $item->quantity);
}

// Evaluate Voucher Discount if applicable (Store only code, re-validate from DB)
$discount_amount = 0;
$applied_code = $_SESSION['applied_voucher'] ?? null;
$applied_voucher = null;

if ($applied_code) {
    if ($subtotal <= 0) {
        unset($_SESSION['applied_voucher']);
        set_flash('warning', "Voucher {$applied_code} is no longer valid and was removed.");
    } else {
        $v_stmt = $db->prepare('SELECT * FROM `voucher` WHERE `code` = ? AND `is_active` = 1 AND `expires_at` > NOW() LIMIT 1');
        $v_stmt->execute([$applied_code]);
        $voucher = $v_stmt->fetch();

        if ($voucher && $voucher->used_count < $voucher->usage_limit && $subtotal >= (float)$voucher->min_spend) {
            $applied_voucher = $voucher;
            if ($voucher->discount_type === 'percentage') {
                $discount_amount = ($subtotal * ((float)$voucher->discount_value / 100));
                if ($voucher->max_discount) {
                    $discount_amount = min($discount_amount, (float)$voucher->max_discount);
                }
            } else {
                $discount_amount = min($subtotal, (float)$voucher->discount_value);
            }
        } else {
            unset($_SESSION['applied_voucher']);
            set_flash('warning', "Voucher {$applied_code} is no longer valid and was removed.");
        }
    }
}

// Shipping calculation (Dynamic settings from store configuration)
$free_shipping_threshold = (float)setting('free_shipping_threshold', '150');
$standard_shipping_fee   = (float)setting('standard_shipping_fee', '15');
$shipping_fee = ($subtotal >= $free_shipping_threshold || $subtotal == 0) ? 0 : $standard_shipping_fee;
$final_total  = max(0, ($subtotal - $discount_amount) + $shipping_fee);

$title = 'My Shopping Cart';
require_once __DIR__ . '/_head.php';
?>

<div class="container container-detail">
    <!-- Title -->
    <div class="mb-24">
        <h1 class="cart-title">Your Shopping Cart</h1>
        <p class="cart-subtitle">Review your tech gadgets, apply discount vouchers, and proceed to checkout.</p>
    </div>

    <?php if (empty($cart_items)): ?>
        <!-- Empty Cart State -->
        <div class="cart-empty-panel">
            <div class="empty-state-icon">🛒</div>
            <h2 class="empty-state-title">Your cart is currently empty</h2>
            <p class="empty-state-desc">Browse our curated collection of power banks, mechanical keyboards, and precision gear.</p>
            <a href="<?= base_url('index.php') ?>" class="btn btn-primary">Browse sumthin Catalog &rarr;</a>
        </div>
    <?php else: ?>
        <!-- Cart Active Grid -->
        <div class="cart-grid">
            <!-- Left: Cart Items Table -->
            <div class="cart-items-card">
                <div class="cart-items-header">
                    <span class="cart-items-count"><?= count($cart_items) ?> Distinct Item(s) in Cart</span>
                    <form action="<?= base_url('cart_action.php') ?>" method="POST" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="clear">
                        <button type="submit" class="btn btn-outline-sm btn-clear-cart" data-confirm="Empty your entire shopping cart?">
                            Clear Cart
                        </button>
                    </form>
                </div>

                <div class="cart-items-list">
                    <?php foreach ($cart_items as $item): ?>
                        <div class="cart-item-row">
                            <!-- Photo -->
                            <div class="cart-item-thumb">
                                <img src="<?= base_url('uploads/products/' . encode($item->photo_url)) ?>" alt="<?= encode($item->name) ?>" class="cart-item-img">
                            </div>

                            <!-- Details -->
                            <div class="cart-item-info">
                                <span class="cart-item-category"><?= encode($item->category_name) ?></span>
                                <h3 class="cart-item-title">
                                    <a href="<?= base_url('product.php?id=' . (int)$item->product_id) ?>" class="product-title-link">
                                        <?= encode($item->name) ?>
                                    </a>
                                </h3>
                                <div class="cart-item-unit-price">
                                    RM <?= number_format((float)$item->price, 2) ?> each
                                </div>
                            </div>

                            <!-- Quantity Selector Form -->
                            <div>
                                <form action="<?= base_url('cart_action.php') ?>" method="POST" class="cart-qty-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="cart_id" value="<?= (int)$item->cart_id ?>">
                                    <input type="number" name="quantity" value="<?= (int)$item->quantity ?>" min="1" max="<?= min(10, (int)$item->stock) ?>" class="form-control cart-qty-input">
                                    <button type="submit" class="btn btn-outline-sm btn-qty-update" title="Update quantity">✓</button>
                                </form>
                            </div>

                            <!-- Subtotal & Remove -->
                            <div class="cart-item-subtotal-col">
                                <div class="cart-item-subtotal">
                                    RM <?= number_format((float)($item->price * $item->quantity), 2) ?>
                                </div>
                                <form action="<?= base_url('cart_action.php') ?>" method="POST" class="mt-6">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="cart_id" value="<?= (int)$item->cart_id ?>">
                                    <button type="submit" class="btn-remove-item" data-confirm="Remove this item?">
                                        &times; Remove
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="mt-16">
                    <a href="<?= base_url('index.php') ?>" class="form-link">&larr; Continue Shopping</a>
                </div>
            </div>

            <!-- Right: Order Summary Card -->
            <div class="cart-summary-card">
                <h2 class="cart-summary-heading">
                    Order Summary
                </h2>

                <!-- Voucher Code Box -->
                <div class="cart-voucher-box">
                    <span class="cart-voucher-label">
                        Have a Discount Voucher?
                    </span>
                    <?php if ($applied_voucher): ?>
                        <div class="voucher-applied-pill">
                            <span>Applied: <?= encode($applied_voucher->code) ?></span>
                            <form action="<?= base_url('cart.php') ?>" method="POST" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="voucher_action" value="remove">
                                <button type="submit" class="btn-voucher-remove">&times; Remove</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <form action="<?= base_url('cart.php') ?>" method="POST" class="voucher-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="voucher_action" value="apply">
                            <input type="text" name="voucher_code" placeholder="e.g. SUMTHIN10" class="form-control voucher-input">
                            <button type="submit" class="btn btn-outline-sm btn-voucher-apply">Apply</button>
                        </form>
                        <span class="voucher-hint">Try demo codes: <code>SUMTHIN10</code> or <code>TECHSAVE30</code></span>
                    <?php endif; ?>
                </div>

                <!-- Price Breakdown Table -->
                <div class="cart-breakdown-list">
                    <div class="flex-between">
                        <span class="text-muted">Items Subtotal:</span>
                        <strong>RM <?= number_format((float)$subtotal, 2) ?></strong>
                    </div>

                    <?php if ($discount_amount > 0): ?>
                        <div class="flex-between text-success">
                            <span>Voucher Savings:</span>
                            <strong>- RM <?= number_format((float)$discount_amount, 2) ?></strong>
                        </div>
                    <?php endif; ?>

                    <div class="flex-between">
                        <span class="text-muted">Shipping:</span>
                        <?php if ($shipping_fee == 0): ?>
                            <span class="shipping-free-text">FREE (Orders &ge; RM <?= number_format($free_shipping_threshold, 0) ?>)</span>
                        <?php else: ?>
                            <span>RM <?= number_format($standard_shipping_fee, 2) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Grand Total -->
                <div class="cart-total-row">
                    <span class="cart-total-label">Estimated Total:</span>
                    <span class="cart-total-val">
                        RM <?= number_format((float)$final_total, 2) ?>
                    </span>
                </div>

                <!-- Checkout CTA -->
                <a href="<?= base_url('checkout.php') ?>" class="btn btn-primary btn-block btn-checkout-cta">
                    Proceed to Checkout &rarr;
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/_foot.php'; ?>
