<?php
// ============================================================================
// File: admin/product_detail.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Product Maintenance (Admin Detail View)
// Description: Comprehensive specification, photography, and review inspection
//              for an individual gadget.
// ============================================================================
require_once __DIR__ . '/../_base.php';

auth('Admin');

$id = (int)req('id');
if ($id <= 0) {
    set_flash('error', 'Invalid product ID.');
    redirect(base_url('admin/products.php'));
}

// Fetch product with category, brand, and primary photo
$stmt = $db->prepare("
    SELECT p.*, c.name AS category_name, b.name AS brand_name,
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
    set_flash('error', 'Product not found.');
    redirect(base_url('admin/products.php'));
}

// Fetch all photos for this product
$photos_stmt = $db->prepare('SELECT * FROM `product_photo` WHERE `product_id` = ? ORDER BY `is_primary` DESC, `sort_order` ASC');
$photos_stmt->execute([$id]);
$all_photos = $photos_stmt->fetchAll();

// Fetch customer reviews for this product
$review_stmt = $db->prepare('
    SELECT r.*, u.name AS reviewer_name 
    FROM `review` r
    JOIN `user` u ON r.user_id = u.id
    WHERE r.product_id = ?
    ORDER BY r.id DESC
');
$review_stmt->execute([$id]);
$reviews = $review_stmt->fetchAll();

$avg_rating = 0;
if (!empty($reviews)) {
    $sum = array_sum(array_map(fn($r) => $r->rating, $reviews));
    $avg_rating = round($sum / count($reviews), 1);
}

$title = 'Gadget: ' . $p->name;
require_once __DIR__ . '/../_head.php';
?>

<div class="container container-history">
    <!-- Breadcrumb & Navigation -->
    <div class="flex-between flex-wrap gap-12 mb-24">
        <div>
            <a href="<?= base_url('admin/products.php') ?>" class="form-link">&larr; Back to Product List</a>
            <h1 class="page-title mt-6">
                <?= encode($p->name) ?>
            </h1>
            <span class="role-pill pill-member fs-12">
                <?= encode($p->brand_name ?? 'sumthin') ?> &bull; <?= encode($p->category_name) ?>
            </span>
        </div>
        <div class="d-flex gap-8">
            <a href="<?= base_url('admin/product_edit.php?id=' . (int)$p->id) ?>" class="btn btn-primary-sm">
                Edit Gadget
            </a>
            <form action="<?= base_url('admin/product_delete.php') ?>" method="POST" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$p->id ?>">
                <button type="submit" class="btn btn-outline-sm btn-outline-danger" data-confirm="Are you sure you want to delete this gadget?">
                    Delete
                </button>
            </form>
        </div>
    </div>

    <!-- Product Layout Grid -->
    <div class="card-panel p-32 mb-32 admin-product-detail-grid">
        <!-- Photo Preview & All Gallery Photos -->
        <div>
            <div class="admin-product-detail-main-img-box">
                <img src="<?= base_url('uploads/products/' . encode($p->photo_url)) ?>" 
                     alt="<?= encode($p->name) ?>" 
                     id="admin-detail-main-img"
                     class="order-thumb-img">
            </div>

            <!-- All Product Photos Showcase -->
            <div class="mt-14">
                <div class="flex-between fs-12 fw-700 text-dark mb-8">
                    <span>All Gallery Photos (<?= count($all_photos) ?>)</span>
                    <a href="<?= base_url('admin/product_edit.php?id=' . (int)$p->id) ?>" class="form-link fs-11">Manage Photos &rarr;</a>
                </div>
                <div class="d-flex gap-8 flex-wrap">
                    <?php foreach ($all_photos as $idx => $ph): ?>
                        <div class="product-mini-thumb <?= $ph->is_primary ? 'is-primary' : '' ?>" title="<?= encode($ph->photo_url) ?>">
                            <img src="<?= base_url('uploads/products/' . encode($ph->photo_url)) ?>" 
                                 alt="Photo <?= $idx + 1 ?>" 
                                 class="order-thumb-img">
                            <?php if ($ph->is_primary): ?>
                                <span class="product-mini-thumb-badge">PRI</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Specifications & Stats -->
        <div>
            <div class="flex-between items-baseline mb-20 border-bottom pb-14">
                <div>
                    <span class="fs-12 text-muted uppercase fw-700">Price</span>
                    <div class="product-price-lg">
                        RM <?= number_format((float)$p->price, 2) ?>
                    </div>
                </div>
                <div class="text-right">
                    <span class="fs-12 text-muted uppercase fw-700">Stock Inventory</span>
                    <div>
                        <?php if ($p->stock == 0): ?>
                            <span class="role-pill pill-admin">Out of Stock (0)</span>
                        <?php elseif ($p->stock <= 15): ?>
                            <span class="role-pill badge-stock-low">Low Stock (<?= (int)$p->stock ?> units)</span>
                        <?php else: ?>
                            <span class="role-pill badge-stock-healthy">Healthy Stock (<?= (int)$p->stock ?> units)</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Description -->
            <div class="mb-24">
                <h3 class="order-sidebar-title fs-14 mb-8">Description & Key Specs</h3>
                <p class="product-detail-desc">
                    <?= nl2br(encode($p->description)) ?>
                </p>
            </div>

            <!-- Metadata Table -->
            <table class="table-custom fs-13">
                <tr>
                    <td class="fw-700 min-w-130 text-dark-muted">Product ID</td>
                    <td>#<?= (int)$p->id ?></td>
                </tr>
                <tr>
                    <td class="fw-700 text-dark-muted">URL Slug</td>
                    <td><code><?= encode($p->slug) ?></code></td>
                </tr>
                <tr>
                    <td class="fw-700 text-dark-muted">Catalog Visibility</td>
                    <td><?= $p->is_featured ? '★ Featured on Storefront Hero' : 'Standard Catalog' ?></td>
                </tr>
                <tr>
                    <td class="fw-700 text-dark-muted">Date Created</td>
                    <td><?= encode(date('d M Y, h:i A', strtotime($p->created_at))) ?></td>
                </tr>
                <tr>
                    <td class="fw-700 text-dark-muted">Last Updated</td>
                    <td><?= encode(date('d M Y, h:i A', strtotime($p->updated_at))) ?></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Customer Reviews Panel -->
    <div class="card-panel p-28">
        <div class="section-title-wrap">
            <h2 class="section-title">Verified Customer Reviews (<?= count($reviews) ?>)</h2>
            <?php if (!empty($reviews)): ?>
                <span class="review-stars-summary">★ <?= $avg_rating ?> / 5.0 Average</span>
            <?php endif; ?>
        </div>

        <?php if (empty($reviews)): ?>
            <p class="text-muted fs-14">No customer reviews have been submitted for this gadget yet.</p>
        <?php else: ?>
            <div class="d-flex flex-col gap-16">
                <?php foreach ($reviews as $rev): ?>
                    <div class="border-bottom pb-14">
                        <div class="flex-between mb-4">
                            <strong><?= encode($rev->reviewer_name) ?></strong>
                            <span class="review-star-rating">
                                <?= str_repeat('★', (int)$rev->rating) ?><?= str_repeat('☆', 5 - (int)$rev->rating) ?> (<?= (int)$rev->rating ?>/5)
                            </span>
                        </div>
                        <p class="fs-13 text-dark mb-6">
                            <?= encode($rev->comment) ?>
                        </p>
                        <span class="fs-11 text-muted">
                            Reviewed on <?= encode(date('d M Y', strtotime($rev->created_at))) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../_foot.php'; ?>
