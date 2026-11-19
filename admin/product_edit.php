<?php
// ============================================================================
// File: admin/product_edit.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Product Maintenance (Admin Edit Product & Multi-Photo Management)
// Description: Modify product details, pricing, stock, and manage multi-photo
//              gallery (up to 5 photos, set primary, delete photo, drag & drop).
// ============================================================================
require_once __DIR__ . '/../_base.php';

auth('Admin');

$id = (int)req('id');
if ($id <= 0) {
    set_flash('error', 'Invalid product ID.');
    redirect(base_url('admin/products.php'));
}

// Fetch existing product
$stmt = $db->prepare('SELECT * FROM `product` WHERE `id` = ?');
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    set_flash('error', 'Product not found.');
    redirect(base_url('admin/products.php'));
}

// Fetch existing photos for this product
$photos_stmt = $db->prepare('SELECT * FROM `product_photo` WHERE `product_id` = ? ORDER BY `is_primary` DESC, `sort_order` ASC, `id` ASC');
$photos_stmt->execute([$id]);
$existing_photos = $photos_stmt->fetchAll();

// ----------------------------------------------------------------------------
// Action 1: Set Primary Photo (POST + CSRF)
// ----------------------------------------------------------------------------
if (is_post() && req('action') === 'set_primary') {
    verify_csrf();
    $photo_id = (int)req('photo_id');

    $chk = $db->prepare('SELECT * FROM `product_photo` WHERE `id` = ? AND `product_id` = ?');
    $chk->execute([$photo_id, $id]);
    if ($chk->fetch()) {
        $db->prepare('UPDATE `product_photo` SET `is_primary` = 0 WHERE `product_id` = ?')->execute([$id]);
        $db->prepare('UPDATE `product_photo` SET `is_primary` = 1 WHERE `id` = ? AND `product_id` = ?')->execute([$photo_id, $id]);
        set_flash('success', 'Primary photo updated successfully.');
    } else {
        set_flash('error', 'Photo not found on this product.');
    }
    redirect(base_url('admin/product_edit.php?id=' . $id));
}

// ----------------------------------------------------------------------------
// Action 2: Delete Photo (POST + CSRF, Disk cleanup, Always keep at least 1)
// ----------------------------------------------------------------------------
if (is_post() && req('action') === 'delete_photo') {
    verify_csrf();
    $photo_id = (int)req('photo_id');

    // Count existing photos
    $cnt_stmt = $db->prepare('SELECT COUNT(*) FROM `product_photo` WHERE `product_id` = ?');
    $cnt_stmt->execute([$id]);
    $total_photos = (int)$cnt_stmt->fetchColumn();

    // Must always keep at least 1 photo
    if ($total_photos <= 1) {
        set_flash('error', 'Cannot delete photo: Every product must maintain at least 1 photo.');
        redirect(base_url('admin/product_edit.php?id=' . $id));
    }

    // Verify photo exists and belongs to this product
    $ph_stmt = $db->prepare('SELECT * FROM `product_photo` WHERE `id` = ? AND `product_id` = ?');
    $ph_stmt->execute([$photo_id, $id]);
    $target_photo = $ph_stmt->fetch();

    if ($target_photo) {
        // If deleting the primary photo, assign another photo as primary first
        if ((int)$target_photo->is_primary === 1) {
            $other_stmt = $db->prepare('SELECT `id` FROM `product_photo` WHERE `product_id` = ? AND `id` != ? LIMIT 1');
            $other_stmt->execute([$id, $photo_id]);
            $new_primary_id = $other_stmt->fetchColumn();
            if ($new_primary_id) {
                $db->prepare('UPDATE `product_photo` SET `is_primary` = 1 WHERE `id` = ?')->execute([$new_primary_id]);
            }
        }

        // Delete image file from disk
        delete_uploaded_image('products', $target_photo->photo_url);

        // Delete database record
        $db->prepare('DELETE FROM `product_photo` WHERE `id` = ?')->execute([$photo_id]);

        set_flash('success', 'Photo removed successfully from product gallery.');
    } else {
        set_flash('error', 'Selected photo does not exist.');
    }
    redirect(base_url('admin/product_edit.php?id=' . $id));
}

