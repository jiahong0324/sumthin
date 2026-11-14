<?php
// ============================================================================
// File: admin/product_edit.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Product Maintenance (Admin Edit Product)
// Description: Modify product details, pricing, inventory, and optionally replace
//              product photography with file validation.
// ============================================================================
require_once __DIR__ . '/../_base.php';

auth('Admin');

$id = (int)req('id');
if ($id <= 0) {
    set_flash('error', 'Invalid product ID.');
    redirect(base_url('admin/products.php'));
}

// Fetch existing product
$stmt = $db->prepare('
    SELECT p.*, pp.photo_url 
    FROM `product` p
    LEFT JOIN `product_photo` pp ON p.id = pp.product_id AND pp.is_primary = 1
    WHERE p.id = ?
');
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    set_flash('error', 'Product not found.');
    redirect(base_url('admin/products.php'));
}

$categories = $db->query("SELECT `id`, `name` FROM `category` ORDER BY `name` ASC")->fetchAll();
$brands     = $db->query("SELECT `id`, `name` FROM `brand` ORDER BY `name` ASC")->fetchAll();

$name        = $product->name;
$category_id = $product->category_id;
$brand_id    = $product->brand_id;
$description = $product->description;
$price       = $product->price;
$stock       = $product->stock;
$is_featured = $product->is_featured;
$current_photo = $product->photo_url ?? 'default_product.png';

if (is_post()) {
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

    // 2. Optional Photo Replacement
    $new_photo_filename = null;
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            $errors['photo'] = 'Upload error (Code: ' . $_FILES['photo']['error'] . ').';
        } else {
            $file = $_FILES['photo'];
            $max_size = 2 * 1024 * 1024;
            $allowed_exts  = ['jpg', 'jpeg', 'png', 'webp'];
            $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp'];

            $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if ($file['size'] > $max_size) {
                $errors['photo'] = 'Image size cannot exceed 2 MB.';
            } elseif (!in_array($file_ext, $allowed_exts, true)) {
                $errors['photo'] = 'Allowed formats: JPG, JPEG, PNG, WEBP.';
            } else {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime_type = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                if (!in_array($mime_type, $allowed_mimes, true)) {
                    $errors['photo'] = 'Invalid image content detected.';
                } else {
                    $new_photo_filename = 'sumthin_' . bin2hex(random_bytes(8)) . '.' . $file_ext;
                    $dest = __DIR__ . '/../uploads/products/' . $new_photo_filename;
                    if (!move_uploaded_file($file['tmp_name'], $dest)) {
                        $errors['photo'] = 'Failed to save new photo to disk.';
                    }
                }
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

            // If new photo was uploaded, update photo record
            if ($new_photo_filename) {
                // Check if photo record already exists
                $check_photo = $db->prepare('SELECT `id`, `photo_url` FROM `product_photo` WHERE `product_id` = ? AND `is_primary` = 1');
                $check_photo->execute([$id]);
                $existing_photo_row = $check_photo->fetch();

                if ($existing_photo_row) {
                    $p_update = $db->prepare('UPDATE `product_photo` SET `photo_url` = ? WHERE `id` = ?');
                    $p_update->execute([$new_photo_filename, $existing_photo_row->id]);

                    // Remove old custom photo file if it exists and isn't default
                    if ($existing_photo_row->photo_url && !str_starts_with($existing_photo_row->photo_url, 'default')) {
                        $old_path = __DIR__ . '/../uploads/products/' . $existing_photo_row->photo_url;
                        if (file_exists($old_path)) {
                            unlink($old_path);
                        }
                    }
                } else {
                    $p_ins = $db->prepare('INSERT INTO `product_photo` (`product_id`, `photo_url`, `is_primary`, `sort_order`) VALUES (?, ?, 1, 1)');
                    $p_ins->execute([$id, $new_photo_filename]);
                }
            }

            $db->commit();

            set_flash('success', "Product '{$name}' was updated successfully!");
            redirect(base_url('admin/products.php'));
        } catch (Exception $e) {
            $db->rollBack();
            $errors['db'] = 'Failed to update product: ' . $e->getMessage();
        }
    }
}

$title = 'Edit Product #' . $id;
require_once __DIR__ . '/../_head.php';
?>

