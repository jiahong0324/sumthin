<?php
// ============================================================================
// File: admin/products.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Product Maintenance (Admin Listing & Searching)
// Description: Product maintenance list with multi-criteria searching, category
//              filtering, stock status indicators, sorting, and pagination.
// ============================================================================
require_once __DIR__ . '/../_base.php';

// Enforce Admin authorization
auth('Admin');

// Read search and filter parameters
$q            = req('q');
$cat_id       = req('cat_id');
$stock_filter = req('stock_filter');
$sort         = req('sort', 'id_desc');
$page         = max(1, (int)req('page', 1));
$limit        = 10;
$offset       = ($page - 1) * $limit;

// Fetch all categories for filter dropdown
$categories = $db->query("SELECT `id`, `name` FROM `category` ORDER BY `name` ASC")->fetchAll();

// Build dynamic WHERE clause
$where_clauses = ["1=1"];
$params        = [];

if ($q !== '') {
    $where_clauses[] = "(p.name LIKE ? OR p.description LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
}

if ($cat_id !== '') {
    $where_clauses[] = "p.category_id = ?";
    $params[] = (int)$cat_id;
}

if ($stock_filter === 'out') {
    $where_clauses[] = "p.stock = 0";
} elseif ($stock_filter === 'low') {
    $where_clauses[] = "p.stock > 0 AND p.stock <= 15";
} elseif ($stock_filter === 'in') {
    $where_clauses[] = "p.stock > 15";
}

$where_sql = implode(' AND ', $where_clauses);

// Build ORDER BY clause
$order_sql = match ($sort) {
    'name_asc'   => 'p.name ASC',
    'name_desc'  => 'p.name DESC',
    'price_asc'  => 'p.price ASC',
    'price_desc' => 'p.price DESC',
    'stock_asc'  => 'p.stock ASC',
    'stock_desc' => 'p.stock DESC',
    'id_asc'     => 'p.id ASC',
    default      => 'p.id DESC',
};

// 1. Get total matching count for pagination
$count_stmt = $db->prepare("SELECT COUNT(*) FROM `product` p WHERE $where_sql");
$count_stmt->execute($params);
$total_rows  = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $limit));

// 2. Fetch paginated records with category and photo
$sql = "
    SELECT p.*, c.name AS category_name, b.name AS brand_name,
           COALESCE(pp.photo_url, 'default_product.png') AS photo_url
    FROM `product` p
    JOIN `category` c ON p.category_id = c.id
    LEFT JOIN `brand` b ON p.brand_id = b.id
    LEFT JOIN `product_photo` pp ON p.id = pp.product_id AND pp.is_primary = 1
    WHERE $where_sql
    ORDER BY $order_sql
    LIMIT $limit OFFSET $offset
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$title = 'Product Maintenance';
require_once __DIR__ . '/../_head.php';
?>

