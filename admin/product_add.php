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

    // 2. Validate Multiple File Uploads (up to 5 photos) using save_uploaded_image()
    $uploaded_filenames = [];
    if (!isset($_FILES['photos']) || empty($_FILES['photos']['name']) || (is_array($_FILES['photos']['name']) && empty($_FILES['photos']['name'][0]))) {
        $errors['photos'] = 'At least one product photo is required.';
    } else {
        $total_files = is_array($_FILES['photos']['name']) ? count($_FILES['photos']['name']) : 1;
        if ($total_files > 5) {
            $errors['photos'] = 'Maximum 5 photos allowed per product.';
        } else {
            for ($i = 0; $i < $total_files; $i++) {
                // Skip empty slots if browser sends empty file input
                if (isset($_FILES['photos']['error'][$i]) && $_FILES['photos']['error'][$i] === UPLOAD_ERR_NO_FILE) {
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

            if (empty($errors['photos']) && empty($uploaded_filenames)) {
                $errors['photos'] = 'At least one valid product photo is required.';
            }

            // If an error occurred on any file, purge previously saved files in this request
            if (!empty($errors['photos'])) {
                foreach ($uploaded_filenames as $f) {
                    delete_uploaded_image('products', $f);
                }
                $uploaded_filenames = [];
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

            // Insert Product Photos (First photo is primary)
            $photo_stmt = $db->prepare('
                INSERT INTO `product_photo` (`product_id`, `photo_url`, `is_primary`, `sort_order`) 
                VALUES (?, ?, ?, ?)
            ');
            foreach ($uploaded_filenames as $idx => $fn) {
                $is_primary = ($idx === 0) ? 1 : 0;
                $sort_order = $idx + 1;
                $photo_stmt->execute([$product_id, $fn, $is_primary, $sort_order]);
            }

            $db->commit();

            set_flash('success', "Product '{$name}' with " . count($uploaded_filenames) . " photo(s) created successfully!");
            redirect(base_url('admin/products.php'));
        } catch (Exception $e) {
            $db->rollBack();
            // Clean up uploaded files if database insert failed
            foreach ($uploaded_filenames as $f) {
                delete_uploaded_image('products', $f);
            }
            $errors['db'] = app_error_message('Failed to save product. Please try again.', $e);
        }
    }
}

$title = 'Add New Product';
require_once __DIR__ . '/../_head.php';
?>

<div class="container container-form-md">
    <!-- Breadcrumb & Header -->
    <div class="mb-24">
        <a href="<?= base_url('admin/products.php') ?>" class="form-link">&larr; Back to Products</a>
        <h1 class="form-page-title">Add New sumthin Gadget</h1>
        <p class="page-subtitle">Enter gadget specifications, pricing, inventory, and upload photography.</p>
    </div>

    <?php if (isset($errors['db'])): ?>
        <div class="flash-alert flash-error mb-20">
            <div class="flash-icon">✕</div>
            <div class="flash-message"><?= encode($errors['db']) ?></div>
        </div>
    <?php endif; ?>

    <!-- Form Card -->
    <div class="card-panel-body">
        <form action="<?= base_url('admin/product_add.php') ?>" method="POST" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>

            <!-- Product Name -->
            <div class="form-group">
                <label for="name" class="form-label">Product Name & Model *</label>
                <?= html_text('name', $name, ['placeholder' => 'e.g. sumthin Volt 30K High-Speed GaN Power Bank']) ?>
                <?= err('name') ?>
            </div>

            <!-- Category & Brand Row -->
            <div class="d-flex gap-16 flex-wrap">
                <div class="form-group flex-1 min-w-220">
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

                <div class="form-group flex-1 min-w-220">
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
            <div class="d-flex gap-16 flex-wrap">
                <div class="form-group flex-1 min-w-220">
                    <label for="price" class="form-label">Selling Price (RM) *</label>
                    <?= html_text('price', $price, ['placeholder' => 'e.g. 299.00']) ?>
                    <?= err('price') ?>
                </div>

                <div class="form-group flex-1 min-w-220">
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

            <!-- Multiple Product Photos Upload (Drag & Drop Zone) -->
            <div class="form-group">
                <label class="form-label">Product Photography * (Up to 5 Photos)</label>
                <div id="drop-zone" class="drop-zone" data-existing-count="0">
                    <span class="drop-zone-icon">📷</span>
                    <div class="drop-zone-text">Drag & drop photos here, or click to browse</div>
                    <div class="drop-zone-hint">Upload 1 to 5 images (JPG, PNG, WEBP &le; 2 MB each). The first photo will be set as primary.</div>
                    <input type="file" name="photos[]" id="photos-input" multiple accept="image/jpeg,image/png,image/webp" class="d-none">
                </div>
                <div id="drop-zone-error" class="form-error d-none mt-8"></div>
                <div id="thumbnails-preview" class="thumbnails-preview"></div>
                <?= err('photos') ?>
            </div>

            <!-- Featured Checkbox -->
            <div class="form-row mt-10">
                <?= html_checkbox('is_featured', '1', !empty($is_featured), 'Feature this gadget prominently on the homepage hero catalog') ?>
            </div>

            <!-- Submit Buttons -->
            <div class="form-actions d-flex gap-12">
                <button type="submit" class="btn btn-primary">Create Product</button>
                <a href="<?= base_url('admin/products.php') ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../_foot.php'; ?>
