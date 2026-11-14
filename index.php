<?php
// ============================================================================
// File: index.php
// Project: sumthin (Tech Gadget Online Store)
// Description: Dynamic store homepage displaying sumthin-branded tech gadgets,
//              top-selling products leaderboard, multi-criteria keyword search,
//              category & price filtering, multi-order sorting, and pagination.
// ============================================================================
require_once __DIR__ . '/_base.php';

// ----------------------------------------------------------------------------
// Request Parameters: Search, Filter, Sort, Pagination
// ----------------------------------------------------------------------------
$q         = req('q');
$cat_slug  = req('cat');
$min_price = req('min_price');
$max_price = req('max_price');
$sort      = req('sort', 'featured');
$page      = max(1, (int)req('page', 1));
$limit     = 9; // 9 products per page (clean 3x3 responsive grid)
$offset    = ($page - 1) * $limit;

// Fetch all active categories for filter tabs & dropdown
$categories = $db->query("SELECT * FROM `category` ORDER BY `id` ASC")->fetchAll();

// ----------------------------------------------------------------------------
// Build Dynamic Search / Filter SQL Query
// ----------------------------------------------------------------------------
$where_clauses = ["1=1"];
$params        = [];

// Keyword search across product name, model, and description
if ($q !== '') {
    $where_clauses[] = "(p.name LIKE ? OR p.description LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
}

// Category filter
$current_category = null;
if ($cat_slug !== '') {
    $where_clauses[] = "c.slug = ?";
    $params[] = $cat_slug;

    foreach ($categories as $c) {
        if ($c->slug === $cat_slug) {
            $current_category = $c;
            break;
        }
    }
}

// Price range filters
if ($min_price !== '' && is_numeric($min_price) && (float)$min_price >= 0) {
    $where_clauses[] = "p.price >= ?";
    $params[] = (float)$min_price;
}

if ($max_price !== '' && is_numeric($max_price) && (float)$max_price > 0) {
    $where_clauses[] = "p.price <= ?";
    $params[] = (float)$max_price;
}

$where_sql = implode(' AND ', $where_clauses);

// Dynamic sorting
$order_sql = match ($sort) {
    'price_asc'  => 'p.price ASC, p.id ASC',
    'price_desc' => 'p.price DESC, p.id ASC',
    'name_asc'   => 'p.name ASC',
    'name_desc'  => 'p.name DESC',
    'newest'     => 'p.id DESC',
    default      => 'p.is_featured DESC, p.id ASC',
};

// Count total matching products for pagination
$count_stmt = $db->prepare("
    SELECT COUNT(*) 
    FROM `product` p 
    JOIN `category` c ON p.category_id = c.id 
    WHERE $where_sql
");
$count_stmt->execute($params);
$total_rows  = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $limit));

// Fetch paginated products
$product_sql = "
    SELECT p.*, c.name AS category_name, c.slug AS category_slug, b.name AS brand_name, 
           COALESCE(pp.photo_url, 'default_product.png') AS photo_url
    FROM `product` p
    JOIN `category` c ON p.category_id = c.id
    LEFT JOIN `brand` b ON p.brand_id = b.id
    LEFT JOIN `product_photo` pp ON p.id = pp.product_id AND pp.is_primary = 1
    WHERE $where_sql
    ORDER BY $order_sql
    LIMIT $limit OFFSET $offset
