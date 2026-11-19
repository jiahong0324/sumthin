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
    $where_clauses[] = "p.stock <= p.low_stock_threshold";
} elseif ($stock_filter === 'in') {
    $where_clauses[] = "p.stock > p.low_stock_threshold";
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
            <h1 class="page-title">Product Maintenance</h1>
            <p class="page-subtitle mt-4">
                Manage sumthin tech gadgets, inventory levels, pricing, and high-res photography.
            </p>
        </div>
        <div class="d-flex gap-10">
            <a href="<?= base_url('admin/product_batch.php') ?>" class="btn btn-outline-sm btn-h40 d-inline-flex items-center gap-6">
                ⚡ Batch Operations
            </a>
            <a href="<?= base_url('admin/product_add.php') ?>" class="btn btn-primary btn-h40 d-inline-flex items-center">
                + Add New Product
            </a>
        </div>
    </div>

    <!-- Search, Category Filter, and Sorting Controls -->
    <div class="filter-panel p-20 mb-24">
        <form action="<?= base_url('admin/products.php') ?>" method="GET" class="filter-form">
            <!-- Keyword Search -->
            <div class="flex-2 min-w-200">
                <label for="q" class="form-label">Search Gadgets</label>
                <input type="text" name="q" id="q" value="<?= encode($q) ?>" placeholder="Search by model, name or keyword..." class="form-control">
            </div>

            <!-- Category Filter -->
            <div class="flex-1 min-w-160">
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
            <div class="flex-1 min-w-150">
                <label for="stock_filter" class="form-label">Inventory Level</label>
                <select name="stock_filter" id="stock_filter" class="form-select">
                    <option value="">All Stock</option>
                    <option value="in" <?= $stock_filter === 'in' ? 'selected' : '' ?>>In Stock (&gt; threshold)</option>
                    <option value="low" <?= $stock_filter === 'low' ? 'selected' : '' ?>>Low Stock (&le; threshold)</option>
                    <option value="out" <?= $stock_filter === 'out' ? 'selected' : '' ?>>Out of Stock (0)</option>
                </select>
            </div>

            <!-- Sort By -->
            <div class="flex-1 min-w-150">
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
            <div class="d-flex gap-8">
                <button type="submit" class="btn btn-primary-sm btn-filter-h">Apply Filter</button>
                <a href="<?= base_url('admin/products.php') ?>" class="btn btn-outline-sm btn-filter-h d-inline-flex items-center">Reset</a>
            </div>
        </form>
    </div>

    <!-- Results Count Bar -->
    <div class="flex-between mb-14 fs-14 text-muted">
        <span>Showing <strong><?= count($products) ?></strong> of <strong><?= $total_rows ?></strong> matching product(s)</span>
        <span>Page <strong><?= $page ?></strong> of <strong><?= $total_pages ?></strong></span>
    </div>

    <!-- Batch Actions Form -->
    <form action="<?= base_url('admin/product_batch.php') ?>" method="POST" id="batch-form">
        <?= csrf_field() ?>

        <!-- Floating / Sticky Batch Toolbar -->
        <div id="batch-toolbar" class="batch-toolbar d-none">
            <div class="batch-toolbar-count">
                <span id="batch-selected-count">0</span> product(s) selected
            </div>
            <div class="d-flex gap-10">
                <button type="submit" name="batch_action" value="update_price" class="btn btn-outline-sm bg-white">
                    Batch Update Price
                </button>
                <button type="submit" name="batch_action" value="delete" class="btn btn-outline-sm bg-white btn-outline-danger" data-confirm="Are you sure you want to delete all selected products? Products with order history will be skipped.">
                    Delete Selected
                </button>
            </div>
        </div>

        <!-- Products Table -->
        <div class="table-responsive">
            <table class="table-custom">
                <thead>
                    <tr>
                        <th class="w-40 text-center">
                            <input type="checkbox" id="check-all-products" title="Select All">
                        </th>
                        <th class="w-60">Photo</th>
                        <th>Product Model & Specs</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Stock Level</th>
                        <th>Featured</th>
                        <th class="text-right min-w-170">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="8" class="table-empty-cell">
                                No products found matching your search criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($products as $p): ?>
                            <tr>
                                <td class="text-center">
                                    <input type="checkbox" name="product_ids[]" value="<?= (int)$p->id ?>" class="product-checkbox">
                                </td>
                                <!-- Thumbnail -->
                                <td>
                                    <div class="product-item-thumb">
                                        <img src="<?= base_url('uploads/products/' . encode($p->photo_url)) ?>" 
                                             alt="<?= encode($p->name) ?>" 
                                             class="order-thumb-img">
                                    </div>
                                </td>

                                <!-- Product Name & Description Preview -->
                                <td>
                                    <div class="fw-700 text-dark mb-3">
                                        <a href="<?= base_url('admin/product_detail.php?id=' . (int)$p->id) ?>" class="text-decoration-none color-inherit">
                                            <?= encode($p->name) ?>
                                        </a>
                                    </div>
                                    <div class="text-truncate-desc fs-12 text-muted">
                                        <?= encode($p->description) ?>
                                    </div>
                                </td>

                                <!-- Category & Brand -->
                                <td>
                                    <span class="role-pill pill-member fs-11">
                                        <?= encode($p->category_name) ?>
                                    </span>
                                    <?php if ($p->brand_name): ?>
                                        <div class="fs-11 text-muted mt-3"><?= encode($p->brand_name) ?></div>
                                    <?php endif; ?>
                                </td>

                                <!-- Price -->
                                <td class="fw-700 text-dark">
                                    RM <?= number_format((float)$p->price, 2) ?>
                                </td>

                                <!-- Stock -->
                                <td>
                                    <?php if ($p->stock == 0): ?>
                                        <span class="role-pill badge-stock-out">
                                            Out of Stock (0)
                                        </span>
                                    <?php elseif ($p->stock <= $p->low_stock_threshold): ?>
                                        <span class="role-pill badge-stock-low">
                                            Low Stock (<?= (int)$p->stock ?>)
                                        </span>
                                    <?php else: ?>
                                        <span class="role-pill badge-stock-healthy">
                                            In Stock (<?= (int)$p->stock ?>)
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Featured Badge -->
                                <td>
                                    <?= $p->is_featured ? '<span class="badge-featured">★ Featured</span>' : '<span class="text-muted fs-12">Standard</span>' ?>
                                </td>

                                <!-- Action Buttons -->
                                <td class="text-right">
                                    <div class="d-inline-flex gap-6">
                                        <a href="<?= base_url('admin/product_detail.php?id=' . (int)$p->id) ?>" class="btn btn-outline-sm" title="View Details">
                                            View
                                        </a>
                                        <a href="<?= base_url('admin/product_edit.php?id=' . (int)$p->id) ?>" class="btn btn-outline-sm" title="Edit Product">
                                            Edit
                                        </a>
                                        <button type="submit" formaction="<?= base_url('admin/product_delete.php') ?>" name="id" value="<?= (int)$p->id ?>" class="btn btn-outline-sm btn-outline-danger" data-confirm="Are you sure you want to delete '<?= encode($p->name) ?>'? This cannot be undone.">
                                            Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="pagination-wrapper flex-center gap-6 mt-24">
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
