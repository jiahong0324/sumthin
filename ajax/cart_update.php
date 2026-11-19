<?php
// ============================================================================
// File: ajax/cart_update.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Shopping Cart AJAX Operations
// Description: Modify cart item quantities via AJAX, updating line item total,
//              subtotal, voucher calculations, and navbar cart badge.
// ============================================================================
require_once __DIR__ . '/../_base.php';

// Method check: Must be POST
if (!is_post()) {
    json_response(['ok' => false, 'message' => '405 Method Not Allowed: POST request required.'], 405);
}

// Authentication check: Must be logged in
if (!is_logged_in()) {
    json_response(['ok' => false, 'message' => 'Please sign in to modify your cart.'], 401);
}

// Authorization check: Only Members can shop
if (!is_member()) {
    json_response(['ok' => false, 'message' => '403 Forbidden: Only members can modify shopping cart.'], 403);
}

// CSRF verification
verify_csrf();

$user_id  = (int)current_user()->id;
$cart_id  = (int)req('cart_id');
$quantity = (int)req('quantity');

if ($cart_id <= 0) {
    json_response(['ok' => false, 'message' => 'Invalid cart item ID.'], 422);
}

// Fetch target cart item belonging to this user
$stmt = $db->prepare('
    SELECT ci.*, p.name, p.price, p.stock
    FROM `cart_item` ci
    JOIN `product` p ON ci.product_id = p.id
    WHERE ci.id = ? AND ci.user_id = ?
');
$stmt->execute([$cart_id, $user_id]);
$item = $stmt->fetch();

if (!$item) {
    json_response(['ok' => false, 'message' => 'Cart item not found.'], 422);
}

$removed = false;
$line_item_total = 0.00;

if ($quantity <= 0) {
    // Remove item
    $del = $db->prepare('DELETE FROM `cart_item` WHERE id = ? AND user_id = ?');
    $del->execute([$cart_id, $user_id]);
    $removed = true;
} else {
    // Check available stock
    if ($quantity > (int)$item->stock) {
        json_response([
            'ok'      => false,
            'message' => "Requested quantity exceeds available stock ({$item->stock} units left).",
            'stock'   => (int)$item->stock
        ], 422);
    }

    $upd = $db->prepare('UPDATE `cart_item` SET quantity = ? WHERE id = ?');
    $upd->execute([$quantity, $cart_id]);
    $line_item_total = (float)$item->price * $quantity;
}

// Recalculate full cart subtotal
$cart_items_stmt = $db->prepare('
    SELECT ci.quantity, p.price
    FROM `cart_item` ci
    JOIN `product` p ON ci.product_id = p.id
    WHERE ci.user_id = ?
');
$cart_items_stmt->execute([$user_id]);
$all_items = $cart_items_stmt->fetchAll();

$subtotal = 0.0;
$cart_count = 0;
foreach ($all_items as $ci) {
    $subtotal += ((float)$ci->price * (int)$ci->quantity);
    $cart_count += (int)$ci->quantity;
}

// Recalculate voucher discount if voucher is in session
$voucher_discount = 0.0;
$voucher_code     = $_SESSION['applied_voucher'] ?? null;
if ($voucher_code && $subtotal > 0) {
    $v_stmt = $db->prepare('SELECT * FROM `voucher` WHERE `code` = ? AND `is_active` = 1 AND `expires_at` > NOW() LIMIT 1');
    $v_stmt->execute([$voucher_code]);
    $voucher = $v_stmt->fetch();

    if ($voucher && $voucher->used_count < $voucher->usage_limit && $subtotal >= (float)$voucher->min_spend) {
        if ($voucher->discount_type === 'percentage') {
            $voucher_discount = ($subtotal * ((float)$voucher->discount_value / 100));
            if ($voucher->max_discount) {
                $voucher_discount = min($voucher_discount, (float)$voucher->max_discount);
            }
        } else {
            $voucher_discount = min($subtotal, (float)$voucher->discount_value);
        }
    } else {
        unset($_SESSION['applied_voucher']);
    }
}

$final_total = max(0, $subtotal - $voucher_discount);

json_response([
    'ok'              => true,
    'message'         => $removed ? "Removed '{$item->name}' from cart." : "Updated '{$item->name}' quantity.",
    'removed'         => $removed,
    'cart_id'         => $cart_id,
    'quantity'        => $quantity,
    'item_total'      => 'RM ' . number_format($line_item_total, 2),
    'subtotal'        => 'RM ' . number_format($subtotal, 2),
    'discount'        => 'RM ' . number_format($voucher_discount, 2),
    'final_total'     => 'RM ' . number_format($final_total, 2),
    'cart_count'      => $cart_count
]);