<div class="container" style="max-width: 800px;">
    <!-- Breadcrumb & Header -->
    <div style="margin-bottom: 24px;">
        <a href="<?= base_url('admin/products.php') ?>" class="form-link">&larr; Back to Products</a>
        <h1 style="font-size:26px; font-weight:800; color:var(--dark); margin-top:8px;">Edit Gadget #<?= (int)$id ?></h1>
        <p style="font-size:14px; color:var(--gray);">Update pricing, specifications, and photography for <strong><?= encode($product->name) ?></strong>.</p>
    </div>

    <?php if (isset($errors['db'])): ?>
        <div class="flash-alert flash-error" style="margin-bottom:20px;">
            <div class="flash-icon">✕</div>
            <div class="flash-message"><?= encode($errors['db']) ?></div>
        </div>
    <?php endif; ?>

    <!-- Form Card -->
    <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:32px; box-shadow:var(--shadow-sm);">
        <form action="<?= base_url('admin/product_edit.php?id=' . (int)$id) ?>" method="POST" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>

            <!-- Product Name -->
            <div class="form-group">
                <label for="name" class="form-label">Product Name & Model *</label>
                <?= html_text('name', $name) ?>
                <?= err('name') ?>
            </div>

            <!-- Category & Brand Row -->
            <div style="display:flex; gap:16px; flex-wrap:wrap;">
                <div class="form-group" style="flex:1; min-width:220px;">
                    <label for="category_id" class="form-label">Category *</label>
                    <select name="category_id" id="category_id" class="form-select">
                        <option value="">-- Select Category --</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= (int)$c->id ?>" <?= ((string)$category_id === (string)$c->id) ? 'selected' : '' ?>>
                                <?= encode($c->name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= err('category_id') ?>
                </div>

                <div class="form-group" style="flex:1; min-width:220px;">
                    <label for="brand_id" class="form-label">Brand Series</label>
                    <select name="brand_id" id="brand_id" class="form-select">
                        <option value="">-- None / Default --</option>
                        <?php foreach ($brands as $b): ?>
                            <option value="<?= (int)$b->id ?>" <?= ((string)$brand_id === (string)$b->id) ? 'selected' : '' ?>>
                                <?= encode($b->name) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= err('brand_id') ?>
                </div>
            </div>

            <!-- Price and Stock Row -->
            <div style="display:flex; gap:16px; flex-wrap:wrap;">
                <div class="form-group" style="flex:1; min-width:220px;">
                    <label for="price" class="form-label">Selling Price (RM) *</label>
                    <?= html_text('price', $price) ?>
                    <?= err('price') ?>
                </div>

                <div class="form-group" style="flex:1; min-width:220px;">
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

            <!-- Current & New Photo Section -->
            <div class="form-group">
                <label class="form-label">Product Photography</label>
                <div style="display:flex; gap:16px; align-items:center; margin-bottom:12px; padding:12px; background:#f8fafc; border-radius:8px; border:1px solid var(--border);">
                    <div style="width:72px; height:72px; background:#0b0f19; border-radius:6px; overflow:hidden; display:flex; align-items:center; justify-content:center;">
                        <img src="<?= base_url('uploads/products/' . encode($current_photo)) ?>" 
                             alt="Current Photo" 
                             style="max-width:100%; max-height:100%; object-fit:contain;">
                    </div>
                    <div>
                        <div style="font-size:13px; font-weight:700; color:var(--dark);">Current Photo:</div>
                        <code style="font-size:12px; color:var(--gray);"><?= encode($current_photo) ?></code>
                    </div>
                </div>

                <label for="photo" class="form-label" style="font-size:12px;">Replace Photo (Optional)</label>
                <?= html_file('photo', 'image/png,image/jpeg,image/webp') ?>
                <?= err('photo') ?>
                <span class="form-help">Leave empty to keep current photo. Formats: PNG, JPG, WEBP (&le; 2 MB).</span>
            </div>

            <!-- Featured Checkbox -->
            <div class="form-row" style="margin-top:10px;">
                <?= html_checkbox('is_featured', '1', !empty($is_featured), 'Feature this gadget prominently on homepage catalog') ?>
            </div>

            <!-- Action Buttons -->
            <div style="display:flex; gap:12px; margin-top:20px; border-top:1px solid var(--border); padding-top:20px;">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="<?= base_url('admin/products.php') ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../_foot.php'; ?>
