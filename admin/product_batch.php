<?php
// ============================================================================
// File: admin/product_batch.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Product Maintenance (Batch Operations: Insert, Price Update, Delete)
// Description: Admin batch operations interface supporting:
//              1. CSV batch insert with validation, preview, and atomic transaction.
//              2. Batch price adjustment (percentage or fixed) with preview & validation.
//              3. Batch product deletion with order_item dependency check and photo cleanup.
// ============================================================================
require_once __DIR__ . '/../_base.php';

// Enforce Admin authorization
auth('Admin');

// ----------------------------------------------------------------------------
// ACTION: Download CSV Template
// ----------------------------------------------------------------------------
if (req('action') === 'download_template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sumthin_product_batch_template.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // Output UTF-8 BOM so Microsoft Excel parses UTF-8 encoding correctly
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['name', 'category', 'brand', 'price', 'stock', 'description', 'warranty_months', 'specs']);
    fputcsv($out, [
        'sumthin GaN Fast Charger 65W',
        'Chargers & Cables',
        'sumthin Core',
        '149.00',
        '50',
        'Ultra-compact dual-port 65W GaN wall charger with foldable plug.',
        '24',
        'USB-C PD 3.0 (65W) + USB-A (18W), PPS, Over-current protection'
    ]);
    fputcsv($out, [
        'sumthin Wave ANC Earbuds',
        'Audio & Headphones',
        'sumthin Studio',
        '299.00',
        '35',
        'True wireless active noise-cancelling earbuds with 36-hour total battery.',
        '12',
        'Bluetooth 5.3, Hybrid ANC, IPX5 water resistance, Wireless Qi case'
    ]);
    fclose($out);
    exit;
}

// ----------------------------------------------------------------------------
// Determine Active Tab
// ----------------------------------------------------------------------------
$active_tab = req('tab', 'insert');
$batch_action = req('batch_action', '');

if ($batch_action === 'update_price' || $batch_action === 'price_preview' || $batch_action === 'price_confirm') {
    $active_tab = 'update';
} elseif ($batch_action === 'delete') {
    $active_tab = 'delete';
}

// Fetch categories and brands for dropdowns & validation maps
$all_categories = $db->query("SELECT id, name FROM `category` ORDER BY `name` ASC")->fetchAll();
$all_brands     = $db->query("SELECT id, name FROM `brand` ORDER BY `name` ASC")->fetchAll();

$category_map = [];
foreach ($all_categories as $c) {
    $category_map[strtolower(trim($c->name))] = (int)$c->id;
}

$brand_map = [];
foreach ($all_brands as $b) {
    $brand_map[strtolower(trim($b->name))] = (int)$b->id;
}

// ----------------------------------------------------------------------------
// TAB 3: BATCH DELETE EXECUTION
// ----------------------------------------------------------------------------
if (is_post() && $batch_action === 'delete') {
    verify_csrf();

    $product_ids = (array)req('product_ids', []);
    $clean_ids   = array_filter(array_map('intval', $product_ids), fn($id) => $id > 0);

    if (empty($clean_ids)) {
        set_flash('error', 'No products were selected for batch deletion.');
        redirect(base_url('admin/products.php'));
    }

    $deleted_items = [];
    $skipped_items = [];

    foreach ($clean_ids as $p_id) {
        $p_stmt = $db->prepare("SELECT id, name FROM `product` WHERE id = ?");
        $p_stmt->execute([$p_id]);
        $prod = $p_stmt->fetch();
        if (!$prod) continue;

        // Check if referenced in order_item table
        $oi_stmt = $db->prepare("SELECT COUNT(*) FROM `order_item` WHERE product_id = ?");
        $oi_stmt->execute([$p_id]);
        $order_count = (int)$oi_stmt->fetchColumn();

        if ($order_count > 0) {
            $skipped_items[] = "Product '{$prod->name}' (ID #{$prod->id}) has {$order_count} existing customer order(s).";
            continue;
        }

        // Fetch photo files to remove from disk
        $ph_stmt = $db->prepare("SELECT photo_url FROM `product_photo` WHERE product_id = ?");
        $ph_stmt->execute([$p_id]);
        $photos = $ph_stmt->fetchAll();

        // Delete product from database
        $del_stmt = $db->prepare("DELETE FROM `product` WHERE id = ?");
        $del_stmt->execute([$p_id]);

        // Clean up disk assets
        foreach ($photos as $ph) {
            delete_uploaded_image('products', $ph->photo_url);
        }

        $deleted_items[] = "Product '{$prod->name}' (ID #{$prod->id})";
    }

    $flash_messages = [];
    if (!empty($deleted_items)) {
        $flash_messages[] = "Successfully deleted " . count($deleted_items) . " product(s) and their photo assets.";
    }
    if (!empty($skipped_items)) {
        $flash_messages[] = "Skipped " . count($skipped_items) . " product(s) because they appear in customer orders:<br>&bull; " . implode('<br>&bull; ', $skipped_items);
    }

    if (!empty($deleted_items) && empty($skipped_items)) {
        set_flash('success', implode('<br>', $flash_messages));
    } elseif (!empty($deleted_items) && !empty($skipped_items)) {
        set_flash('warning', implode('<br>', $flash_messages));
    } else {
        set_flash('error', implode('<br>', $flash_messages));
    }

    redirect(base_url('admin/products.php'));
}

