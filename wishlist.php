<?php
// ============================================================================
// File: wishlist.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Customer Experience (Favorites / Wishlist)
// Description: Allows members to bookmark gadgets, review saved items, and easily
//              move them directly into their shopping cart.
// ============================================================================
require_once __DIR__ . '/_base.php';

auth('Member');

$user_id = $_SESSION['user']->id;

// Handle Add / Remove Post Action
if (is_post()) {
    verify_csrf();

    $action     = req('action');
    $product_id = (int)req('product_id');

    if ($product_id > 0) {
        if ($action === 'add') {
            $chk = $db->prepare('SELECT COUNT(*) FROM `wishlist` WHERE `user_id` = ? AND `product_id` = ?');
            $chk->execute([$user_id, $product_id]);
            if ($chk->fetchColumn() == 0) {
                $ins = $db->prepare('INSERT INTO `wishlist` (`user_id`, `product_id`) VALUES (?, ?)');
                $ins->execute([$user_id, $product_id]);
                set_flash('success', 'Gadget saved to your wishlist!');
            }
        } elseif ($action === 'remove') {
            $del = $db->prepare('DELETE FROM `wishlist` WHERE `user_id` = ? AND `product_id` = ?');
            $del->execute([$user_id, $product_id]);
            set_flash('info', 'Item removed from your wishlist.');
        }
    }

    $redirect_url = $_SERVER['HTTP_REFERER'] ?? base_url('wishlist.php');
    redirect($redirect_url);
}

// Fetch member wishlist items
$stmt = $db->prepare("
    SELECT w.id AS wishlist_id, p.*, c.name AS category_name, b.name AS brand_name,
           COALESCE(pp.photo_url, 'default_product.png') AS photo_url
    FROM `wishlist` w
    JOIN `product` p ON w.product_id = p.id
    JOIN `category` c ON p.category_id = c.id
    LEFT JOIN `brand` b ON p.brand_id = b.id
    LEFT JOIN `product_photo` pp ON p.id = pp.product_id AND pp.is_primary = 1
    WHERE w.user_id = ?
    ORDER BY w.id DESC
");
$stmt->execute([$user_id]);
$items = $stmt->fetchAll();

$title = 'My Wishlist';
require_once __DIR__ . '/_head.php';
?>

<div class="container container-history">
    <!-- Title -->
    <div class="mb-24">
        <h1 class="page-header-title">My Saved Wishlist</h1>
        <p class="page-header-desc">Tech gadgets you have bookmarked for future purchase.</p>
    </div>

    <?php if (empty($items)): ?>
        <div class="empty-state-panel">
            <div class="empty-state-icon">❤️</div>
            <h2 class="empty-state-title">Your wishlist is currently empty</h2>
            <p class="empty-state-desc mb-20">Save products while browsing to keep track of items you love.</p>
            <a href="<?= base_url('index.php') ?>" class="btn btn-primary">Browse sumthin Catalog &rarr;</a>
        </div>
    <?php else: ?>
        <div class="product-grid">
            <?php foreach ($items as $p): ?>
                <article class="product-card">
                    <div class="product-card-image-wrap">
                        <img src="<?= base_url('uploads/products/' . encode($p->photo_url)) ?>" alt="<?= encode($p->name) ?>" class="product-card-img">
                    </div>

                    <div class="product-category-tag">
                        <?= encode($p->brand_name ?? 'sumthin') ?> &bull; <?= encode($p->category_name) ?>
                    </div>

                    <h3 class="product-name">
                        <a href="<?= base_url('product.php?id=' . (int)$p->id) ?>" class="product-title-link">
                            <?= encode($p->name) ?>
                        </a>
                    </h3>

                    <div class="product-footer mt-auto">
                        <span class="product-price">RM <?= number_format((float)$p->price, 2) ?></span>
                        <div class="product-card-actions">
                            <?php if ($p->stock > 0): ?>
                                <form action="<?= base_url('cart_action.php') ?>" method="POST">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="add">
                                    <input type="hidden" name="product_id" value="<?= (int)$p->id ?>">
                                    <input type="hidden" name="quantity" value="1">
                                    <button type="submit" class="btn btn-primary-sm fs-11">+ Cart</button>
                                </form>
                            <?php endif; ?>

                            <form action="<?= base_url('wishlist.php') ?>" method="POST">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="product_id" value="<?= (int)$p->id ?>">
                                <button type="submit" class="btn btn-outline-sm fs-11 text-danger" title="Remove">&times;</button>
                            </form>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/_foot.php'; ?>
