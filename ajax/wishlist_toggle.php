<?php
// ============================================================================
// File: ajax/wishlist_toggle.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Wishlist AJAX Operations
// Description: Toggle saved tech gadgets in member wishlist via AJAX.
// ============================================================================
require_once __DIR__ . '/../_base.php';

// Method check: Must be POST
if (!is_post()) {
    json_response(['ok' => false, 'message' => '405 Method Not Allowed: POST request required.'], 405);
}

// Authentication check: Must be logged in
if (!is_logged_in()) {
    json_response(['ok' => false, 'message' => 'Please sign in to save items to your wishlist.', 'login_url' => base_url('login.php')], 401);
}

// Authorization check: Only Members have wishlists
if (!is_member()) {
    json_response(['ok' => false, 'message' => '403 Forbidden: Administrator accounts do not have wishlists.'], 403);
}

// CSRF verification
verify_csrf();

$product_id = (int)req('product_id');
$user_id    = (int)current_user()->id;

if ($product_id <= 0) {
    json_response(['ok' => false, 'message' => 'Invalid product ID.'], 422);
}

// Check product exists
$p_stmt = $db->prepare('SELECT id, name FROM `product` WHERE id = ?');
$p_stmt->execute([$product_id]);
$product = $p_stmt->fetch();

if (!$product) {
    json_response(['ok' => false, 'message' => 'Product not found.'], 422);
}

// Check current wishlist status
$w_stmt = $db->prepare('SELECT id FROM `wishlist` WHERE user_id = ? AND product_id = ?');
$w_stmt->execute([$user_id, $product_id]);
$existing = $w_stmt->fetch();

if ($existing) {
    $del = $db->prepare('DELETE FROM `wishlist` WHERE id = ?');
    $del->execute([$existing->id]);

    json_response([
        'ok'          => true,
        'in_wishlist' => false,
        'message'     => "Removed '{$product->name}' from your wishlist."
    ]);
} else {
    $ins = $db->prepare('INSERT INTO `wishlist` (user_id, product_id, created_at) VALUES (?, ?, NOW())');
    $ins->execute([$user_id, $product_id]);

    json_response([
        'ok'          => true,
        'in_wishlist' => true,
        'message'     => "Saved '{$product->name}' to your wishlist!"
    ]);
}
