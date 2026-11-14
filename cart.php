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
            $stmt = $db->prepare('SELECT * FROM `voucher` WHERE `code` = ? AND `expires_at` > NOW() LIMIT 1');
            $stmt->execute([$code]);
            $voucher = $stmt->fetch();

            if ($voucher && $voucher->used_count < $voucher->usage_limit) {
                $_SESSION['applied_voucher'] = $voucher;
                set_flash('success', "Voucher '{$voucher->code}' applied successfully!");
            } else {
                unset($_SESSION['applied_voucher']);
                set_flash('error', 'Invalid or expired voucher code.');
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

// Evaluate Voucher Discount if applicable
$discount_amount = 0;
$applied_voucher = $_SESSION['applied_voucher'] ?? null;

if ($applied_voucher && $subtotal > 0) {
    if ($subtotal < $applied_voucher->min_spend) {
        $min_spend = number_format($applied_voucher->min_spend, 2);
        set_flash('warning', "Voucher '{$applied_voucher->code}' requires a minimum spend of RM {$min_spend}.");
        unset($_SESSION['applied_voucher']);
        $applied_voucher = null;
    } else {
        if ($applied_voucher->discount_type === 'percentage') {
            $discount_amount = ($subtotal * ($applied_voucher->discount_value / 100));
            if ($applied_voucher->max_discount) {
                $discount_amount = min($discount_amount, (float)$applied_voucher->max_discount);
            }
        } else {
            $discount_amount = min($subtotal, (float)$applied_voucher->discount_value);
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

<div class="container" style="max-width: 1050px;">
    <!-- Title -->
    <div style="margin-bottom: 24px;">
        <h1 style="font-size:28px; font-weight:800; color:var(--dark);">Your Shopping Cart</h1>
        <p style="font-size:14px; color:var(--gray);">Review your tech gadgets, apply discount vouchers, and proceed to checkout.</p>
    </div>

    <?php if (empty($cart_items)): ?>
        <!-- Empty Cart State -->
        <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius-lg); padding:60px 20px; text-align:center; box-shadow:var(--shadow-sm);">
            <div style="font-size:48px; margin-bottom:12px;">🛒</div>
            <h2 style="font-size:20px; font-weight:800; color:var(--dark); margin-bottom:8px;">Your cart is currently empty</h2>
            <p style="font-size:14px; color:var(--gray); margin-bottom:24px;">Browse our curated collection of power banks, mechanical keyboards, and precision gear.</p>
            <a href="<?= base_url('index.php') ?>" class="btn btn-primary">Browse sumthin Catalog &rarr;</a>
        </div>
    <?php else: ?>
        <!-- Cart Active Grid -->
        <div style="display:grid; grid-template-columns: 2fr 1.1fr; gap:32px; align-items:flex-start;">
            <!-- Left: Cart Items Table -->
            <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:24px; box-shadow:var(--shadow-sm);">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-bottom:1px solid var(--border); padding-bottom:12px;">
                    <span style="font-weight:700; color:var(--dark);"><?= count($cart_items) ?> Distinct Item(s) in Cart</span>
                    <form action="<?= base_url('cart_action.php') ?>" method="POST" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="clear">
                        <button type="submit" class="btn btn-outline-sm" style="color:var(--error); border-color:#fca5a5; font-size:11px;" data-confirm="Empty your entire shopping cart?">
                            Clear Cart
                        </button>
                    </form>
                </div>

                <div style="display:flex; flex-direction:column; gap:20px;">
                    <?php foreach ($cart_items as $item): ?>
                        <div style="display:flex; gap:16px; align-items:center; border-bottom:1px solid #f1f5f9; padding-bottom:16px;">
                            <!-- Photo -->
                            <div style="width:72px; height:72px; background:#0b0f19; border-radius:8px; overflow:hidden; flex-shrink:0; display:flex; align-items:center; justify-content:center; border:1px solid #1e293b;">
                                <img src="<?= base_url('uploads/products/' . encode($item->photo_url)) ?>" alt="<?= encode($item->name) ?>" style="max-width:100%; max-height:100%; object-fit:contain;">
                            </div>

                            <!-- Details -->
                            <div style="flex:1;">
                                <span style="font-size:11px; text-transform:uppercase; font-weight:700; color:var(--primary);"><?= encode($item->category_name) ?></span>
                                <h3 style="font-size:15px; font-weight:700; color:var(--dark); margin:2px 0 4px 0;">
                                    <a href="<?= base_url('product.php?id=' . (int)$item->product_id) ?>" style="text-decoration:none; color:inherit;">
                                        <?= encode($item->name) ?>
                                    </a>
                                </h3>
                                <div style="font-size:13px; color:var(--gray);">
                                    RM <?= number_format((float)$item->price, 2) ?> each
                                </div>
                            </div>

                            <!-- Quantity Selector Form -->
                            <div>
                                <form action="<?= base_url('cart_action.php') ?>" method="POST" style="display:flex; align-items:center; gap:6px;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="cart_id" value="<?= (int)$item->cart_id ?>">
                                    <input type="number" name="quantity" value="<?= (int)$item->quantity ?>" min="1" max="<?= min(10, (int)$item->stock) ?>" class="form-control" style="width:65px; text-align:center; padding:6px;">
                                    <button type="submit" class="btn btn-outline-sm" style="padding:6px 10px; font-size:12px;" title="Update quantity">✓</button>
                                </form>
                            </div>

                            <!-- Subtotal & Remove -->
                            <div style="text-align:right; min-width:90px;">
                                <div style="font-size:16px; font-weight:800; color:var(--dark);">
                                    RM <?= number_format((float)($item->price * $item->quantity), 2) ?>
                                </div>
                                <form action="<?= base_url('cart_action.php') ?>" method="POST" style="margin-top:6px;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="cart_id" value="<?= (int)$item->cart_id ?>">
                                    <button type="submit" style="background:transparent; border:none; color:var(--error); font-size:12px; cursor:pointer;" data-confirm="Remove this item?">
                                        &times; Remove
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top:16px;">
                    <a href="<?= base_url('index.php') ?>" class="form-link">&larr; Continue Shopping</a>
                </div>
            </div>

            <!-- Right: Order Summary Card -->
            <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:24px; box-shadow:var(--shadow-sm);">
                <h2 style="font-size:18px; font-weight:800; color:var(--dark); margin-bottom:16px; border-bottom:1px solid var(--border); padding-bottom:10px;">
                    Order Summary
                </h2>

                <!-- Voucher Code Box -->
                <div style="margin-bottom:20px; background:#f8fafc; border:1px dashed var(--border-dark); border-radius:var(--radius-sm); padding:14px;">
                    <span style="font-size:12px; font-weight:700; color:var(--dark); display:block; margin-bottom:6px;">
                        Have a Discount Voucher?
                    </span>
                    <?php if ($applied_voucher): ?>
                        <div style="display:flex; justify-content:space-between; align-items:center; background:#dcfce7; padding:8px 12px; border-radius:6px; font-size:12px; color:#15803d; font-weight:700;">
                            <span>Applied: <?= encode($applied_voucher->code) ?></span>
                            <form action="<?= base_url('cart.php') ?>" method="POST" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="voucher_action" value="remove">
                                <button type="submit" style="background:transparent; border:none; color:#b91c1c; font-size:12px; cursor:pointer; font-weight:700;">&times; Remove</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <form action="<?= base_url('cart.php') ?>" method="POST" style="display:flex; gap:8px;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="voucher_action" value="apply">
                            <input type="text" name="voucher_code" placeholder="e.g. SUMTHIN10" class="form-control" style="font-size:12px; text-transform:uppercase; padding:6px 10px;">
                            <button type="submit" class="btn btn-outline-sm" style="font-size:12px; padding:6px 12px;">Apply</button>
                        </form>
                        <span style="font-size:11px; color:var(--gray); margin-top:4px; display:block;">Try demo codes: <code>SUMTHIN10</code> or <code>TECHSAVE30</code></span>
                    <?php endif; ?>
                </div>

                <!-- Price Breakdown Table -->
                <div style="display:flex; flex-direction:column; gap:10px; font-size:14px; margin-bottom:20px; border-bottom:1px solid var(--border); padding-bottom:16px;">
                    <div style="display:flex; justify-content:space-between;">
                        <span style="color:var(--gray);">Items Subtotal:</span>
                        <strong>RM <?= number_format((float)$subtotal, 2) ?></strong>
                    </div>

                    <?php if ($discount_amount > 0): ?>
                        <div style="display:flex; justify-content:space-between; color:var(--success);">
                            <span>Voucher Savings:</span>
                            <strong>- RM <?= number_format((float)$discount_amount, 2) ?></strong>
                        </div>
                    <?php endif; ?>

                    <div style="display:flex; justify-content:space-between;">
                        <span style="color:var(--gray);">Shipping:</span>
                        <?php if ($shipping_fee == 0): ?>
                            <span style="color:var(--success); font-weight:700;">FREE (Orders &ge; RM <?= number_format($free_shipping_threshold, 0) ?>)</span>
                        <?php else: ?>
                            <span>RM <?= number_format($standard_shipping_fee, 2) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Grand Total -->
                <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:24px;">
                    <span style="font-size:16px; font-weight:800; color:var(--dark);">Estimated Total:</span>
                    <span style="font-size:26px; font-weight:900; color:var(--primary);">
                        RM <?= number_format((float)$final_total, 2) ?>
                    </span>
                </div>

                <!-- Checkout CTA -->
                <a href="<?= base_url('checkout.php') ?>" class="btn btn-primary btn-block" style="padding:12px; font-size:15px;">
                    Proceed to Checkout &rarr;
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/_foot.php'; ?>
