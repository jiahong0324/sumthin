<?php
// ============================================================================
// File: admin/categories.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Category Maintenance + CRUD (Admin Management)
// Description: Manage product categories: list with product counts, add new
//              category, edit category details, and delete category with
//              relational foreign-key safeguards.
// ============================================================================
require_once __DIR__ . '/../_base.php';

auth('Admin');

$action    = req('action');
$edit_id   = (int)req('edit_id', 0);
$cat_name  = '';
$cat_slug  = '';
$cat_desc  = '';

// Load existing category for editing if requested
if ($edit_id > 0) {
    $edit_stmt = $db->prepare('SELECT * FROM `category` WHERE `id` = ?');
    $edit_stmt->execute([$edit_id]);
    $edit_cat = $edit_stmt->fetch();
    if ($edit_cat) {
        $cat_name = $edit_cat->name;
        $cat_slug = $edit_cat->slug;
        $cat_desc = $edit_cat->description ?? '';
    } else {
        $edit_id = 0;
    }
}

// ----------------------------------------------------------------------------
// Handle Form Submissions (Create / Update / Delete)
// ----------------------------------------------------------------------------
if (is_post()) {
    verify_csrf();

    $post_action = req('post_action');

    // 1. DELETE CATEGORY
    if ($post_action === 'delete') {
        $del_id = (int)req('id');
        $del_stmt = $db->prepare('SELECT * FROM `category` WHERE `id` = ?');
        $del_stmt->execute([$del_id]);
        $target_cat = $del_stmt->fetch();

        if ($target_cat) {
            // Check for associated products to prevent accidental data loss
            $chk_prod = $db->prepare('SELECT COUNT(*) FROM `product` WHERE `category_id` = ?');
            $chk_prod->execute([$del_id]);
            $associated_count = (int)$chk_prod->fetchColumn();

            if ($associated_count > 0) {
                set_flash('warning', "Cannot delete category '{$target_cat->name}' because {$associated_count} product(s) are currently categorized under it. Please reassign or delete those products first.");
            } else {
                $del = $db->prepare('DELETE FROM `category` WHERE `id` = ?');
                $del->execute([$del_id]);
                set_flash('success', "Category '{$target_cat->name}' has been deleted successfully.");
            }
        }
        redirect(base_url('admin/categories.php'));
    }

    // 2. CREATE OR UPDATE CATEGORY
    $cat_name = req('name');
    $cat_slug = req('slug');
    $cat_desc = req('description');

    // Name Validation
    if ($cat_name === '') {
        $errors['name'] = 'Category name is required.';
    } elseif (strlen($cat_name) < 2 || strlen($cat_name) > 100) {
        $errors['name'] = 'Category name must be between 2 and 100 characters.';
    } else {
        // Unique check
        $unique_sql = 'SELECT COUNT(*) FROM `category` WHERE `name` = ?' . ($edit_id > 0 ? ' AND `id` != ?' : '');
        $unique_params = ($edit_id > 0) ? [$cat_name, $edit_id] : [$cat_name];
        $u_stmt = $db->prepare($unique_sql);
        $u_stmt->execute($unique_params);
        if ($u_stmt->fetchColumn() > 0) {
            $errors['name'] = 'A category with this name already exists.';
        }
    }

    // Slug Generation / Validation
    if ($cat_slug === '') {
        $cat_slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $cat_name), '-'));
    } else {
        $cat_slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $cat_slug), '-'));
    }

    if ($cat_slug === '') {
        $errors['slug'] = 'Valid category URL slug is required.';
    } else {
        $slug_sql = 'SELECT COUNT(*) FROM `category` WHERE `slug` = ?' . ($edit_id > 0 ? ' AND `id` != ?' : '');
        $slug_params = ($edit_id > 0) ? [$cat_slug, $edit_id] : [$cat_slug];
        $s_stmt = $db->prepare($slug_sql);
        $s_stmt->execute($slug_params);
        if ($s_stmt->fetchColumn() > 0) {
            $errors['slug'] = 'This URL slug is already taken. Please choose another.';
        }
    }

    if (empty($errors)) {
        if ($edit_id > 0) {
            // Update
            $up = $db->prepare('UPDATE `category` SET `name` = ?, `slug` = ?, `description` = ? WHERE `id` = ?');
            $up->execute([$cat_name, $cat_slug, $cat_desc, $edit_id]);
            set_flash('success', "Category '{$cat_name}' updated successfully!");
        } else {
            // Insert
            $ins = $db->prepare('INSERT INTO `category` (`name`, `slug`, `description`) VALUES (?, ?, ?)');
            $ins->execute([$cat_name, $cat_slug, $cat_desc]);
            set_flash('success', "New category '{$cat_name}' created successfully!");
        }
        redirect(base_url('admin/categories.php'));
    }
}