// ----------------------------------------------------------------------------
// Action 3: General Product Details Update & Additional Photos Upload
// ----------------------------------------------------------------------------
$categories = $db->query("SELECT `id`, `name` FROM `category` ORDER BY `name` ASC")->fetchAll();
$brands     = $db->query("SELECT `id`, `name` FROM `brand` ORDER BY `name` ASC")->fetchAll();

$name        = $product->name;
$category_id = $product->category_id;
$brand_id    = $product->brand_id;
$description = $product->description;
$price       = $product->price;
$stock       = $product->stock;
$is_featured = $product->is_featured;

if (is_post() && (!req('action') || req('action') === 'update_product')) {
    verify_csrf();

    $name        = req('name');
    $category_id = req('category_id');
    $brand_id    = req('brand_id');
    $description = req('description');
    $price       = req('price');
    $stock       = req('stock');
    $is_featured = req('is_featured') ? 1 : 0;

    // 1. Text validations
    if ($name === '') {
        $errors['name'] = 'Product name is required.';
    } elseif (strlen($name) < 3 || strlen($name) > 150) {
        $errors['name'] = 'Product name must be between 3 and 150 characters.';
    }

    if ($category_id === '') {
        $errors['category_id'] = 'Please select a category.';
    }

    if ($description === '') {
        $errors['description'] = 'Product description is required.';
    }

    if ($price === '') {
        $errors['price'] = 'Price is required.';
    } elseif (!is_numeric($price) || (float)$price <= 0) {
        $errors['price'] = 'Please enter a valid positive price.';
    }

    if ($stock === '') {
        $errors['stock'] = 'Stock quantity is required.';
    } elseif (!ctype_digit((string)$stock) || (int)$stock < 0) {
        $errors['stock'] = 'Stock must be a non-negative whole number (0 or higher).';
    }

    // 2. Validate & Process Additional Uploaded Photos (Up to 5 in total)
    $uploaded_filenames = [];
    $current_photo_count = count($existing_photos);
    $available_slots     = 5 - $current_photo_count;

    if (isset($_FILES['photos']) && !empty($_FILES['photos']['name']) && is_array($_FILES['photos']['name'])) {
        $incoming_count = 0;
        foreach ($_FILES['photos']['error'] as $err_code) {
            if ($err_code !== UPLOAD_ERR_NO_FILE) {
                $incoming_count++;
            }
        }

        if ($incoming_count > $available_slots) {
            $errors['photos'] = "Maximum 5 photos allowed per product. Currently has {$current_photo_count} photo(s); you can only add up to {$available_slots} more.";
        } elseif ($incoming_count > 0) {
            $total_inputs = count($_FILES['photos']['name']);
            for ($i = 0; $i < $total_inputs; $i++) {
                if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $photo_err = null;
                $saved = save_uploaded_image('photos', 'products', 'sumthin', $photo_err, 2, $i);
                if ($photo_err) {
                    $errors['photos'] = $photo_err;
                    break;
                } elseif ($saved) {
                    $uploaded_filenames[] = $saved;
                }
            }

            // If error occurred, clean up uploaded files
            if (!empty($errors['photos'])) {
                foreach ($uploaded_filenames as $f) {
                    delete_uploaded_image('products', $f);
                }
                $uploaded_filenames = [];
            }
        }
    }

    // 3. Database Update if Valid
    if (empty($errors)) {
        try {
            $db->beginTransaction();

            $update_stmt = $db->prepare('
                UPDATE `product` 
                SET `category_id` = ?, `brand_id` = ?, `name` = ?, `description` = ?, `price` = ?, `stock` = ?, `is_featured` = ?
                WHERE `id` = ?
            ');
            $update_stmt->execute([
                (int)$category_id,
                !empty($brand_id) ? (int)$brand_id : null,
                $name,
                $description,
                number_format((float)$price, 2, '.', ''),
                (int)$stock,
                $is_featured,
                $id
            ]);

            // Insert new gallery photos
            if (!empty($uploaded_filenames)) {
                $has_primary_stmt = $db->prepare('SELECT COUNT(*) FROM `product_photo` WHERE `product_id` = ? AND `is_primary` = 1');
                $has_primary_stmt->execute([$id]);
                $has_primary = ($has_primary_stmt->fetchColumn() > 0);

                $photo_ins = $db->prepare('INSERT INTO `product_photo` (`product_id`, `photo_url`, `is_primary`, `sort_order`) VALUES (?, ?, ?, ?)');
                foreach ($uploaded_filenames as $idx => $fn) {
                    $is_primary = (!$has_primary && $idx === 0) ? 1 : 0;
                    $sort_order = $current_photo_count + $idx + 1;
                    $photo_ins->execute([$id, $fn, $is_primary, $sort_order]);
                }
            }

            $db->commit();

            set_flash('success', "Product '{$name}' updated successfully!");
            redirect(base_url('admin/products.php'));
        } catch (Exception $e) {
            $db->rollBack();
            foreach ($uploaded_filenames as $f) {
                delete_uploaded_image('products', $f);
            }
            $errors['db'] = app_error_message('Failed to update product. Please try again.', $e);
        }
    }
}

// Refresh photo list after any changes
$photos_stmt->execute([$id]);
$existing_photos = $photos_stmt->fetchAll();

$title = 'Edit Product #' . $id;
require_once __DIR__ . '/../_head.php';
?>

<div class="container container-settings">
    <!-- Breadcrumb & Header -->
    <div class="mb-24">
        <a href="<?= base_url('admin/products.php') ?>" class="form-link">&larr; Back to Products</a>
        <h1 class="form-page-title">Edit Gadget #<?= (int)$id ?></h1>
        <p class="page-subtitle">Update specifications, inventory, and photography for <strong><?= encode($product->name) ?></strong>.</p>
    </div>

    <?php if (isset($errors['db'])): ?>
        <div class="flash-alert flash-error mb-20">
            <div class="flash-icon">✕</div>
            <div class="flash-message"><?= encode($errors['db']) ?></div>
        </div>
    <?php endif; ?>

    <!-- Existing Photos Gallery Management Card -->
    <div class="card-panel p-24 mb-24">
        <div class="flex-between mb-16 border-bottom pb-10">
            <div>
                <h3 class="card-title-sm fs-16 m-0">
                    Current Product Photography (<?= count($existing_photos) ?> / 5)
                </h3>
                <span class="fs-12 text-muted">Every product must keep at least 1 photo. One photo must be designated as Primary.</span>
            </div>
            <span class="role-pill pill-member fs-11">
                <?= 5 - count($existing_photos) ?> slot(s) remaining
            </span>
        </div>

        <div class="photo-gallery-grid">
            <?php foreach ($existing_photos as $idx => $ph): ?>
                <div class="photo-gallery-card <?= $ph->is_primary ? 'is-primary' : '' ?>">
                    <div>
                        <div class="photo-gallery-thumb">
                            <img src="<?= product_photo_url($ph->photo_url) ?>" 
                                 alt="Photo <?= $idx + 1 ?>" 
                                 class="order-thumb-img">
                        </div>
                        <?php if ($ph->is_primary): ?>
                            <span class="role-pill badge-photo-primary">★ Primary</span>
                        <?php else: ?>
                            <span class="role-pill badge-photo-gallery">Gallery</span>
                        <?php endif; ?>
                    </div>

                    <div class="d-flex flex-col gap-6 mt-8">
                        <?php if (!$ph->is_primary): ?>
                            <form action="<?= base_url('admin/product_edit.php?id=' . (int)$id) ?>" method="POST">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="set_primary">
                                <input type="hidden" name="photo_id" value="<?= (int)$ph->id ?>">
                                <button type="submit" class="btn btn-outline-sm btn-action-block">
                                    Set Primary
                                </button>
                            </form>
                        <?php endif; ?>

                        <form action="<?= base_url('admin/product_edit.php?id=' . (int)$id) ?>" method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_photo">
                            <input type="hidden" name="photo_id" value="<?= (int)$ph->id ?>">
                            <button type="submit" class="btn btn-outline-sm btn-action-block btn-outline-danger" data-confirm="Are you sure you want to delete this photo from the product and disk?">
                                Delete Photo
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Product Details Edit Form -->
    <div class="card-panel-body">
        <form action="<?= base_url('admin/product_edit.php?id=' . (int)$id) ?>" method="POST" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_product">

            <!-- Product Name -->
            <div class="form-group">
                <label for="name" class="form-label">Product Name / Model *</label>
                <?= html_text('name', $name, ['placeholder' => 'e.g. Apex 100W GaN Desktop Charger']) ?>
                <?= err('name') ?>
            </div>

            <!-- Category & Brand Row -->
            <div class="d-flex gap-16 flex-wrap">
                <div class="form-group flex-1 min-w-220">
                    <label for="category_id" class="form-label">Category *</label>
                    <select name="category_id" id="category_id" class="form-select">
                        <option value="">-- Choose Category --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int)$cat->id ?>" <?= ((string)$category_id === (string)$cat->id) ? 'selected' : '' ?>>
                                <?= encode($cat->name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= err('category_id') ?>
                </div>

                <div class="form-group flex-1 min-w-220">
                    <label for="brand_id" class="form-label">Brand</label>
                    <select name="brand_id" id="brand_id" class="form-select">
                        <option value="">sumthin (In-House Store Brand)</option>
                        <?php foreach ($brands as $b): ?>
                            <option value="<?= (int)$b->id ?>" <?= ((string)$brand_id === (string)$b->id) ? 'selected' : '' ?>>
                                <?= encode($b->name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Price and Stock Row -->
            <div class="d-flex gap-16 flex-wrap">
                <div class="form-group flex-1 min-w-220">
                    <label for="price" class="form-label">Selling Price (RM) *</label>
                    <?= html_text('price', $price) ?>
                    <?= err('price') ?>
                </div>

                <div class="form-group flex-1 min-w-220">
                    <label for="stock" class="form-label">Current Stock Quantity *</label>
                    <?= html_text('stock', $stock) ?>
                    <?= err('stock') ?>
                </div>
            </div>

            <!-- Description -->
            <div class="form-group">
                <label for="description" class="form-label">Product Description & Specs *</label>
                <?= html_textarea('description', $description, ['rows' => 4]) ?>
                <?= err('description') ?>
            </div>

            <!-- Drag & Drop Zone for Adding More Photos -->
            <div class="form-group">
                <label class="form-label">Add More Photos (Optional)</label>
                <?php $remaining = 5 - count($existing_photos); ?>
                <?php if ($remaining > 0): ?>
                    <div id="drop-zone" class="drop-zone" data-existing-count="<?= count($existing_photos) ?>">
                        <span class="drop-zone-icon">📷</span>
                        <div class="drop-zone-text">Drag & drop photos here, or click to browse</div>
                        <div class="drop-zone-hint">You can add up to <?= $remaining ?> more photo(s) (JPG, PNG, WEBP &le; 2 MB each).</div>
                        <input type="file" name="photos[]" id="photos-input" multiple accept="image/jpeg,image/png,image/webp" class="d-none">
                    </div>
                    <div id="drop-zone-error" class="form-error d-none mt-8"></div>
                    <div id="thumbnails-preview" class="thumbnails-preview"></div>
                    <?= err('photos') ?>
                <?php else: ?>
                    <div class="notice-box">
                        ℹ Maximum limit of 5 gallery photos reached. To upload a different image, delete an existing photo from the gallery above first.
                    </div>
                <?php endif; ?>
            </div>

            <!-- Featured Checkbox -->
            <div class="form-row mt-10">
                <?= html_checkbox('is_featured', '1', !empty($is_featured), 'Feature this gadget prominently on homepage catalog') ?>
            </div>

            <!-- Action Buttons -->
            <div class="form-actions d-flex gap-12">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="<?= base_url('admin/products.php') ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../_foot.php'; ?>
