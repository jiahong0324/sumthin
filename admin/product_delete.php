<?php
// ============================================================================
// File: admin/product_delete.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Product Maintenance (Admin Delete Product)
// Description: Securely remove products, verify foreign key dependencies, and
//              delete associated photo assets safely without runtime crashes.
// ============================================================================
require_once __DIR__ . '/../_base.php';

auth('Admin');

if (is_post()) {
    verify_csrf();

    $id = (int)req('id');

    if ($id <= 0) {
        set_flash('error', 'Invalid product ID.');
        redirect(base_url('admin/products.php'));
    }

    // 1. Fetch product
    $stmt = $db->prepare('SELECT * FROM `product` WHERE `id` = ?');
    $stmt->execute([$id]);
    $product = $stmt->fetch();

    if (!$product) {
        set_flash('error', 'Product not found or already deleted.');
        redirect(base_url('admin/products.php'));
    }

    // 2. Foreign Key Check: Has this product been purchased in customer orders?
    $check_orders = $db->prepare('SELECT COUNT(*) FROM `order_item` WHERE `product_id` = ?');
    $check_orders->execute([$id]);
    $order_count = (int)$check_orders->fetchColumn();

    if ($order_count > 0) {
        set_flash('warning', "Cannot delete '{$product->name}' because it is linked to {$order_count} existing order record(s). Please reduce stock to 0 to discontinue selling it instead.");
        redirect(base_url('admin/products.php'));
    }

    // 3. Fetch photos to remove files from disk
    $photo_stmt = $db->prepare('SELECT `photo_url` FROM `product_photo` WHERE `product_id` = ?');
    $photo_stmt->execute([$id]);
    $photos = $photo_stmt->fetchAll();

    try {
        // Delete product from database (ON DELETE CASCADE handles product_photo, cart_item, wishlist)
        $del = $db->prepare('DELETE FROM `product` WHERE `id` = ?');
        $del->execute([$id]);

        // Remove photo files from disk if custom
        foreach ($photos as $ph) {
            if ($ph->photo_url && !str_starts_with($ph->photo_url, 'default')) {
                $file_path = __DIR__ . '/../uploads/products/' . $ph->photo_url;
                if (file_exists($file_path)) {
                    @unlink($file_path);
                }
            }
        }

        set_flash('success', "Product '{$product->name}' was deleted successfully.");
    } catch (PDOException $e) {
        set_flash('error', 'Database error while deleting product: ' . $e->getMessage());
    }
}

redirect(base_url('admin/products.php'));