";
$stmt = $db->prepare($product_sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Helper to retain active filters in pagination links
function catalog_page_url(int $target_page): string {
    global $q, $cat_slug, $min_price, $max_price, $sort;
    $params = ['page' => $target_page];
    if ($q !== '') $params['q'] = $q;
    if ($cat_slug !== '') $params['cat'] = $cat_slug;
    if ($min_price !== '') $params['min_price'] = $min_price;
    if ($max_price !== '') $params['max_price'] = $max_price;
    if ($sort !== '' && $sort !== 'featured') $params['sort'] = $sort;
    return base_url('index.php?' . http_build_query($params) . '#catalog');
}

$title = 'Tech Gadgets & Everyday Essentials';
require_once __DIR__ . '/_head.php';
?>

<div class="container">
    <!-- Category Filter Tabs -->
    <div class="mb-24">
        <div class="category-scroll-list">
            <a href="<?= base_url('index.php' . ($q !== '' ? '?q=' . urlencode($q) : '') . '#catalog') ?>" 
               class="btn <?= empty($cat_slug) ? 'btn-primary-sm' : 'btn-outline-sm' ?>">
               All Categories
            </a>
            <?php foreach ($categories as $cat): ?>
                <?php
                $cat_link_params = ['cat' => $cat->slug];
                if ($q !== '') $cat_link_params['q'] = $q;
                if ($sort !== 'featured') $cat_link_params['sort'] = $sort;
                ?>
                <a href="<?= base_url('index.php?' . http_build_query($cat_link_params) . '#catalog') ?>" 
                   class="btn <?= ($cat_slug === $cat->slug) ? 'btn-primary-sm' : 'btn-outline-sm' ?>">
                   <?= encode($cat->name) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Multi-Criteria Search & Filter Controls Panel (Basic Searching + Combined Filter/Sort) -->
    <section id="catalog" class="card-panel catalog-filter-panel">
        <form action="<?= base_url('index.php') ?>#catalog" method="GET" class="catalog-filter-form">
            <!-- Keyword Search Input -->
            <div class="filter-group-search">
                <label for="q" class="form-label filter-label">Search sumthin Gadgets</label>
                <?= html_text('q', $q, [
                    'placeholder' => 'Search by model, GaN, wireless, wattage, optical...',
                    'autocomplete' => 'off'
                ]) ?>
            </div>

            <!-- Category Selector -->
            <div class="filter-group-category">
                <label for="cat" class="form-label filter-label">Category</label>
                <select name="cat" id="cat" class="form-select">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= encode($c->slug) ?>" <?= ($cat_slug === $c->slug) ? 'selected' : '' ?>>
                            <?= encode($c->name) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Min Price Filter -->
            <div class="filter-group-price">
                <label for="min_price" class="form-label filter-label">Min RM</label>
                <?= html_text('min_price', $min_price, ['placeholder' => '0']) ?>
            </div>

            <!-- Max Price Filter -->
            <div class="filter-group-price">
                <label for="max_price" class="form-label filter-label">Max RM</label>
                <?= html_text('max_price', $max_price, ['placeholder' => '1500']) ?>
            </div>

            <!-- Sorting Dropdown -->
            <div class="filter-group-sort">
                <label for="sort" class="form-label filter-label">Sort By</label>
                <select name="sort" id="sort" class="form-select">
                    <option value="featured" <?= ($sort === 'featured') ? 'selected' : '' ?>>Featured First</option>
                    <option value="price_asc" <?= ($sort === 'price_asc') ? 'selected' : '' ?>>Price: Low to High</option>
                    <option value="price_desc" <?= ($sort === 'price_desc') ? 'selected' : '' ?>>Price: High to Low</option>
                    <option value="name_asc" <?= ($sort === 'name_asc') ? 'selected' : '' ?>>Name: A to Z</option>
                    <option value="newest" <?= ($sort === 'newest') ? 'selected' : '' ?>>Newest Arrivals</option>
                </select>
            </div>

            <!-- Buttons -->
            <div class="filter-group-actions">
                <button type="submit" class="btn btn-primary-sm btn-filter-submit">
                    Search / Filter
                </button>
                <a href="<?= base_url('index.php#catalog') ?>" class="btn btn-outline-sm btn-filter-reset">
                    Reset
                </a>
            </div>
        </form>
    </section>

    <!-- Catalog Header / Active Filter Summary -->
    <div class="section-title-wrap mb-20">
        <div>
            <h2 class="section-title">
                <?= $current_category ? encode($current_category->name) : 'Product Catalog' ?>
                <?php if ($q !== ''): ?>
                    <span class="catalog-match-query"> &mdash; matching "<em><?= encode($q) ?></em>"</span>
                <?php endif; ?>
            </h2>
            <?php if ($current_category && $current_category->description): ?>
                <p class="catalog-match-desc">
                    <?= encode($current_category->description) ?>
                </p>
            <?php endif; ?>
        </div>
        <span class="catalog-item-count">
            Found <strong><?= $total_rows ?></strong> product(s) &bull; Page <?= $page ?> of <?= $total_pages ?>
        </span>
    </div>

    <!-- Product Catalog Grid -->
    <?php if (empty($products)): ?>
        <div class="catalog-empty-card">
            <div class="empty-state-icon">🔍</div>
            <h3 class="empty-state-title">No matching gadgets found</h3>
            <p class="empty-state-desc">Try refining your keywords or clearing your price filter range.</p>
            <a href="<?= base_url('index.php#catalog') ?>" class="btn btn-primary-sm">Reset All Filters</a>
        </div>
    <?php else: ?>
        <div class="product-grid mb-36">
            <?php foreach ($products as $p): ?>
                <article class="product-card">
                    <!-- Product Photo Link -->
                    <a href="<?= base_url('product.php?id=' . (int)$p->id) ?>" class="product-card-image-wrap product-card-link">
                        <img src="<?= base_url('uploads/products/' . encode($p->photo_url)) ?>" 
                             alt="<?= encode($p->name) ?>" 
                             class="product-card-img" 
                             loading="lazy">
                    </a>

                    <!-- Brand & Category Tag -->
                    <div class="product-category-tag">
                        <?= encode($p->brand_name ?? 'sumthin') ?> &bull; <?= encode($p->category_name) ?>
                    </div>

                    <!-- Product Title -->
                    <h3 class="product-name">
                        <a href="<?= base_url('product.php?id=' . (int)$p->id) ?>" class="product-title-link">
                            <?= encode($p->name) ?>
                        </a>
                    </h3>

                    <!-- Description -->
                    <p class="product-desc"><?= encode($p->description) ?></p>

                    <!-- Price & Actions Footer -->
                    <div class="product-footer">
                        <div>
                            <span class="product-price">RM <?= number_format((float)$p->price, 2) ?></span>
                            <div class="product-stock mt-2">
                                <?php if ($p->stock > 15): ?>
                                    <span class="stock-status-in">In Stock (<?= (int)$p->stock ?>)</span>
                                <?php elseif ($p->stock > 0): ?>
                                    <span class="stock-status-low">Low Stock (<?= (int)$p->stock ?> left)</span>
                                <?php else: ?>
                                    <span class="stock-status-out">Out of Stock</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="product-card-actions">
                            <a href="<?= base_url('product.php?id=' . (int)$p->id) ?>" class="btn btn-outline-sm btn-card-action">
                                Details
                            </a>
                            <?php if ($p->stock > 0): ?>
                                <form action="<?= base_url('cart_action.php') ?>" method="POST" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="add">
                                    <input type="hidden" name="product_id" value="<?= (int)$p->id ?>">
                                    <input type="hidden" name="quantity" value="1">
                                    <button type="submit" class="btn btn-primary-sm btn-card-action">
                                        + Cart
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <!-- Pagination Controls (Combined Filtering, Sorting & Paging) -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination-wrapper">
                <?php if ($page > 1): ?>
                    <a href="<?= catalog_page_url($page - 1) ?>" class="btn btn-outline-sm">&larr; Previous</a>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i === $page): ?>
                        <span class="btn btn-primary-sm pagination-active"><?= $i ?></span>
                    <?php elseif ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                        <a href="<?= catalog_page_url($i) ?>" class="btn btn-outline-sm pagination-link"><?= $i ?></a>
                    <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                        <span class="pagination-ellipsis">...</span>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="<?= catalog_page_url($page + 1) ?>" class="btn btn-outline-sm">Next &rarr;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/_foot.php'; ?>