<div class="container">
    <!-- Top Action Bar -->
    <div class="admin-header-panel">
        <div>
            <h1 style="font-size:24px; font-weight:800; color:var(--dark);">Product Maintenance</h1>
            <p style="font-size:14px; color:var(--gray); margin-top:4px;">
                Manage sumthin tech gadgets, inventory levels, pricing, and high-res photography.
            </p>
        </div>
        <div>
            <a href="<?= base_url('admin/product_add.php') ?>" class="btn btn-primary">
                + Add New Product
            </a>
        </div>
    </div>

    <!-- Search, Category Filter, and Sorting Controls -->
    <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:20px; margin-bottom:24px;">
        <form action="<?= base_url('admin/products.php') ?>" method="GET" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
            <!-- Keyword Search -->
            <div style="flex:2; min-width:200px;">
                <label for="q" class="form-label">Search Gadgets</label>
                <input type="text" name="q" id="q" value="<?= encode($q) ?>" placeholder="Search by model, name or keyword..." class="form-control">
            </div>

            <!-- Category Filter -->
            <div style="flex:1; min-width:160px;">
                <label for="cat_id" class="form-label">Category</label>
                <select name="cat_id" id="cat_id" class="form-select">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int)$cat->id ?>" <?= ((string)$cat_id === (string)$cat->id) ? 'selected' : '' ?>>
                            <?= encode($cat->name) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Stock Filter -->
            <div style="flex:1; min-width:150px;">
                <label for="stock_filter" class="form-label">Inventory Level</label>
                <select name="stock_filter" id="stock_filter" class="form-select">
                    <option value="">All Stock</option>
                    <option value="in" <?= $stock_filter === 'in' ? 'selected' : '' ?>>In Stock (&gt;15)</option>
                    <option value="low" <?= $stock_filter === 'low' ? 'selected' : '' ?>>Low Stock (&le;15)</option>
                    <option value="out" <?= $stock_filter === 'out' ? 'selected' : '' ?>>Out of Stock (0)</option>
                </select>
            </div>

            <!-- Sort By -->
            <div style="flex:1; min-width:150px;">
                <label for="sort" class="form-label">Sort Order</label>
                <select name="sort" id="sort" class="form-select">
                    <option value="id_desc" <?= $sort === 'id_desc' ? 'selected' : '' ?>>Newest Added</option>
                    <option value="id_asc" <?= $sort === 'id_asc' ? 'selected' : '' ?>>Oldest Added</option>
                    <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name (A - Z)</option>
                    <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Name (Z - A)</option>
                    <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price (Low &rarr; High)</option>
                    <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price (High &rarr; Low)</option>
                    <option value="stock_asc" <?= $sort === 'stock_asc' ? 'selected' : '' ?>>Stock (Low &rarr; High)</option>
                </select>
            </div>

            <!-- Buttons -->
            <div style="display:flex; gap:8px;">
                <button type="submit" class="btn btn-primary-sm" style="height:38px;">Apply Filter</button>
                <a href="<?= base_url('admin/products.php') ?>" class="btn btn-outline-sm" style="height:38px; display:inline-flex; align-items:center;">Reset</a>
            </div>
        </form>
    </div>

    <!-- Results Count Bar -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; font-size:14px; color:var(--gray);">
        <span>Showing <strong><?= count($products) ?></strong> of <strong><?= $total_rows ?></strong> matching product(s)</span>
        <span>Page <strong><?= $page ?></strong> of <strong><?= $total_pages ?></strong></span>
    </div>

    <!-- Products Table -->
    <div class="table-responsive">
        <table class="table-custom">
            <thead>
                <tr>
                    <th style="width:60px;">Photo</th>
                    <th>Product Model & Specs</th>
                    <th>Category</th>
                    <th>Price</th>
                    <th>Stock Level</th>
                    <th>Featured</th>
                    <th style="text-align:right; width:190px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center; padding:40px; color:var(--gray);">
                            No products found matching your search criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($products as $p): ?>
                        <tr>
                            <!-- Thumbnail -->
                            <td>
                                <div style="width:52px; height:52px; background:#0b0f19; border-radius:6px; overflow:hidden; display:flex; align-items:center; justify-content:center; border:1px solid #1e293b;">
                                    <img src="<?= base_url('uploads/products/' . encode($p->photo_url)) ?>" 
                                         alt="<?= encode($p->name) ?>" 
                                         style="max-width:100%; max-height:100%; object-fit:contain;">
                                </div>
                            </td>

                            <!-- Product Name & Description Preview -->
                            <td>
                                <div style="font-weight:700; color:var(--dark); margin-bottom:3px;">
                                    <a href="<?= base_url('admin/product_detail.php?id=' . (int)$p->id) ?>" style="text-decoration:none; color:inherit;">
                                        <?= encode($p->name) ?>
                                    </a>
                                </div>
                                <div style="font-size:12px; color:var(--gray); max-width:380px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                    <?= encode($p->description) ?>
                                </div>
                            </td>

                            <!-- Category & Brand -->
                            <td>
                                <span class="role-pill pill-member" style="font-size:11px;">
                                    <?= encode($p->category_name) ?>
                                </span>
                                <?php if ($p->brand_name): ?>
                                    <div style="font-size:11px; color:var(--gray); margin-top:3px;"><?= encode($p->brand_name) ?></div>
                                <?php endif; ?>
                            </td>

                            <!-- Price -->
                            <td style="font-weight:700; color:var(--dark);">
                                RM <?= number_format((float)$p->price, 2) ?>
                            </td>

                            <!-- Stock -->
                            <td>
                                <?php if ($p->stock == 0): ?>
                                    <span class="role-pill pill-admin" style="background:#fee2e2; color:#b91c1c;">
                                        Out of Stock (0)
                                    </span>
                                <?php elseif ($p->stock <= 15): ?>
                                    <span class="role-pill" style="background:#fef3c7; color:#b45309;">
                                        Low Stock (<?= (int)$p->stock ?>)
                                    </span>
                                <?php else: ?>
                                    <span class="role-pill" style="background:#dcfce7; color:#15803d;">
                                        In Stock (<?= (int)$p->stock ?>)
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- Featured Badge -->
                            <td>
                                <?= $p->is_featured ? '<span style="color:#d97706; font-size:12px; font-weight:700;">★ Featured</span>' : '<span style="color:var(--gray); font-size:12px;">Standard</span>' ?>
                            </td>

                            <!-- Action Buttons -->
                            <td style="text-align:right;">
                                <div style="display:inline-flex; gap:6px;">
                                    <a href="<?= base_url('admin/product_detail.php?id=' . (int)$p->id) ?>" class="btn btn-outline-sm" title="View Details">
                                        View
                                    </a>
                                    <a href="<?= base_url('admin/product_edit.php?id=' . (int)$p->id) ?>" class="btn btn-outline-sm" title="Edit Product">
                                        Edit
                                    </a>
                                    <form action="<?= base_url('admin/product_delete.php') ?>" method="POST" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int)$p->id ?>">
                                        <button type="submit" class="btn btn-outline-sm" style="color:var(--error); border-color:#fca5a5;" data-confirm="Are you sure you want to delete '<?= encode($p->name) ?>'? This cannot be undone.">
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div style="display:flex; justify-content:center; gap:6px; margin-top:24px;">
            <?php
            $query_params = $_GET;
            ?>
            <?php if ($page > 1): ?>
                <?php $query_params['page'] = $page - 1; ?>
                <a href="<?= base_url('admin/products.php?' . http_build_query($query_params)) ?>" class="btn btn-outline-sm">&laquo; Previous</a>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <?php $query_params['page'] = $i; ?>
                <a href="<?= base_url('admin/products.php?' . http_build_query($query_params)) ?>" 
                   class="btn <?= $i === $page ? 'btn-primary-sm' : 'btn-outline-sm' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>

            <?php if ($page < $total_pages): ?>
                <?php $query_params['page'] = $page + 1; ?>
                <a href="<?= base_url('admin/products.php?' . http_build_query($query_params)) ?>" class="btn btn-outline-sm">Next &raquo;</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../_foot.php'; ?>
