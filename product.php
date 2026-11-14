<?php
// ============================================================================
// File: product.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Shopping Cart (Customer Product Detail)
// Description: Customer-facing product detail page with inventory checking,
//              quantity selection, review scores, and Add to Cart action.
// ============================================================================
require_once __DIR__ . '/_base.php';

$id = (int)req('id');
if ($id <= 0) {
    redirect(base_url('index.php'));
}

// Fetch product with category and primary photo
$stmt = $db->prepare("
    SELECT p.*, c.name AS category_name, c.slug AS category_slug, b.name AS brand_name,
           COALESCE(pp.photo_url, 'default_product.png') AS photo_url
    FROM `product` p
    JOIN `category` c ON p.category_id = c.id
    LEFT JOIN `brand` b ON p.brand_id = b.id
    LEFT JOIN `product_photo` pp ON p.id = pp.product_id AND pp.is_primary = 1
    WHERE p.id = ?
");
$stmt->execute([$id]);
$p = $stmt->fetch();

if (!$p) {
    redirect(base_url('404.php'));
}

// Fetch verified customer reviews
$rev_stmt = $db->prepare('
    SELECT r.*, u.name AS reviewer_name, u.photo AS reviewer_photo
    FROM `review` r
    JOIN `user` u ON r.user_id = u.id
    WHERE r.product_id = ?
    ORDER BY r.id DESC
');
$rev_stmt->execute([$id]);
$reviews = $rev_stmt->fetchAll();

$avg_rating = 0;
if (!empty($reviews)) {
    $sum = array_sum(array_map(fn($r) => $r->rating, $reviews));
    $avg_rating = round($sum / count($reviews), 1);
}

// Check if item is already in user wishlist
$in_wishlist = false;
if (is_logged_in()) {
    $w_check = $db->prepare('SELECT COUNT(*) FROM `wishlist` WHERE `user_id` = ? AND `product_id` = ?');
    $w_check->execute([$_SESSION['user']->id, $id]);
    $in_wishlist = ($w_check->fetchColumn() > 0);
}

$title = $p->name;
require_once __DIR__ . '/_head.php';
?>

<div class="container" style="max-width: 1050px;">
    <!-- Breadcrumbs -->
    <div style="font-size:13px; color:var(--gray); margin-bottom:20px;">
        <a href="<?= base_url('index.php') ?>" class="form-link">Shop</a> &rsaquo;
        <a href="<?= base_url('index.php?cat=' . encode($p->category_slug)) ?>" class="form-link"><?= encode($p->category_name) ?></a> &rsaquo;
        <span style="color:var(--dark);"><?= encode($p->name) ?></span>
    </div>

    <!-- Product Showcase Grid -->
    <div class="card-panel product-showcase-panel" style="display:grid; grid-template-columns: 1fr 1.2fr; gap:40px; background:#fff; border:1px solid var(--border); border-radius:var(--radius-lg); padding:36px; box-shadow:var(--shadow); margin-bottom:40px;">
        <!-- Left: Product Photo Box -->
        <div>
            <div style="background:#0b0f19; border-radius:12px; height:380px; display:flex; align-items:center; justify-content:center; border:1px solid #1e293b; overflow:hidden; padding:20px;">
                <img src="<?= base_url('uploads/products/' . encode($p->photo_url)) ?>" 
                     alt="<?= encode($p->name) ?>" 
                     style="max-width:100%; max-height:100%; object-fit:contain;">
            </div>
            <div style="margin-top:14px; display:flex; gap:12px; align-items:center; justify-content:center; font-size:13px; color:var(--gray);">
                <span>🛡️ 2-Year sumthin Official Warranty</span>
                <span>&bull;</span>
                <span>⚡ Same-Day Dispatch</span>
            </div>
        </div>

        <!-- Right: Purchasing Panel -->
        <div>
            <span class="role-pill pill-member" style="font-size:12px; margin-bottom:8px;">
                <?= encode($p->brand_name ?? 'sumthin') ?> &bull; <?= encode($p->category_name) ?>
            </span>

            <h1 style="font-size:28px; font-weight:800; color:var(--dark); margin:8px 0 12px 0; line-height:1.2;">
                <?= encode($p->name) ?>
            </h1>

            <!-- Ratings Summary -->
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:16px;">
                <span style="color:#d97706; font-size:16px;">
                    <?= str_repeat('★', (int)round($avg_rating ?: 5)) ?>
                </span>
                <span style="font-size:14px; font-weight:700; color:var(--dark);">
                    <?= $avg_rating ? $avg_rating : '5.0' ?> / 5.0
                </span>
                <span style="font-size:13px; color:var(--gray);">
                    (<?= count($reviews) ?> customer review<?= count($reviews) !== 1 ? 's' : '' ?>)
                </span>
            </div>

            <!-- Price -->
            <div style="font-size:36px; font-weight:900; color:var(--dark); margin-bottom:16px;">
                RM <?= number_format((float)$p->price, 2) ?>
            </div>

            <!-- Inventory Status -->
            <div style="margin-bottom:20px;">
                <?php if ($p->stock > 15): ?>
                    <span class="role-pill" style="background:#dcfce7; color:#15803d; font-size:13px;">✓ In Stock (<?= (int)$p->stock ?> available)</span>
                <?php elseif ($p->stock > 0): ?>
                    <span class="role-pill" style="background:#fef3c7; color:#b45309; font-size:13px;">⚠ Low Stock: Only <?= (int)$p->stock ?> left!</span>
                <?php else: ?>
                    <span class="role-pill pill-admin" style="font-size:13px;">✕ Currently Out of Stock</span>
                <?php endif; ?>
            </div>

            <!-- Description -->
            <div style="font-size:14px; color:var(--dark-muted); line-height:1.7; margin-bottom:24px; border-top:1px solid var(--border); padding-top:16px;">
                <?= nl2br(encode($p->description)) ?>
            </div>

            <!-- Shopee-Style Purchasing Section -->
            <?php if ($p->stock > 0): ?>
                <form action="<?= base_url('cart_action.php') ?>" method="POST" id="shopee-purchase-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="product_id" value="<?= (int)$p->id ?>">
                    <input type="hidden" name="buy_now" id="shopee-buy-now-input" value="0">

                    <!-- Shopee Quantity Stepper Row -->
                    <div class="shopee-qty-section">
                        <span class="shopee-section-label">Quantity</span>
                        <div class="shopee-stepper-wrap">
                            <div class="shopee-stepper">
                                <button type="button" class="shopee-step-btn btn-qty-minus" aria-label="Decrease quantity">&minus;</button>
                                <input type="text" name="quantity" id="quantity-input" value="1" min="1" max="<?= min(10, (int)$p->stock) ?>" class="shopee-step-input input-qty-value" readonly>
                                <button type="button" class="shopee-step-btn btn-qty-plus" aria-label="Increase quantity">+</button>
                            </div>
                            <span class="shopee-stock-count"><?= (int)$p->stock ?> pieces available</span>
                        </div>
                    </div>

                    <!-- Shopee Action Buttons Row -->
                    <div class="shopee-action-row">
                        <button type="submit" class="btn-shopee-cart btn-action-addcart">
                            <span class="shopee-cart-icon">🛒</span>
                            <span>Add To Cart</span>
                        </button>
                        <button type="submit" class="btn-shopee-buy btn-action-buynow">
                            Buy Now
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <div class="shopee-outofstock-box" style="margin-bottom:20px;">
                    <button type="button" class="btn btn-secondary btn-block" disabled style="cursor:not-allowed; height:48px; font-weight:700;">
                        Currently Out of Stock
                    </button>
                </div>
            <?php endif; ?>

            <!-- Shopee Guarantee & Wishlist Bar -->
            <div class="shopee-guarantee-bar">
                <form action="<?= base_url('wishlist.php') ?>" method="POST" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="<?= $in_wishlist ? 'remove' : 'add' ?>">
                    <input type="hidden" name="product_id" value="<?= (int)$p->id ?>">
                    <button type="submit" class="btn-shopee-wishlist-link">
                        <span class="shopee-heart"><?= $in_wishlist ? '❤️' : '🤍' ?></span>
                        <span><?= $in_wishlist ? 'Added to Wishlist' : 'Add to Wishlist' ?></span>
                    </button>
                </form>
                <span class="shopee-divider">|</span>
                <span class="shopee-trust-item">
                    <span class="trust-icon">🛡️</span> sumthin Mall Guarantee
                </span>
                <span class="shopee-divider">|</span>
                <span class="shopee-trust-item">
                    <span class="trust-icon">⚡</span> 15-Day Free Returns
                </span>
            </div>
        </div>
    </div>

    <!-- Verified Customer Reviews Section -->
    <section class="card-panel product-reviews-card" style="background:#fff; border:1px solid var(--border); border-radius:var(--radius-lg); padding:32px; box-shadow:var(--shadow-sm); margin-bottom:40px;">
        <div class="section-title-wrap">
            <h2 class="section-title">Verified Buyer Reviews (<?= count($reviews) ?>)</h2>
            <?php if (is_member()): ?>
                <a href="<?= base_url('review_add.php?product_id=' . (int)$p->id) ?>" class="btn btn-primary-sm">
                    Write a Review &rarr;
                </a>
            <?php endif; ?>
        </div>

        <?php if (empty($reviews)): ?>
            <p style="color:var(--gray); font-size:14px; text-align:center; padding:30px;">
                No customer reviews yet. Be the first verified buyer to leave a review!
            </p>
        <?php else: ?>
            <div style="display:flex; flex-direction:column; gap:20px;">
                <?php foreach ($reviews as $rev): ?>
                    <div style="border-bottom:1px solid var(--border); padding-bottom:16px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <div style="display:flex; align-items:center; gap:8px;">
                                <strong style="color:var(--dark);"><?= encode($rev->reviewer_name) ?></strong>
                                <span class="role-pill" style="background:#dcfce7; color:#15803d; font-size:10px;">Verified Buyer</span>
                            </div>
                            <span style="color:#d97706; font-size:14px;">
                                <?= str_repeat('★', (int)$rev->rating) ?><?= str_repeat('☆', 5 - (int)$rev->rating) ?>
                            </span>
                        </div>
                        <p style="font-size:14px; color:var(--dark-muted); line-height:1.6; margin-bottom:6px;">
                            <?= encode($rev->comment) ?>
                        </p>
                        <span style="font-size:12px; color:var(--gray);">
                            Reviewed on <?= encode(date('d M Y', strtotime($rev->created_at))) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require_once __DIR__ . '/_foot.php'; ?>
