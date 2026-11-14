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

<div class="container" style="max-width: 950px;">
    <!-- Breadcrumb & Navigation -->
    <div style="margin-bottom: 24px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
        <div>
            <a href="<?= base_url('admin/products.php') ?>" class="form-link">&larr; Back to Product List</a>
            <h1 style="font-size:26px; font-weight:800; color:var(--dark); margin-top:6px;">
                <?= encode($p->name) ?>
            </h1>
            <span class="role-pill pill-member" style="font-size:12px;">
                <?= encode($p->brand_name ?? 'sumthin') ?> &bull; <?= encode($p->category_name) ?>
            </span>
        </div>
        <div style="display:flex; gap:8px;">
            <a href="<?= base_url('admin/product_edit.php?id=' . (int)$p->id) ?>" class="btn btn-primary-sm">
                Edit Gadget
            </a>
            <form action="<?= base_url('admin/product_delete.php') ?>" method="POST" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$p->id ?>">
                <button type="submit" class="btn btn-outline-sm" style="color:var(--error); border-color:#fca5a5;" data-confirm="Are you sure you want to delete this gadget?">
                    Delete
                </button>
            </form>
        </div>
    </div>

    <!-- Product Layout Grid -->
    <div style="display:grid; grid-template-columns: 1fr 1.3fr; gap:32px; background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:32px; margin-bottom:32px;">
        <!-- Photo Preview -->
        <div>
            <div style="background:#0b0f19; border-radius:10px; overflow:hidden; border:1px solid #1e293b; height:320px; display:flex; align-items:center; justify-content:center; margin-bottom:12px;">
                <img src="<?= base_url('uploads/products/' . encode($p->photo_url)) ?>" 
                     alt="<?= encode($p->name) ?>" 
                     style="max-width:100%; max-height:100%; object-fit:contain;">
            </div>
            <div style="font-size:12px; color:var(--gray); text-align:center;">
                Filename: <code><?= encode($p->photo_url) ?></code>
            </div>
        </div>

        <!-- Specifications & Stats -->
        <div>
            <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:20px; border-bottom:1px solid var(--border); padding-bottom:14px;">
                <div>
                    <span style="font-size:12px; color:var(--gray); text-transform:uppercase; font-weight:700;">Price</span>
                    <div style="font-size:32px; font-weight:900; color:var(--dark);">
                        RM <?= number_format((float)$p->price, 2) ?>
                    </div>
                </div>
                <div style="text-align:right;">
                    <span style="font-size:12px; color:var(--gray); text-transform:uppercase; font-weight:700;">Stock Inventory</span>
                    <div>
                        <?php if ($p->stock == 0): ?>
                            <span class="role-pill pill-admin">Out of Stock (0)</span>
                        <?php elseif ($p->stock <= 15): ?>
                            <span class="role-pill" style="background:#fef3c7; color:#b45309;">Low Stock (<?= (int)$p->stock ?> units)</span>
                        <?php else: ?>
                            <span class="role-pill" style="background:#dcfce7; color:#15803d;">Healthy Stock (<?= (int)$p->stock ?> units)</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Description -->
            <div style="margin-bottom:24px;">
                <h3 style="font-size:14px; font-weight:700; color:var(--dark-muted); text-transform:uppercase; margin-bottom:8px;">Description & Key Specs</h3>
                <p style="font-size:14px; color:var(--dark); line-height:1.7;">
                    <?= nl2br(encode($p->description)) ?>
                </p>
            </div>

            <!-- Metadata Table -->
            <table class="table-custom" style="font-size:13px;">
                <tr>
                    <td style="font-weight:700; width:130px; color:var(--dark-muted);">Product ID</td>
                    <td>#<?= (int)$p->id ?></td>
                </tr>
                <tr>
                    <td style="font-weight:700; color:var(--dark-muted);">URL Slug</td>
                    <td><code><?= encode($p->slug) ?></code></td>
                </tr>
                <tr>
                    <td style="font-weight:700; color:var(--dark-muted);">Catalog Visibility</td>
                    <td><?= $p->is_featured ? '★ Featured on Storefront Hero' : 'Standard Catalog' ?></td>
                </tr>
                <tr>
                    <td style="font-weight:700; color:var(--dark-muted);">Date Created</td>
                    <td><?= encode(date('d M Y, h:i A', strtotime($p->created_at))) ?></td>
                </tr>
                <tr>
                    <td style="font-weight:700; color:var(--dark-muted);">Last Updated</td>
                    <td><?= encode(date('d M Y, h:i A', strtotime($p->updated_at))) ?></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- Customer Reviews Panel -->
    <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:28px;">
        <div class="section-title-wrap">
            <h2 class="section-title">Verified Customer Reviews (<?= count($reviews) ?>)</h2>
            <?php if (!empty($reviews)): ?>
                <span style="font-size:14px; color:#d97706; font-weight:700;">★ <?= $avg_rating ?> / 5.0 Average</span>
            <?php endif; ?>
        </div>

        <?php if (empty($reviews)): ?>
            <p style="color:var(--gray); font-size:14px;">No customer reviews have been submitted for this gadget yet.</p>
        <?php else: ?>
            <div style="display:flex; flex-direction:column; gap:16px;">
                <?php foreach ($reviews as $rev): ?>
                    <div style="border-bottom:1px solid var(--border); padding-bottom:14px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                            <strong><?= encode($rev->reviewer_name) ?></strong>
                            <span style="color:#d97706; font-weight:700; font-size:13px;">
                                <?= str_repeat('★', (int)$rev->rating) ?><?= str_repeat('☆', 5 - (int)$rev->rating) ?> (<?= (int)$rev->rating ?>/5)
                            </span>
                        </div>
                        <p style="font-size:13px; color:var(--dark); margin-bottom:6px;">
                            <?= encode($rev->comment) ?>
                        </p>
                        <span style="font-size:11px; color:var(--gray);">
                            Reviewed on <?= encode(date('d M Y', strtotime($rev->created_at))) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../_foot.php'; ?>