// ----------------------------------------------------------------------------
// TAB 1: CSV IMPORT PROCESSING & CONFIRMATION
// ----------------------------------------------------------------------------
$csv_preview_rows = [];
$csv_valid_count  = 0;
$csv_error_count  = 0;

if (is_post() && $batch_action === 'csv_upload') {
    verify_csrf();

    $file = $_FILES['csv_file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        set_flash('error', 'Please upload a valid CSV file.');
    } elseif ($file['size'] > 1048576) { // 1MB limit
        set_flash('error', 'File size exceeds maximum allowed limit of 1MB.');
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            set_flash('error', 'Invalid file type. Only .csv files are supported.');
        } else {
            // MIME check
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            $allowed_mimes = ['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/csv', 'text/x-csv'];
            if (!in_array($mime, $allowed_mimes, true)) {
                set_flash('error', 'Invalid file format. Please upload a plain text CSV file.');
            } else {
                // Read and normalize content
                $content = file_get_contents($file['tmp_name']);
                if (str_starts_with($content, "\xEF\xBB\xBF")) {
                    $content = substr($content, 3); // Strip UTF-8 BOM
                }
                $content = str_replace(["\r\n", "\r"], "\n", $content);

                $stream = fopen('php://memory', 'r+');
                fwrite($stream, $content);
                rewind($stream);

                $headers = fgetcsv($stream);
                if (!$headers) {
                    set_flash('error', 'CSV file appears to be empty.');
                } else {
                    $headers = array_map(fn($h) => strtolower(trim($h)), $headers);
                    $req_cols = ['name', 'category', 'price', 'stock', 'description'];
                    $missing_cols = array_diff($req_cols, $headers);

                    if (!empty($missing_cols)) {
                        set_flash('error', 'Missing required CSV column(s): ' . implode(', ', $missing_cols));
                    } else {
                        // Preload all existing slugs from database to prevent collision
                        $existing_slugs = [];
                        foreach ($db->query("SELECT slug FROM `product`")->fetchAll() as $s) {
                            $existing_slugs[$s->slug] = true;
                        }

                        $row_num = 1;
                        $valid_rows_for_session = [];

                        while (($row = fgetcsv($stream)) !== false) {
                            $row_num++;
                            if (count($row) === 1 && trim($row[0]) === '') {
                                continue; // Skip blank lines
                            }

                            if ($row_num > 201) { // 1 header + 200 rows limit
                                set_flash('error', 'CSV file exceeds maximum limit of 200 product rows.');
                                $csv_preview_rows = [];
                                break;
                            }

                            $data = [];
                            foreach ($headers as $idx => $header_name) {
                                $data[$header_name] = trim($row[$idx] ?? '');
                            }

                            $row_errors = [];

                            // 1. Name validation
                            $name = $data['name'] ?? '';
                            if ($name === '') {
                                $row_errors[] = 'Name is required.';
                            } elseif (mb_strlen($name) > 150) {
                                $row_errors[] = 'Name exceeds 150 characters.';
                            }

                            // 2. Category validation (must exist by name)
                            $cat_name = $data['category'] ?? '';
                            $cat_id   = null;
                            if ($cat_name === '') {
                                $row_errors[] = 'Category is required.';
                            } elseif (!isset($category_map[strtolower($cat_name)])) {
                                $row_errors[] = "Category '{$cat_name}' does not exist.";
                            } else {
                                $cat_id = $category_map[strtolower($cat_name)];
                            }

                            // 3. Brand validation (optional, but must exist if provided)
                            $brand_name = $data['brand'] ?? '';
                            $brand_id   = null;
                            if ($brand_name !== '') {
                                if (!isset($brand_map[strtolower($brand_name)])) {
                                    $row_errors[] = "Brand '{$brand_name}' does not exist.";
                                } else {
                                    $brand_id = $brand_map[strtolower($brand_name)];
                                }
                            }

                            // 4. Price validation
                            $price_str = $data['price'] ?? '';
                            $price     = 0.0;
                            if ($price_str === '' || !is_numeric($price_str)) {
                                $row_errors[] = 'Valid price is required.';
                            } else {
                                $price = (float)$price_str;
                                if ($price <= 0) {
                                    $row_errors[] = 'Price must be greater than RM 0.00.';
                                } elseif ($price > 999999.99) {
                                    $row_errors[] = 'Price exceeds maximum allowed value.';
                                }
                            }

                            // 5. Stock validation
                            $stock_str = $data['stock'] ?? '';
                            $stock     = 0;
                            if ($stock_str === '' || !ctype_digit($stock_str)) {
                                $row_errors[] = 'Stock must be an integer >= 0.';
                            } else {
                                $stock = (int)$stock_str;
                            }

                            // 6. Description validation
                            $desc = $data['description'] ?? '';
                            if ($desc === '') {
                                $row_errors[] = 'Description is required.';
                            }

                            // 7. Warranty months (optional, defaults to 12)
                            $warranty_str = $data['warranty_months'] ?? '12';
                            $warranty = 12;
                            if ($warranty_str !== '') {
                                if (!ctype_digit($warranty_str) || (int)$warranty_str > 120) {
                                    $row_errors[] = 'Warranty must be between 0 and 120 months.';
                                } else {
                                    $warranty = (int)$warranty_str;
                                }
                            }

                            // 8. Specs (optional)
                            $specs = $data['specs'] ?? null;

                            // 9. Generate unique slug
                            $slug = '';
                            if ($name !== '') {
                                $base_slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-'));
                                if ($base_slug === '') $base_slug = 'product';
                                $slug = $base_slug;
                                $counter = 1;
                                while (isset($existing_slugs[$slug])) {
                                    $slug = $base_slug . '-' . (++$counter);
                                }
                                $existing_slugs[$slug] = true;
                            }

                            $is_valid = empty($row_errors);
                            if ($is_valid) {
                                $csv_valid_count++;
                                $valid_rows_for_session[] = [
                                    'name'            => $name,
                                    'category_id'     => $cat_id,
                                    'category_name'   => $cat_name,
                                    'brand_id'        => $brand_id,
                                    'brand_name'      => $brand_name,
                                    'price'           => number_format($price, 2, '.', ''),
                                    'stock'           => $stock,
                                    'description'     => $desc,
                                    'warranty_months' => $warranty,
                                    'specs'           => $specs,
                                    'slug'            => $slug
                                ];
                            } else {
                                $csv_error_count++;
                            }

                            $csv_preview_rows[] = [
                                'row_num'  => $row_num,
                                'data'     => $data,
                                'slug'     => $slug,
                                'errors'   => $row_errors,
                                'is_valid' => $is_valid
                            ];
                        }

                        if (!empty($valid_rows_for_session)) {
                            $_SESSION['batch_csv_valid_rows'] = $valid_rows_for_session;
                        } else {
                            unset($_SESSION['batch_csv_valid_rows']);
                        }
                    }
                }
                fclose($stream);
            }
        }
    }
}

