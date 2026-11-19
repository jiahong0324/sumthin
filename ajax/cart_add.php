<?php
// ============================================================================
// File: ajax/cart_add.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Shopping Cart AJAX Operations
// Description: Add item to member shopping cart with stock check, returning
//              structured JSON and updated cart count.
// ============================================================================
require_once __DIR__ . '/../_base.php';

// Method check: Must be POST
if (!is_post()) {
    json_response(['ok' => false, 'message' => '405 Method Not Allowed: POST request required.'], 405);
}

// Authentication check: Must be logged in
if (!is_logged_in()) {
    json_response(['ok' => false, 'message' => 'Please sign in to add items to your cart.', 'login_url' => base_url('login.php')], 401);
}

// Authorization check: Only Members can shop
if (!is_member()) {
    json_response(['ok' => false, 'message' => '403 Forbidden: Administrator accounts cannot add items to cart.'], 403);
}

// CSRF verification
verify_csrf();

$product_id = (int)req('product_id');
$quantity   = max(1, (int)req('quantity', 1));
$user_id    = (int)current_user()->id;

if ($product_id <= 0) {
    json_response(['ok' => false, 'message' => 'Invalid product selected.'], 422);
}

// Fetch product & stock
$stmt = $db->prepare('SELECT id, name, price, stock FROM `product` WHERE id = ?');
$stmt->execute([$product_id]);
$product = $stmt->fetch();

if (!$product) {
    json_response(['ok' => false, 'message' => 'Product not found.'], 422);
}

if ($product->stock < 1) {
    json_response(['ok' => false, 'message' => "Sorry, '{$product->name}' is currently out of stock."], 422);
}

// Check existing cart item
$chk = $db->prepare('SELECT id, quantity FROM `cart_item` WHERE user_id = ? AND product_id = ?');
$chk->execute([$user_id, $product_id]);
$existing = $chk->fetch();

$current_cart_qty = $existing ? (int)$existing->quantity : 0;
$target_qty       = $current_cart_qty + $quantity;

if ($target_qty > (int)$product->stock) {
    $available_to_add = max(0, (int)$product->stock - $current_cart_qty);
    if ($available_to_add > 0) {
        $msg = "Only {$available_to_add} more unit(s) of '{$product->name}' can be added (you have {$current_cart_qty} in cart).";
    } else {
        $msg = "You already have all available units ({$current_cart_qty}) in your cart.";
    }
    json_response(['ok' => false, 'message' => $msg], 422);
}

// Update or insert into cart
if ($existing) {
    $upd = $db->prepare('UPDATE `cart_item` SET quantity = ? WHERE id = ?');
    $upd->execute([$target_qty, $existing->id]);
} else {
    $ins = $db->prepare('INSERT INTO `cart_item` (user_id, product_id, quantity) VALUES (?, ?, ?)');
    $ins->execute([$user_id, $product_id, $quantity]);
}

$new_cart_count = cart_count();

json_response([
    'ok'         => true,
    'message'    => "Added {$quantity}x '{$product->name}' to cart!",
    'cart_count' => $new_cart_count
]);
