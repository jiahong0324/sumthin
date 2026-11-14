<?php
// ============================================================================
// File: cart_action.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Shopping Cart (Cart Mutations)
// Description: Handles cart addition, quantity updates, and deletions stored
//              persistently in the MySQL cart_item table for authenticated members.
// ============================================================================
require_once __DIR__ . '/_base.php';

// Must be signed in as a Member to add/update cart
if (!is_logged_in()) {
    $_SESSION['redirect_after_login'] = $_SERVER['HTTP_REFERER'] ?? base_url('index.php');
    set_flash('warning', 'Please sign in to add items to your shopping cart.');
    redirect(base_url('login.php'));
}

if (!is_member()) {
    set_flash('warning', 'Administrators cannot add items to a shopping cart.');
    redirect(base_url('index.php'));
}

if (is_post()) {
    verify_csrf();

    $action  = req('action');
    $user_id = $_SESSION['user']->id;

    // 1. Add to Cart
    if ($action === 'add') {
        $product_id = (int)req('product_id');
        $quantity   = max(1, (int)req('quantity', 1));

        $stmt = $db->prepare('SELECT `id`, `name`, `stock`, `price` FROM `product` WHERE `id` = ?');
        $stmt->execute([$product_id]);
        $prod = $stmt->fetch();

        if ($prod && $prod->stock > 0) {
            // Check if already in cart
            $chk = $db->prepare('SELECT `id`, `quantity` FROM `cart_item` WHERE `user_id` = ? AND `product_id` = ?');
            $chk->execute([$user_id, $product_id]);
            $existing = $chk->fetch();

            if ($existing) {
                $new_qty = min($prod->stock, $existing->quantity + $quantity);
                $up = $db->prepare('UPDATE `cart_item` SET `quantity` = ? WHERE `id` = ?');
                $up->execute([$new_qty, $existing->id]);
            } else {
                $ins_qty = min($prod->stock, $quantity);
                $ins = $db->prepare('INSERT INTO `cart_item` (`user_id`, `product_id`, `quantity`) VALUES (?, ?, ?)');
                $ins->execute([$user_id, $product_id, $ins_qty]);
            }

            set_flash('success', "Added '{$prod->name}' to your cart!");
        } else {
            set_flash('error', 'Sorry, that product is currently out of stock.');
        }

        if (req('buy_now') === '1') {
            redirect(base_url('checkout.php'));
        }
        redirect(base_url('cart.php'));
    }

    // 2. Update Cart Item Quantity
    elseif ($action === 'update') {
        $cart_id  = (int)req('cart_id');
        $quantity = (int)req('quantity');

        if ($quantity <= 0) {
            $del = $db->prepare('DELETE FROM `cart_item` WHERE `id` = ? AND `user_id` = ?');
            $del->execute([$cart_id, $user_id]);
            set_flash('info', 'Item removed from cart.');
        } else {
            // Check product stock limit
            $chk = $db->prepare('
                SELECT ci.id, p.stock 
                FROM `cart_item` ci 
                JOIN `product` p ON ci.product_id = p.id 
                WHERE ci.id = ? AND ci.user_id = ?
            ');
            $chk->execute([$cart_id, $user_id]);
            $item = $chk->fetch();

            if ($item) {
                $final_qty = min($item->stock, $quantity);
                $up = $db->prepare('UPDATE `cart_item` SET `quantity` = ? WHERE `id` = ?');
                $up->execute([$final_qty, $cart_id]);
                set_flash('info', 'Cart quantity updated.');
            }
        }

        redirect(base_url('cart.php'));
    }

    // 3. Remove Single Cart Item
    elseif ($action === 'remove') {
        $cart_id = (int)req('cart_id');
        $del = $db->prepare('DELETE FROM `cart_item` WHERE `id` = ? AND `user_id` = ?');
        $del->execute([$cart_id, $user_id]);
        set_flash('info', 'Item removed from your cart.');
        redirect(base_url('cart.php'));
    }

    // 4. Clear Entire Cart
    elseif ($action === 'clear') {
        $del = $db->prepare('DELETE FROM `cart_item` WHERE `user_id` = ?');
        $del->execute([$user_id]);
        set_flash('info', 'Your shopping cart has been emptied.');
        redirect(base_url('cart.php'));
    }
}

redirect(base_url('cart.php'));
