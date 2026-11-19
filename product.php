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

// Fetch all photos for this product
$photos_stmt = $db->prepare('SELECT * FROM `product_photo` WHERE `product_id` = ? ORDER BY `is_primary` DESC, `sort_order` ASC, `id` ASC');
$photos_stmt->execute([$id]);
$product_photos = $photos_stmt->fetchAll();

if (empty($product_photos)) {
    $product_photos = [
        (object)[
            'photo_url'  => $p->photo_url ?? 'default_product.png',
            'is_primary' => 1,
            'sort_order' => 1
        ]
    ];
}
$primary_photo = $product_photos[0]->photo_url;

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

<div class="container container-detail">
    <!-- Breadcrumbs -->
    <div class="breadcrumb-nav">
        <a href="<?= base_url('index.php') ?>" class="form-link">Shop</a> &rsaquo;
        <a href="<?= base_url('index.php?cat=' . encode($p->category_slug)) ?>" class="form-link"><?= encode($p->category_name) ?></a> &rsaquo;
        <span class="breadcrumb-current"><?= encode($p->name) ?></span>
    </div>

    <!-- Product Showcase Grid -->
    <div class="card-panel product-detail-card">
        <!-- Left: Product Photo Slider (Dynamic Slider) -->
        <div>
            <div class="product-slider" id="product-slider">
                <div class="slider-main-stage">
                    <img id="slider-main-img" 
                         src="<?= product_photo_url($primary_photo) ?>" 
                         alt="<?= encode($p->name) ?>" 
                         class="slider-main-img">

                    <?php if (count($product_photos) > 1): ?>
                        <button type="button" class="slider-arrow slider-prev" id="slider-prev" aria-label="Previous photo">&lsaquo;</button>
                        <button type="button" class="slider-arrow slider-next" id="slider-next" aria-label="Next photo">&rsaquo;</button>
                    <?php endif; ?>
                </div>

                <?php if (count($product_photos) > 1): ?>
                    <div class="slider-thumbnails" id="slider-thumbnails">
                        <?php foreach ($product_photos as $idx => $ph): ?>
                            <button type="button" 
                                    class="slider-thumb-btn <?= $idx === 0 ? 'active' : '' ?>" 
                                    data-index="<?= $idx ?>" 
                                    data-src="<?= product_photo_url($ph->photo_url) ?>"
                                    aria-label="View photo <?= $idx + 1 ?>">
                                <img src="<?= product_photo_url($ph->photo_url) ?>" 
                                     alt="Thumbnail <?= $idx + 1 ?>">
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="product-trust-row" style="margin-top:14px;">
                <span>🛡️ 2-Year sumthin Official Warranty</span>
                <span>&bull;</span>
                <span>⚡ Same-Day Dispatch</span>
            </div>
        </div>

        <!-- Right: Purchasing Panel -->
        <div>
            <span class="role-pill pill-member mb-8">
                <?= encode($p->brand_name ?? 'sumthin') ?> &bull; <?= encode($p->category_name) ?>
            </span>

            <h1 class="product-detail-name">
                <?= encode($p->name) ?>
            </h1>

            <!-- Ratings Summary -->
            <div class="product-rating-row">
                <?php if (!empty($reviews)): ?>
                    <span class="rating-stars-gold">
                        <?= str_repeat('★', (int)round($avg_rating)) ?>
                    </span>
                    <span class="rating-score-text">
                        <?= number_format($avg_rating, 1) ?> / 5.0
                    </span>
                    <span class="rating-count-text">
                        (<?= count($reviews) ?> customer review<?= count($reviews) !== 1 ? 's' : '' ?>)
                    </span>
                <?php else: ?>
                    <span class="rating-count-text">No reviews yet</span>
                <?php endif; ?>
            </div>

            <!-- Price -->
            <div class="product-detail-price">
                RM <?= number_format((float)$p->price, 2) ?>
            </div>

            <!-- Inventory Status -->
            <div class="mb-20">
                <?php if ($p->stock > 15): ?>
                    <span class="role-pill pill-stock-in">✓ In Stock (<?= (int)$p->stock ?> available)</span>
                <?php elseif ($p->stock > 0): ?>
                    <span class="role-pill pill-stock-low">⚠ Low Stock: Only <?= (int)$p->stock ?> left!</span>
                <?php else: ?>
                    <span class="role-pill pill-admin pill-stock-out">✕ Currently Out of Stock</span>
                <?php endif; ?>
            </div>

            <!-- Description -->
            <div class="product-detail-desc">
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
                <div class="shopee-outofstock-box mb-20">
                    <button type="button" class="btn btn-secondary btn-block btn-disabled-block" disabled>
                        Currently Out of Stock
                    </button>
                </div>
            <?php endif; ?>

            <!-- Shopee Guarantee & Wishlist Bar -->
            <div class="shopee-guarantee-bar">
                <form action="<?= base_url('wishlist.php') ?>" method="POST" class="d-inline">
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
    <section class="card-panel reviews-section-card">
        <div class="section-title-wrap">
            <h2 class="section-title">Verified Buyer Reviews (<?= count($reviews) ?>)</h2>
            <?php 
            $can_write_review = is_member() && (find_reviewable_order((int)$_SESSION['user']->id, (int)$p->id) !== null);
            if ($can_write_review): 
            ?>
                <a href="<?= base_url('review_add.php?product_id=' . (int)$p->id) ?>" class="btn btn-primary-sm">
                    Write a Review &rarr;
                </a>
            <?php endif; ?>
        </div>

        <?php if (empty($reviews)): ?>
            <p class="reviews-empty-text">
                No customer reviews yet. Be the first verified buyer to leave a review!
            </p>
        <?php else: ?>
            <div class="reviews-list">
                <?php foreach ($reviews as $rev): ?>
                    <div class="review-item">
                        <div class="review-header">
                            <div class="review-author-wrap">
                                <strong class="review-author-name"><?= encode($rev->reviewer_name) ?></strong>
                                <span class="role-pill pill-verified-buyer">Verified Buyer</span>
                            </div>
                            <span class="review-stars">
                                <?= str_repeat('★', (int)$rev->rating) ?><?= str_repeat('☆', 5 - (int)$rev->rating) ?>
                            </span>
                        </div>
                        <p class="review-content">
                            <?= encode($rev->comment) ?>
                        </p>
                        <span class="review-timestamp">
                            Reviewed on <?= encode(date('d M Y', strtotime($rev->created_at))) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require_once __DIR__ . '/_foot.php'; ?>