// Fetch all categories with product counts
$categories = $db->query("
    SELECT c.*, COUNT(p.id) AS product_count
    FROM `category` c
    LEFT JOIN `product` p ON c.id = p.category_id
    GROUP BY c.id
    ORDER BY c.id ASC
")->fetchAll();

$title = 'Category Maintenance';
require_once __DIR__ . '/../_head.php';
?>

<div class="container">
    <!-- Header -->
    <div class="admin-header-panel">
        <div>
            <h1 class="page-title">Category Maintenance</h1>
            <p class="page-subtitle mt-4">
                Organize store hierarchy, create new merchandise lines, and manage catalog classifications.
            </p>
        </div>
        <span class="role-pill pill-member pill-counter">
            <?= count($categories) ?> Active Categories
        </span>
    </div>

    <div class="admin-two-col-grid">
        <!-- Left Column: Add / Edit Form -->
        <div class="card-panel p-28">
            <div class="flex-between mb-18 pb-12 border-bottom">
                <h2 class="card-title-sm">
                    <?= $edit_id > 0 ? 'Edit Category' : 'Create New Category' ?>
                </h2>
                <?php if ($edit_id > 0): ?>
                    <a href="<?= base_url('admin/categories.php') ?>" class="form-link fs-12">+ Switch to Create</a>
                <?php endif; ?>
            </div>

            <form action="<?= base_url('admin/categories.php' . ($edit_id > 0 ? '?edit_id=' . $edit_id : '')) ?>" method="POST" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="post_action" value="<?= $edit_id > 0 ? 'update' : 'create' ?>">

                <!-- Category Name -->
                <div class="form-group">
                    <label for="name" class="form-label">Category Name *</label>
                    <?= html_text('name', $cat_name, [
                        'placeholder' => 'e.g. Smart Wearables',
                        'autocomplete' => 'off'
                    ]) ?>
                    <?= err('name') ?>
                </div>

                <!-- Category Slug -->
                <div class="form-group">
                    <label for="slug" class="form-label">URL Slug (lowercase & hyphens)</label>
                    <?= html_text('slug', $cat_slug, [
                        'placeholder' => 'e.g. smart-wearables (leave blank to auto-generate)',
                        'autocomplete' => 'off'
                    ]) ?>
                    <?= err('slug') ?>
                    <span class="form-help">Used in URLs: <code>index.php?cat=your-slug</code></span>
                </div>

                <!-- Description -->
                <div class="form-group">
                    <label for="description" class="form-label">Category Description</label>
                    <?= html_textarea('description', $cat_desc, [
                        'rows' => 3,
                        'placeholder' => 'Brief marketing synopsis displayed at the top of category pages...'
                    ]) ?>
                    <?= err('description') ?>
                </div>

                <div class="category-form-actions">
                    <button type="submit" class="btn btn-primary">
                        <?= $edit_id > 0 ? 'Update Category' : 'Save Category' ?>
                    </button>
                    <?php if ($edit_id > 0): ?>
                        <a href="<?= base_url('admin/categories.php') ?>" class="btn btn-secondary">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Right Column: Category Directory Table -->
        <div class="card-panel p-24">
            <div class="mb-16">
                <h2 class="card-title-sm">All Product Categories</h2>
                <span class="fs-13 text-muted">Click on a category to view or edit</span>
            </div>

            <div class="table-responsive">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th class="w-50">ID</th>
                            <th>Name & Description</th>
                            <th>Slug</th>
                            <th class="text-center">Products</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $cat): ?>
                            <tr <?= ($edit_id === (int)$cat->id) ? 'class="tr-active"' : '' ?>>
                                <td><strong>#<?= (int)$cat->id ?></strong></td>
                                <td>
                                    <div class="fw-700 text-dark"><?= encode($cat->name) ?></div>
                                    <?php if ($cat->description): ?>
                                        <div class="fs-12 text-muted mt-2">
                                            <?= encode(substr($cat->description, 0, 75)) ?><?= strlen($cat->description) > 75 ? '...' : '' ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code class="fs-11"><?= encode($cat->slug) ?></code>
                                </td>
                                <td class="text-center">
                                    <a href="<?= base_url('admin/products.php?cat_id=' . (int)$cat->id) ?>" class="role-pill pill-member text-decoration-none fs-11">
                                        <?= (int)$cat->product_count ?> gadget(s)
                                    </a>
                                </td>
                                <td class="text-right text-nowrap">
                                    <a href="<?= base_url('admin/categories.php?edit_id=' . (int)$cat->id) ?>" class="btn btn-outline-sm btn-action-xs">
                                        Edit
                                    </a>
                                    <form action="<?= base_url('admin/categories.php') ?>" method="POST" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="post_action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$cat->id ?>">
                                        <button type="submit" class="btn btn-outline-sm btn-action-xs btn-outline-danger" data-confirm="Are you sure you want to delete '<?= encode($cat->name) ?>'?">
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../_foot.php'; ?>