// Confirm CSV Import Execution
if (is_post() && $batch_action === 'csv_confirm') {
    verify_csrf();

    $valid_rows = $_SESSION['batch_csv_valid_rows'] ?? [];
    if (empty($valid_rows)) {
        set_flash('error', 'No pending valid CSV rows to import or session expired.');
        redirect(base_url('admin/product_batch.php?tab=insert'));
    }

    try {
        $db->beginTransaction();

        $prod_stmt = $db->prepare("
            INSERT INTO `product` (`category_id`, `brand_id`, `name`, `slug`, `description`, `price`, `stock`, `warranty_months`, `specs`, `is_featured`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
        ");

        $photo_stmt = $db->prepare("
            INSERT INTO `product_photo` (`product_id`, `photo_url`, `is_primary`, `sort_order`)
            VALUES (?, 'default_product.png', 1, 0)
        ");

        $imported_count = 0;
        foreach ($valid_rows as $row) {
            $prod_stmt->execute([
                $row['category_id'],
                $row['brand_id'],
                $row['name'],
                $row['slug'],
                $row['description'],
                $row['price'],
                $row['stock'],
                $row['warranty_months'],
                $row['specs']
            ]);
            $new_prod_id = (int)$db->lastInsertId();
            $photo_stmt->execute([$new_prod_id]);
            $imported_count++;
        }

        $db->commit();
        unset($_SESSION['batch_csv_valid_rows']);

        set_flash('success', "Batch import successful! Inserted {$imported_count} new product(s) in a single atomic transaction.");
        redirect(base_url('admin/products.php'));
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        set_flash('error', 'Database transaction failed during import: ' . $e->getMessage());
        redirect(base_url('admin/product_batch.php?tab=insert'));
    }
}

// ----------------------------------------------------------------------------
// TAB 2: BATCH PRICE UPDATE PROCESSING & CONFIRMATION
// ----------------------------------------------------------------------------
$price_preview_rows = [];
$preselected_ids    = (array)req('product_ids', []);
$preselected_ids    = array_filter(array_map('intval', $preselected_ids), fn($id) => $id > 0);

if (is_post() && $batch_action === 'price_preview') {
    verify_csrf();

    $scope_type   = req('scope_type', 'filter'); // 'checked' or 'filter'
    $scope_cat_id = req('scope_cat_id');
    $scope_brand_id = req('scope_brand_id');
    $p_ids        = (array)req('product_ids', []);
    $p_ids        = array_filter(array_map('intval', $p_ids), fn($id) => $id > 0);

    $price_action = req('price_action', 'increase'); // 'increase' or 'decrease'
    $calc_type    = req('calc_type', 'percent');     // 'percent' or 'fixed'
    $amount       = (float)req('amount', 0);

    if ($amount <= 0) {
        set_flash('error', 'Please enter a valid adjustment amount greater than 0.');
    } else {
        // Query candidate products based on scope
        $where_clauses = ["1=1"];
        $params        = [];

        if ($scope_type === 'checked') {
            if (empty($p_ids)) {
                set_flash('error', 'No products were selected for price adjustment.');
            } else {
                $in_placeholders = implode(',', array_fill(0, count($p_ids), '?'));
                $where_clauses[] = "p.id IN ($in_placeholders)";
                $params          = array_merge($params, $p_ids);
            }
        } else {
            if ($scope_cat_id !== '') {
                $where_clauses[] = "p.category_id = ?";
                $params[]        = (int)$scope_cat_id;
            }
            if ($scope_brand_id !== '') {
                $where_clauses[] = "p.brand_id = ?";
                $params[]        = (int)$scope_brand_id;
            }
        }

        if (empty(get_flash('error'))) {
            $where_sql = implode(' AND ', $where_clauses);
            $stmt = $db->prepare("
                SELECT p.id, p.name, p.price, c.name AS category_name, b.name AS brand_name
                FROM `product` p
                JOIN `category` c ON p.category_id = c.id
                LEFT JOIN `brand` b ON p.brand_id = b.id
                WHERE $where_sql
                ORDER BY p.name ASC
            ");
            $stmt->execute($params);
            $candidate_products = $stmt->fetchAll();

            if (empty($candidate_products)) {
                set_flash('error', 'No products matched the selected category / brand criteria.');
            } else {
                $has_invalid_price = false;
                $staged_updates    = [];

                foreach ($candidate_products as $cp) {
                    $old_price = (float)$cp->price;
                    $diff      = 0.0;

                    if ($calc_type === 'percent') {
                        $diff = round($old_price * ($amount / 100), 2);
                    } else {
                        $diff = round($amount, 2);
                    }

                    $new_price = ($price_action === 'increase') ? round($old_price + $diff, 2) : round($old_price - $diff, 2);
                    $row_error = null;

                    // Strictly enforce: Prevent price <= 0
                    if ($new_price <= 0) {
                        $row_error = 'Price would be <= RM 0.00 (invalid)';
                        $has_invalid_price = true;
                    }

                    $preview_item = [
                        'id'            => (int)$cp->id,
                        'name'          => $cp->name,
                        'category_name' => $cp->category_name,
                        'brand_name'    => $cp->brand_name,
                        'old_price'     => $old_price,
                        'diff'          => ($price_action === 'increase' ? '+' : '-') . number_format($diff, 2),
                        'new_price'     => $new_price,
                        'error'         => $row_error,
                        'is_valid'      => ($row_error === null)
                    ];

                    $price_preview_rows[] = $preview_item;
                    if ($preview_item['is_valid']) {
                        $staged_updates[] = [
                            'id'        => (int)$cp->id,
                            'new_price' => $new_price
                        ];
                    }
                }

                $_SESSION['batch_price_update'] = $staged_updates;
            }
        }
    }
}

// Confirm Batch Price Update Execution
if (is_post() && $batch_action === 'price_confirm') {
    verify_csrf();

    $staged_updates = $_SESSION['batch_price_update'] ?? [];
    if (empty($staged_updates)) {
        set_flash('error', 'No pending price updates found or session expired.');
        redirect(base_url('admin/product_batch.php?tab=update'));
    }

    try {
        $db->beginTransaction();
        $upd_stmt = $db->prepare("UPDATE `product` SET `price` = ? WHERE `id` = ?");
        $updated_cnt = 0;

        foreach ($staged_updates as $u) {
            if ($u['new_price'] <= 0) {
                throw new Exception("Product ID #{$u['id']} has an invalid price <= 0.");
            }
            $upd_stmt->execute([number_format((float)$u['new_price'], 2, '.', ''), $u['id']]);
            $updated_cnt++;
        }

        $db->commit();
        unset($_SESSION['batch_price_update']);

        set_flash('success', "Batch price update complete! Updated pricing for {$updated_cnt} product(s).");
        redirect(base_url('admin/products.php'));
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        set_flash('error', 'Price update transaction failed: ' . $e->getMessage());
        redirect(base_url('admin/product_batch.php?tab=update'));
    }
}

// Products list for Tab 3 direct deletion interface
$all_prods_for_delete = [];
if ($active_tab === 'delete') {
    $all_prods_for_delete = $db->query("
        SELECT p.id, p.name, p.price, p.stock, c.name AS category_name,
               COALESCE(pp.photo_url, 'default_product.png') AS photo_url,
               (SELECT COUNT(*) FROM `order_item` oi WHERE oi.product_id = p.id) AS order_count
        FROM `product` p
        JOIN `category` c ON p.category_id = c.id
        LEFT JOIN `product_photo` pp ON p.id = pp.product_id AND pp.is_primary = 1
        ORDER BY p.id DESC
    ")->fetchAll();
}

$title = 'Product Batch Operations';
require_once __DIR__ . '/../_head.php';
?>

<div class="container">
    <!-- Header -->
    <div class="admin-header-panel">
        <div>
            <h1 style="font-size:24px; font-weight:800; color:var(--dark);">Product Batch Operations</h1>
            <p style="font-size:14px; color:var(--gray); margin-top:4px;">
                Execute bulk CSV imports, multi-product price recalculations, and safe batch removals.
            </p>
        </div>
        <div>
            <a href="<?= base_url('admin/products.php') ?>" class="btn btn-outline-sm" style="height:38px; display:inline-flex; align-items:center;">
                &larr; Back to Products
            </a>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div style="display:flex; gap:10px; margin-bottom:24px; border-bottom:1px solid var(--border); padding-bottom:12px;">
        <a href="<?= base_url('admin/product_batch.php?tab=insert') ?>" 
           class="btn <?= $active_tab === 'insert' ? 'btn-primary-sm' : 'btn-outline-sm' ?>" 
           style="height:38px; display:inline-flex; align-items:center; gap:6px;">
            📁 1. Batch Insert (CSV)
        </a>
        <a href="<?= base_url('admin/product_batch.php?tab=update') ?>" 
           class="btn <?= $active_tab === 'update' ? 'btn-primary-sm' : 'btn-outline-sm' ?>" 
           style="height:38px; display:inline-flex; align-items:center; gap:6px;">
            🏷️ 2. Batch Price Update
        </a>
        <a href="<?= base_url('admin/product_batch.php?tab=delete') ?>" 
           class="btn <?= $active_tab === 'delete' ? 'btn-primary-sm' : 'btn-outline-sm' ?>" 
           style="height:38px; display:inline-flex; align-items:center; gap:6px;">
            🗑️ 3. Batch Delete
        </a>
    </div>

    <!-- ===================================================================== -->
    <!-- TAB 1: BATCH INSERT (CSV)                                              -->
    <!-- ===================================================================== -->
    <?php if ($active_tab === 'insert'): ?>
        <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:24px; margin-bottom:24px;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px; margin-bottom:20px;">
                <div>
                    <h2 style="font-size:18px; font-weight:700; color:var(--dark); margin-bottom:4px;">CSV Catalog Import</h2>
                    <p style="font-size:13px; color:var(--gray); margin:0;">
                        Upload a structured CSV containing up to 200 products. Supports Windows CRLF endings and UTF-8 BOM.
                    </p>
                </div>
                <div>
                    <a href="<?= base_url('admin/product_batch.php?action=download_template') ?>" class="btn btn-outline-sm" style="display:inline-flex; align-items:center; gap:6px;">
                        📥 Download Template CSV
                    </a>
                </div>
            </div>

            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px; margin-bottom:24px; font-size:13px; color:#475569;">
                <strong>CSV Format Guidelines:</strong>
                <ul style="margin:8px 0 0 20px; padding:0; line-height:1.6;">
                    <li><strong>Columns:</strong> <code>name</code>, <code>category</code>, <code>brand</code>, <code>price</code>, <code>stock</code>, <code>description</code>, <code>warranty_months</code>, <code>specs</code></li>
                    <li><strong>Category & Brand:</strong> Must match existing catalog names (e.g. <em>Audio & Headphones</em>, <em>Chargers & Cables</em>).</li>
                    <li><strong>Photos:</strong> Newly imported products are automatically assigned <code>default_product.png</code> as their primary photo.</li>
                    <li><strong>Transaction:</strong> All valid products are inserted in a single atomic transaction.</li>
                </ul>
            </div>

            <!-- Upload Form -->
            <form action="<?= base_url('admin/product_batch.php?tab=insert') ?>" method="POST" enctype="multipart/form-data" style="margin-bottom:20px;">
                <?= csrf_field() ?>
                <input type="hidden" name="batch_action" value="csv_upload">

                <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                    <div style="flex:1; min-width:280px;">
                        <input type="file" name="csv_file" accept=".csv,text/csv" required class="form-control" style="padding:8px 12px;">
                    </div>
                    <div>
                        <button type="submit" class="btn btn-primary" style="height:42px; display:inline-flex; align-items:center; gap:6px;">
                            Upload & Preview CSV
                        </button>
                    </div>
                </div>
            </form>

            <!-- Preview Table (If Uploaded) -->
            <?php if (!empty($csv_preview_rows)): ?>
                <hr style="border:0; border-top:1px solid var(--border); margin:24px 0;">

                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:10px;">
                    <div>
                        <h3 style="font-size:16px; font-weight:700; color:var(--dark); margin:0;">
                            CSV Import Preview (<?= count($csv_preview_rows) ?> rows)
                        </h3>
                        <div style="font-size:13px; color:var(--gray); margin-top:4px;">
                            Valid: <strong style="color:var(--success);"><?= $csv_valid_count ?></strong> &bull; 
                            Errors: <strong style="color:var(--error);"><?= $csv_error_count ?></strong>
                        </div>
                    </div>
                    <?php if ($csv_valid_count > 0): ?>
                        <form action="<?= base_url('admin/product_batch.php?tab=insert') ?>" method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="batch_action" value="csv_confirm">
                            <button type="submit" class="btn btn-primary" data-confirm="Confirm import of <?= $csv_valid_count ?> valid product(s)?">
                                Confirm Import (<?= $csv_valid_count ?> Products)
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="table-responsive">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th style="width:40px;">#</th>
                                <th>Name</th>
                                <th>Category</th>
                                <th>Brand</th>
                                <th>Price</th>
                                <th>Stock</th>
                                <th>Slug</th>
                                <th>Validation Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($csv_preview_rows as $pr): ?>
                                <tr style="<?= !$pr['is_valid'] ? 'background:#fef2f2;' : '' ?>">
                                    <td><?= $pr['row_num'] ?></td>
                                    <td>
                                        <div style="font-weight:600; color:var(--dark);"><?= encode($pr['data']['name'] ?? '') ?></div>
                                        <div style="font-size:11px; color:var(--gray); max-width:240px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                            <?= encode($pr['data']['description'] ?? '') ?>
                                        </div>
                                    </td>
                                    <td><?= encode($pr['data']['category'] ?? '') ?></td>
                                    <td><?= encode($pr['data']['brand'] ?? '-') ?></td>
                                    <td>RM <?= encode($pr['data']['price'] ?? '0.00') ?></td>
                                    <td><?= encode($pr['data']['stock'] ?? '0') ?></td>
                                    <td><code style="font-size:11px;"><?= encode($pr['slug'] ?: '-') ?></code></td>
                                    <td>
                                        <?php if ($pr['is_valid']): ?>
                                            <span class="role-pill pill-member" style="background:#dcfce7; color:#15803d;">
                                                ✓ Ready to Import
                                            </span>
                                        <?php else: ?>
                                            <div style="color:var(--error); font-size:12px; font-weight:600;">
                                                <?= implode('<br>', array_map('encode', $pr['errors'])) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- ===================================================================== -->
    <!-- TAB 2: BATCH PRICE UPDATE                                              -->
    <!-- ===================================================================== -->
    <?php if ($active_tab === 'update'): ?>
        <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:24px; margin-bottom:24px;">
            <div style="margin-bottom:20px;">
                <h2 style="font-size:18px; font-weight:700; color:var(--dark); margin-bottom:4px;">Batch Price Adjustment</h2>
                <p style="font-size:13px; color:var(--gray); margin:0;">
                    Increase or decrease prices by percentage or fixed Ringgit amount. Previews changes and strictly blocks prices &le; RM 0.00.
                </p>
            </div>

            <!-- Parameters Form -->
            <form action="<?= base_url('admin/product_batch.php?tab=update') ?>" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="batch_action" value="price_preview">

                <!-- Hidden inputs for preselected product IDs from products.php -->
                <?php foreach ($preselected_ids as $pid): ?>
                    <input type="hidden" name="product_ids[]" value="<?= (int)$pid ?>">
                <?php endforeach; ?>

                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin-bottom:20px;">
                    <!-- Scope Selection -->
                    <div>
                        <label class="form-label">Adjustment Scope</label>
                        <select name="scope_type" id="scope_type" class="form-select">
                            <?php if (!empty($preselected_ids)): ?>
                                <option value="checked" selected>Selected Products (<?= count($preselected_ids) ?> checked)</option>
                            <?php endif; ?>
                            <option value="filter" <?= empty($preselected_ids) ? 'selected' : '' ?>>Filter by Category / Brand</option>
                        </select>
                    </div>

                    <!-- Category Filter -->
                    <div class="scope-filter-field">
                        <label class="form-label">Category</label>
                        <select name="scope_cat_id" class="form-select">
                            <option value="">All Categories</option>
                            <?php foreach ($all_categories as $c): ?>
                                <option value="<?= (int)$c->id ?>" <?= req('scope_cat_id') == $c->id ? 'selected' : '' ?>>
                                    <?= encode($c->name) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Brand Filter -->
                    <div class="scope-filter-field">
                        <label class="form-label">Brand</label>
                        <select name="scope_brand_id" class="form-select">
                            <option value="">All Brands</option>
                            <?php foreach ($all_brands as $b): ?>
                                <option value="<?= (int)$b->id ?>" <?= req('scope_brand_id') == $b->id ? 'selected' : '' ?>>
                                    <?= encode($b->name) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Action: Increase or Decrease -->
                    <div>
                        <label class="form-label">Action</label>
                        <select name="price_action" class="form-select">
                            <option value="increase" <?= req('price_action') === 'increase' ? 'selected' : '' ?>>Increase Price (+)</option>
                            <option value="decrease" <?= req('price_action') === 'decrease' ? 'selected' : '' ?>>Decrease Price (-)</option>
                        </select>
                    </div>

                    <!-- Calculation Type: Percent or Fixed -->
                    <div>
                        <label class="form-label">Calculation Mode</label>
                        <select name="calc_type" class="form-select">
                            <option value="percent" <?= req('calc_type') === 'percent' ? 'selected' : '' ?>>Percentage (%)</option>
                            <option value="fixed" <?= req('calc_type') === 'fixed' ? 'selected' : '' ?>>Fixed Amount (RM)</option>
                        </select>
                    </div>

                    <!-- Amount -->
                    <div>
                        <label class="form-label">Amount (% or RM)</label>
                        <input type="number" name="amount" step="0.01" min="0.01" value="<?= encode(req('amount', '10.00')) ?>" required class="form-control" placeholder="10.00">
                    </div>
                </div>

                <div style="display:flex; justify-content:flex-end;">
                    <button type="submit" class="btn btn-primary" style="height:40px; display:inline-flex; align-items:center; gap:6px;">
                        🔍 Preview Price Adjustments
                    </button>
                </div>
            </form>

            <!-- Price Preview Table -->
            <?php if (!empty($price_preview_rows)): ?>
                <hr style="border:0; border-top:1px solid var(--border); margin:24px 0;">

                <?php
                $valid_price_count   = count(array_filter($price_preview_rows, fn($r) => $r['is_valid']));
                $invalid_price_count = count($price_preview_rows) - $valid_price_count;
                ?>

                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:10px;">
                    <div>
                        <h3 style="font-size:16px; font-weight:700; color:var(--dark); margin:0;">
                            Price Adjustment Preview (<?= count($price_preview_rows) ?> products)
                        </h3>
                        <div style="font-size:13px; color:var(--gray); margin-top:4px;">
                            Valid adjustments: <strong style="color:var(--success);"><?= $valid_price_count ?></strong>
                            <?php if ($invalid_price_count > 0): ?>
                                &bull; Blocked (&le; RM 0.00): <strong style="color:var(--error);"><?= $invalid_price_count ?></strong>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($valid_price_count > 0): ?>
                        <form action="<?= base_url('admin/product_batch.php?tab=update') ?>" method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="batch_action" value="price_confirm">
                            <button type="submit" class="btn btn-primary" data-confirm="Apply new pricing to <?= $valid_price_count ?> product(s)? This will update the database.">
                                Confirm Price Update (<?= $valid_price_count ?> Products)
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <?php if ($invalid_price_count > 0): ?>
                    <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:12px 16px; margin-bottom:16px; font-size:13px; color:#b91c1c;">
                        <strong>Note:</strong> Products resulting in prices &le; RM 0.00 are highlighted in red and will be excluded from the update.
                    </div>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Product Model</th>
                                <th>Category</th>
                                <th>Brand</th>
                                <th>Current Price</th>
                                <th>Adjustment</th>
                                <th>New Price</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($price_preview_rows as $pr): ?>
                                <tr style="<?= !$pr['is_valid'] ? 'background:#fef2f2;' : '' ?>">
                                    <td style="font-weight:600; color:var(--dark);"><?= encode($pr['name']) ?></td>
                                    <td><?= encode($pr['category_name']) ?></td>
                                    <td><?= encode($pr['brand_name'] ?? '-') ?></td>
                                    <td style="font-weight:600; color:var(--dark);">RM <?= number_format($pr['old_price'], 2) ?></td>
                                    <td style="font-weight:600; color:<?= str_starts_with($pr['diff'], '+') ? 'var(--success)' : 'var(--error)' ?>;">
                                        <?= encode($pr['diff']) ?>
                                    </td>
                                    <td style="font-weight:800; color:<?= $pr['is_valid'] ? 'var(--dark)' : 'var(--error)' ?>;">
                                        RM <?= number_format($pr['new_price'], 2) ?>
                                    </td>
                                    <td>
                                        <?php if ($pr['is_valid']): ?>
                                            <span class="role-pill pill-member" style="background:#dcfce7; color:#15803d;">
                                                ✓ Valid
                                            </span>
                                        <?php else: ?>
                                            <span class="role-pill pill-admin" style="background:#fee2e2; color:#b91c1c;">
                                                ✕ <?= encode($pr['error']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- ===================================================================== -->
    <!-- TAB 3: BATCH DELETE                                                    -->
    <!-- ===================================================================== -->
    <?php if ($active_tab === 'delete'): ?>
        <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:24px; margin-bottom:24px;">
            <div style="margin-bottom:20px;">
                <h2 style="font-size:18px; font-weight:700; color:var(--dark); margin-bottom:4px;">Batch Product Removal</h2>
                <p style="font-size:13px; color:var(--gray); margin:0;">
                    Select products to delete. Products appearing in customer orders are safely skipped to preserve order history, and all photo files of deleted products are wiped from disk.
                </p>
            </div>

            <form action="<?= base_url('admin/product_batch.php?tab=delete') ?>" method="POST" id="batch-delete-form">
                <?= csrf_field() ?>
                <input type="hidden" name="batch_action" value="delete">

                <div id="batch-toolbar" style="display:none; background:#eff6ff; border:1px solid #bfdbfe; border-radius:var(--radius); padding:12px 18px; margin-bottom:14px; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                    <div style="font-size:14px; font-weight:600; color:#1e40af;">
                        <span id="batch-selected-count">0</span> product(s) selected
                    </div>
                    <div>
                        <button type="submit" class="btn btn-outline-sm" style="background:#fff; color:var(--error); border-color:#fca5a5;" data-confirm="Are you sure you want to delete all selected products? Products with customer orders will be skipped automatically.">
                            Delete Selected Products
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th style="width:40px; text-align:center;">
                                    <input type="checkbox" id="check-all-products" title="Select All">
                                </th>
                                <th style="width:50px;">Photo</th>
                                <th>Product Model</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Stock</th>
                                <th>Order History</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($all_prods_for_delete)): ?>
                                <tr>
                                    <td colspan="7" style="text-align:center; padding:30px; color:var(--gray);">
                                        No products available.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($all_prods_for_delete as $p): ?>
                                    <tr>
                                        <td style="text-align:center;">
                                            <input type="checkbox" name="product_ids[]" value="<?= (int)$p->id ?>" class="product-checkbox">
                                        </td>
                                        <td>
                                            <div style="width:44px; height:44px; background:#0b0f19; border-radius:6px; overflow:hidden; display:flex; align-items:center; justify-content:center; border:1px solid #1e293b;">
                                                <img src="<?= product_photo_url($p->photo_url) ?>" 
                                                     alt="<?= encode($p->name) ?>" 
                                                     style="max-width:100%; max-height:100%; object-fit:contain;">
                                            </div>
                                        </td>
                                        <td style="font-weight:600; color:var(--dark);"><?= encode($p->name) ?></td>
                                        <td><?= encode($p->category_name) ?></td>
                                        <td style="font-weight:700; color:var(--dark);">RM <?= number_format((float)$p->price, 2) ?></td>
                                        <td><?= (int)$p->stock ?></td>
                                        <td>
                                            <?php if ($p->order_count > 0): ?>
                                                <span class="role-pill" style="background:#fef3c7; color:#b45309; font-size:11px;">
                                                    🔒 Linked to <?= (int)$p->order_count ?> order(s) (Protected)
                                                </span>
                                            <?php else: ?>
                                                <span class="role-pill" style="background:#f1f5f9; color:#475569; font-size:11px;">
                                                    0 orders (Safe to delete)
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../_foot.php'; ?>
