<?php
// ============================================================================
// File: admin/product_add.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Product Maintenance (Admin Create Product)
// Description: Add new product with strict server-side validation and secure
//              file upload verification (extension, MIME type, size, rename).
// ============================================================================
require_once __DIR__ . '/../_base.php';

auth('Admin');

// Fetch categories and brands for dropdowns
$categories = $db->query("SELECT `id`, `name` FROM `category` ORDER BY `name` ASC")->fetchAll();
$brands     = $db->query("SELECT `id`, `name` FROM `brand` ORDER BY `name` ASC")->fetchAll();

$name        = '';
$category_id = '';
$brand_id    = '';
$description = '';
$price       = '';
$stock       = '10';
$is_featured = 0;

if (is_post()) {
    verify_csrf();

    $name        = req('name');
    $category_id = req('category_id');
    $brand_id    = req('brand_id');
    $description = req('description');
    $price       = req('price');
    $stock       = req('stock');
    $is_featured = req('is_featured') ? 1 : 0;

    // 1. Validate Text Inputs
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
        $errors['price'] = 'Please enter a valid positive price (e.g. 199.00).';
    }

    if ($stock === '') {
        $errors['stock'] = 'Initial stock quantity is required.';
    } elseif (!ctype_digit((string)$stock) || (int)$stock < 0) {
        $errors['stock'] = 'Stock must be a non-negative whole number (0 or higher).';
    }

    // 2. Validate File Upload (Extension + MIME + Size + Rename)
    $uploaded_filename = null;
    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] === UPLOAD_ERR_NO_FILE) {
        $errors['photo'] = 'Product photo is required.';
    } elseif ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        $errors['photo'] = 'File upload encountered an error (Code: ' . $_FILES['photo']['error'] . ').';
    } else {
        $file = $_FILES['photo'];
        $max_size = 2 * 1024 * 1024; // 2 MB limit
        $allowed_exts  = ['jpg', 'jpeg', 'png', 'webp'];
        $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp'];

        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        // a. Check size
        if ($file['size'] > $max_size) {
            $errors['photo'] = 'File size cannot exceed 2 MB.';
        }
        // b. Check extension whitelist
        elseif (!in_array($file_ext, $allowed_exts, true)) {
            $errors['photo'] = 'Allowed file formats: JPG, JPEG, PNG, WEBP.';
        }
        // c. Verify real MIME type using finfo
        else {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mime_type, $allowed_mimes, true)) {
                $errors['photo'] = 'Invalid image content detected. Upload rejected.';
            } else {
                // d. Generate cryptographically safe random filename
                $uploaded_filename = 'sumthin_' . bin2hex(random_bytes(8)) . '.' . $file_ext;
                $destination = __DIR__ . '/../uploads/products/' . $uploaded_filename;

                if (!move_uploaded_file($file['tmp_name'], $destination)) {
                    $errors['photo'] = 'Failed to save uploaded file to disk.';
                }
            }
        }
    }

    // 3. Database Insertion if Validation Passes
    if (empty($errors)) {
        // Generate unique URL slug
        $base_slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));
        $slug = $base_slug;
        $counter = 1;
        while (true) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM `product` WHERE `slug` = ?');
            $stmt->execute([$slug]);
            if ($stmt->fetchColumn() == 0) break;
            $slug = $base_slug . '-' . $counter++;
        }

        try {
            $db->beginTransaction();

            // Insert Product
            $stmt = $db->prepare('
                INSERT INTO `product` (`category_id`, `brand_id`, `name`, `slug`, `description`, `price`, `stock`, `is_featured`) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                (int)$category_id,
                !empty($brand_id) ? (int)$brand_id : null,
                $name,
                $slug,
                $description,
                number_format((float)$price, 2, '.', ''),
                (int)$stock,
                $is_featured
            ]);
            $product_id = (int)$db->lastInsertId();

            // Insert Primary Photo
            $photo_stmt = $db->prepare('
                INSERT INTO `product_photo` (`product_id`, `photo_url`, `is_primary`, `sort_order`) 
                VALUES (?, ?, 1, 1)
            ');
            $photo_stmt->execute([$product_id, $uploaded_filename]);

            $db->commit();

            set_flash('success', "Product '{$name}' created successfully!");
            redirect(base_url('admin/products.php'));
        } catch (Exception $e) {
            $db->rollBack();
            // Clean up uploaded file if database insert failed
            if ($uploaded_filename && file_exists(__DIR__ . '/../uploads/products/' . $uploaded_filename)) {
                unlink(__DIR__ . '/../uploads/products/' . $uploaded_filename);
            }
            $errors['db'] = 'Failed to save product: ' . $e->getMessage();
        }
    }
}

$title = 'Add New Product';
require_once __DIR__ . '/../_head.php';
?>

<div class="container" style="max-width: 800px;">
    <!-- Breadcrumb & Header -->
    <div style="margin-bottom: 24px;">
        <a href="<?= base_url('admin/products.php') ?>" class="form-link">&larr; Back to Products</a>
        <h1 style="font-size:26px; font-weight:800; color:var(--dark); margin-top:8px;">Add New sumthin Gadget</h1>
        <p style="font-size:14px; color:var(--gray);">Enter gadget specifications, pricing, inventory, and upload photography.</p>
    </div>

    <?php if (isset($errors['db'])): ?>
        <div class="flash-alert flash-error" style="margin-bottom:20px;">
            <div class="flash-icon">✕</div>
            <div class="flash-message"><?= encode($errors['db']) ?></div>
        </div>
    <?php endif; ?>

    <!-- Form Card -->
    <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:32px; box-shadow:var(--shadow-sm);">
        <form action="<?= base_url('admin/product_add.php') ?>" method="POST" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>

            <!-- Product Name -->
            <div class="form-group">
                <label for="name" class="form-label">Product Name & Model *</label>
                <?= html_text('name', $name, ['placeholder' => 'e.g. sumthin Volt 30K High-Speed GaN Power Bank']) ?>
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
                    <?= html_text('price', $price, ['placeholder' => 'e.g. 299.00']) ?>
                    <?= err('price') ?>
                </div>

                <div class="form-group" style="flex:1; min-width:220px;">
                    <label for="stock" class="form-label">Initial Stock Quantity *</label>
                    <?= html_text('stock', $stock, ['placeholder' => 'e.g. 25']) ?>
                    <?= err('stock') ?>
                </div>
            </div>

            <!-- Description -->
            <div class="form-group">
                <label for="description" class="form-label">Product Description & Specs *</label>
                <?= html_textarea('description', $description, ['rows' => 4, 'placeholder' => 'Detail key technical specifications, port types, wattage, weight, battery capacity, warranty...']) ?>
                <?= err('description') ?>
            </div>

            <!-- Product Photo Upload -->
            <div class="form-group">
                <label for="photo" class="form-label">Primary Product Photo *</label>
                <?= html_file('photo', 'image/png,image/jpeg,image/webp') ?>
                <?= err('photo') ?>
                <span class="form-help">Formats accepted: PNG, JPG, JPEG, WEBP. Max file size: 2 MB.</span>
            </div>

            <!-- Featured Checkbox -->
            <div class="form-row" style="margin-top:10px;">
                <?= html_checkbox('is_featured', '1', !empty($is_featured), 'Feature this gadget prominently on the homepage hero catalog') ?>
            </div>

            <!-- Submit Buttons -->
            <div style="display:flex; gap:12px; margin-top:20px; border-top:1px solid var(--border); padding-top:20px;">
                <button type="submit" class="btn btn-primary">Create Product</button>
                <a href="<?= base_url('admin/products.php') ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../_foot.php'; ?>
