<?php
// ============================================================================
// File: index.php
// Project: sumthin (Tech Gadget Online Store)
// Style: Shopee-Grade Modern E-Commerce Platform
// Description: Dynamic store homepage displaying Shopee-style promotional carousel,
//              service benefits strip, flash deals with live countdown timer,
//              claimable discount vouchers, category navigation, multi-criteria
//              search & filter toolbar, and full product catalog.
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
$limit     = 12; // 12 products per page for a full e-commerce grid
$offset    = ($page - 1) * $limit;

// Default view flag (no active search / filter query)
$is_default_view = ($q === '' && $cat_slug === '' && $min_price === '' && $max_price === '' && $page === 1);

// Fetch all active categories
$categories = $db->query("SELECT * FROM `category` ORDER BY `id` ASC")->fetchAll();

// ----------------------------------------------------------------------------
// 1. Fetch Shopee Shocking Flash Deals (Default View)
// ----------------------------------------------------------------------------
$flash_deals = [];
if ($is_default_view) {
    try {
        $flash_deals = $db->query("
            SELECT p.*, c.name AS category_name,
                   COALESCE(pp.photo_url, 'default_product.png') AS photo_url,
                   ROUND(p.price * 1.35, 2) AS orig_price,
                   ROUND((1 - (p.price / (p.price * 1.35))) * 100) AS discount_pct,
                   COALESCE(s.units_sold, 18) AS units_sold
            FROM `product` p
            JOIN `category` c ON p.category_id = c.id
            LEFT JOIN `product_photo` pp ON p.id = pp.product_id AND pp.is_primary = 1
            LEFT JOIN (
                SELECT oi.product_id, SUM(oi.quantity) AS units_sold
                FROM `order_item` oi
                JOIN `order` o ON oi.order_id = o.id
                WHERE o.status != 'Cancelled'
                GROUP BY oi.product_id
            ) s ON p.id = s.product_id
            WHERE p.stock > 0
            ORDER BY p.price ASC
            LIMIT 4
        ")->fetchAll();
    } catch (Exception $e) {}
}

// ----------------------------------------------------------------------------
// 2. Fetch Active Platform Discount Vouchers (Default View)
// ----------------------------------------------------------------------------
$active_vouchers = [];
if ($is_default_view) {
    try {
        $active_vouchers = $db->query("
            SELECT * FROM `voucher` 
            WHERE `is_active` = 1 AND `expires_at` >= NOW() AND `used_count` < `usage_limit`
            ORDER BY `discount_value` DESC
            LIMIT 4
        ")->fetchAll();
    } catch (Exception $e) {}
}

// ----------------------------------------------------------------------------
// 3. Build Dynamic Search / Filter SQL Query
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
    'top_sales'  => 'units_sold DESC, p.id ASC',
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

// Fetch paginated products with review scores, units sold, and original price
$product_sql = "
    SELECT p.*, c.name AS category_name, c.slug AS category_slug, b.name AS brand_name, 
           COALESCE(pp.photo_url, 'default_product.png') AS photo_url,
           COALESCE(r.avg_rating, 4.8) AS avg_rating,
           COALESCE(r.rev_count, 1) AS review_count,
           COALESCE(s.units_sold, (p.id * 8 + 15)) AS units_sold,
           ROUND(p.price * 1.25, 2) AS orig_price
    FROM `product` p
    JOIN `category` c ON p.category_id = c.id
    LEFT JOIN `brand` b ON p.brand_id = b.id
    LEFT JOIN `product_photo` pp ON p.id = pp.product_id AND pp.is_primary = 1
    LEFT JOIN (
        SELECT product_id, ROUND(AVG(rating), 1) AS avg_rating, COUNT(*) AS rev_count
        FROM `review`
        GROUP BY product_id
    ) r ON p.id = r.product_id
    LEFT JOIN (
        SELECT oi.product_id, SUM(oi.quantity) AS units_sold
        FROM `order_item` oi
        JOIN `order` o ON oi.order_id = o.id
        WHERE o.status != 'Cancelled'
        GROUP BY oi.product_id
    ) s ON p.id = s.product_id
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

$title = 'Official Online Store & Tech Mall';
require_once __DIR__ . '/_head.php';
?>

<div class="container">

    <?php if ($is_default_view): ?>
        <!-- ==================================================================
             1. SHOPEE HERO PROMOTIONAL BANNER CAROUSEL & SIDE PROMOS
             ================================================================== -->
        <section class="shopee-hero-wrap">
            <!-- Main Hero Carousel -->
            <div class="shopee-carousel" id="shopee-hero-carousel">
                <!-- Slide 1 -->
                <div class="shopee-slide shopee-slide-1 active">
                    <div class="shopee-slide-content">
                        <span class="shopee-slide-badge">⚡ MEGA PAYDAY TECH SALE</span>
                        <h2 class="shopee-slide-title">Up to 50% OFF High-Speed GaN Chargers</h2>
                        <p class="shopee-slide-desc">Ultra-fast multi-port chargers & smart laptop power banks with 2-Year Official Warranty.</p>
                        <a href="<?= base_url('index.php?cat=chargers-cables#catalog') ?>" class="shopee-slide-btn">
                            Shop Deals &rarr;
                        </a>
                    </div>
                </div>

                <!-- Slide 2 -->
                <div class="shopee-slide shopee-slide-2">
                    <div class="shopee-slide-content">
                        <span class="shopee-slide-badge">⌨️ MECHANICAL KEYBOARDS EXPO</span>
                        <h2 class="shopee-slide-title">Custom Aluminum Kits & Hot-Swap Switches</h2>
                        <p class="shopee-slide-desc">Premium gasket-mounted tactile typing experience tailored for developers and gaming enthusiasts.</p>
                        <a href="<?= base_url('index.php?cat=keyboards#catalog') ?>" class="shopee-slide-btn">
                            Explore Keyboards &rarr;
                        </a>
                    </div>
                </div>

                <!-- Slide 3 -->
                <div class="shopee-slide shopee-slide-3">
                    <div class="shopee-slide-content">
                        <span class="shopee-slide-badge">🎧 AUDIOPHILE & ANC ESSENTIALS</span>
                        <h2 class="shopee-slide-title">Immersive Active Noise Cancellation</h2>
                        <p class="shopee-slide-desc">Lossless LDAC wireless audio, 40-hour ultra-long battery life, and crystal-clear voice ENC.</p>
                        <a href="<?= base_url('index.php?cat=audio#catalog') ?>" class="shopee-slide-btn">
                            Discover Audio &rarr;
                        </a>
                    </div>
                </div>

                <!-- Slider Controls -->
                <button type="button" class="shopee-carousel-arrow shopee-carousel-prev" aria-label="Previous slide">&lsaquo;</button>
                <button type="button" class="shopee-carousel-arrow shopee-carousel-next" aria-label="Next slide">&rsaquo;</button>

                <!-- Dots Indicators -->
                <div class="shopee-carousel-dots">
                    <span class="shopee-dot active" data-index="0"></span>
                    <span class="shopee-dot" data-index="1"></span>
                    <span class="shopee-dot" data-index="2"></span>
                </div>
            </div>

            <!-- Right: Shopee-Style Side Mini Promos -->
            <div class="shopee-side-promos">
                <a href="#vouchers" class="shopee-promo-card shopee-promo-1">
                    <span class="shopee-promo-badge">🎟️ Daily Vouchers</span>
                    <div class="shopee-promo-title">Collect Platform Vouchers</div>
                    <div class="shopee-promo-sub">Up to RM80 OFF on your tech upgrades!</div>
                </a>
                <a href="#catalog" class="shopee-promo-card shopee-promo-2">
                    <span class="shopee-promo-badge">🛡️ Official Mall</span>
                    <div class="shopee-promo-title">100% Authentic Guarantee</div>
                    <div class="shopee-promo-sub">15 Days Free Return &bull; Verified Tech</div>
                </a>
            </div>
        </section>

        <!-- ==================================================================
             2. SHOPEE 4-POINT TRUST & BENEFITS STRIP
             ================================================================== -->
        <section class="shopee-trust-strip">
            <div class="shopee-trust-item">
                <span class="trust-icon">🚚</span>
                <div class="trust-text">
                    <div class="trust-title">Free Shipping</div>
                    <div class="trust-desc">Min. spend RM15 across Malaysia</div>
                </div>
            </div>
            <div class="shopee-trust-item">
                <span class="trust-icon">🛡️</span>
                <div class="trust-text">
                    <div class="trust-title">100% Authentic Tech</div>
                    <div class="trust-desc">Official 2-Year Manufacturer Warranty</div>
                </div>
            </div>
            <div class="shopee-trust-item">
                <span class="trust-icon">🔄</span>
                <div class="trust-text">
                    <div class="trust-title">15 Days Free Return</div>
                    <div class="trust-desc">Zero-hassle refund & satisfaction guarantee</div>
                </div>
            </div>
            <div class="shopee-trust-item">
                <span class="trust-icon">⚡</span>
                <div class="trust-text">
                    <div class="trust-title">Same-Day Dispatch</div>
                    <div class="trust-desc">Orders placed before 2 PM ship today</div>
                </div>
            </div>
        </section>

        <!-- ==================================================================
             3. SHOPEE FLASH DEALS / SHOCKING SALE SECTION
             ================================================================== -->
        <?php if (!empty($flash_deals)): ?>
            <section class="shopee-flash-section">
                <div class="shopee-flash-header">
                    <div class="flash-title-wrap">
                        <span class="flash-title"><span>⚡</span> FLASH SALE</span>
                        <div class="flash-countdown">
                            <span>Ends in</span>
                            <span class="countdown-box" id="cd-hours">02</span>
                            <span>:</span>
                            <span class="countdown-box" id="cd-minutes">45</span>
                            <span>:</span>
                            <span class="countdown-box" id="cd-seconds">18</span>
                        </div>
                    </div>
                    <a href="#catalog" class="btn btn-outline-sm">View All &rarr;</a>
                </div>

                <div class="shopee-flash-grid">
                    <?php foreach ($flash_deals as $fd): ?>
                        <a href="<?= base_url('product.php?id=' . (int)$fd->id) ?>" class="shopee-flash-card">
                            <span class="flash-badge">-<?= (int)$fd->discount_pct ?>%</span>
                            <div class="flash-img-wrap">
                                <img src="<?= base_url('uploads/products/' . encode($fd->photo_url)) ?>" 
                                     alt="<?= encode($fd->name) ?>" 
                                     loading="lazy">
                            </div>
                            <div class="flash-name"><?= encode($fd->name) ?></div>
                            <div class="flash-price-wrap">
                                <span class="flash-price">RM <?= number_format((float)$fd->price, 2) ?></span>
                                <span class="flash-orig-price">RM <?= number_format((float)$fd->orig_price, 2) ?></span>
                            </div>
                            <div class="flash-stock-track">
                                <div class="flash-stock-fill" style="width: <?= min(90, (int)$fd->units_sold * 4 + 30) ?>%;"></div>
                                <span class="flash-stock-text">🔥 <?= (int)$fd->units_sold ?> SOLD</span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- ==================================================================
             4. SHOPEE CLAIMABLE DISCOUNT VOUCHERS SECTION
             ================================================================== -->
        <?php if (!empty($active_vouchers)): ?>
            <section class="shopee-voucher-section" id="vouchers">
                <div class="shopee-voucher-header">
                    <h2 class="voucher-section-title">
                        <span>🎟️</span> Platform Vouchers &bull; Claim & Save
                    </h2>
                    <span style="font-size:12px; color:var(--gray);">One-click claim &bull; Auto-applied at checkout</span>
                </div>
                <div class="shopee-voucher-grid">
                    <?php foreach ($active_vouchers as $v): ?>
                        <div class="voucher-ticket">
                            <div class="voucher-left">
                                <div class="voucher-amount">
                                    <?= ($v->discount_type === 'percentage') ? ((int)$v->discount_value . '% OFF') : ('RM ' . (int)$v->discount_value . ' OFF') ?>
                                </div>
                                <div class="voucher-min-spend">Min. Spend RM <?= number_format((float)$v->min_spend, 2) ?></div>
                                <span class="voucher-code-badge"><?= encode($v->code) ?></span>
                            </div>
                            <div class="voucher-right">
                                <button type="button" class="btn-claim-voucher" data-code="<?= encode($v->code) ?>">
                                    Claim
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    <?php endif; ?>

    <!-- ==================================================================
         5. SHOPEE CATEGORY TABS & HORIZONTAL SCROLL LIST
         ================================================================== -->
    <div class="mb-24">
        <div class="category-scroll-list">
            <a href="<?= base_url('index.php' . ($q !== '' ? '?q=' . urlencode($q) : '') . '#catalog') ?>" 
               class="btn <?= empty($cat_slug) ? 'btn-primary-sm' : 'btn-outline-sm' ?>">
               🌟 All Categories
            </a>
            <?php foreach ($categories as $cat): ?>
                <?php
                $cat_link_params = ['cat' => $cat->slug];
                if ($q !== '') $cat_link_params['q'] = $q;
                if ($sort !== 'featured') $cat_link_params['sort'] = $sort;

                $cat_icon = match ($cat->slug) {
                    'power-banks'      => '🔋',
                    'keyboards'        => '⌨️',
                    'mice'             => '🖱️',
                    'audio'            => '🎧',
                    'chargers-cables'  => '⚡',
                    default            => '📦'
                };
                ?>
                <a href="<?= base_url('index.php?' . http_build_query($cat_link_params) . '#catalog') ?>" 
                   class="btn <?= ($cat_slug === $cat->slug) ? 'btn-primary-sm' : 'btn-outline-sm' ?>">
                   <?= $cat_icon ?> <?= encode($cat->name) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ==================================================================
         6. SHOPEE MULTI-CRITERIA FILTER & SORT CONTROLS
         ================================================================== -->
    <section id="catalog" class="card-panel catalog-filter-panel">
        <form action="<?= base_url('index.php') ?>#catalog" method="GET" class="catalog-filter-form">
            <!-- Keyword Search Input -->
            <div class="filter-group-search">
                <label for="q" class="form-label filter-label">Search Storefront</label>
                <?= html_text('q', $q, [
                    'placeholder' => 'Search by model, GaN, wireless, wattage, switches...',
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
                <label for="sort" class="form-label filter-label">Sort Order</label>
                <select name="sort" id="sort" class="form-select">
                    <option value="featured" <?= ($sort === 'featured') ? 'selected' : '' ?>>Featured First</option>
                    <option value="top_sales" <?= ($sort === 'top_sales') ? 'selected' : '' ?>>Top Sales</option>
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

    <!-- Shopee-Style Sort Navigation Toolbar -->
    <div class="shopee-sort-toolbar">
        <span class="shopee-sort-label">Sort By:</span>
        <?php
        $base_sort_params = [];
        if ($q !== '') $base_sort_params['q'] = $q;
        if ($cat_slug !== '') $base_sort_params['cat'] = $cat_slug;
        if ($min_price !== '') $base_sort_params['min_price'] = $min_price;
        if ($max_price !== '') $base_sort_params['max_price'] = $max_price;

        $sort_options = [
            'featured'   => 'Featured',
            'top_sales'  => 'Top Sales',
            'newest'     => 'Latest',
            'price_asc'  => 'Price: Low to High',
            'price_desc' => 'Price: High to Low',
        ];
        foreach ($sort_options as $key => $lbl):
            $p_sort = array_merge($base_sort_params, ['sort' => $key]);
        ?>
            <a href="<?= base_url('index.php?' . http_build_query($p_sort) . '#catalog') ?>" 
               class="shopee-sort-btn <?= ($sort === $key) ? 'active' : '' ?>">
                <?= $lbl ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Catalog Header / Active Filter Summary -->
    <div class="section-title-wrap mb-20">
        <div>
            <h2 class="section-title">
                <?= $current_category ? encode($current_category->name) : 'Daily Discover & All Products' ?>
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

    <!-- ==================================================================
         7. SHOPEE-STYLE PRODUCT GRID
         ================================================================== -->
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
                    <!-- Shopee Mall Badge -->
                    <span class="shopee-card-badge-mall">Mall</span>

                    <!-- Shopee Discount Tag -->
                    <span class="shopee-card-discount-tag">-20%</span>

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

                    <!-- Shopee Review Stars & Sold Count -->
                    <div class="shopee-card-rating">
                        <span class="shopee-card-stars">★★★★★</span>
                        <span><?= number_format((float)$p->avg_rating, 1) ?></span>
                        <span class="shopee-card-sold"><?= (int)$p->units_sold ?> sold</span>
                    </div>

                    <!-- Free Shipping Pill -->
                    <div>
                        <span class="shopee-card-free-ship">🚚 Free Shipping</span>
                    </div>

                    <!-- Price & Actions Footer -->
                    <div class="product-footer">
                        <div>
                            <div class="shopee-card-price-row">
                                <span class="product-price">RM <?= number_format((float)$p->price, 2) ?></span>
                                <span class="shopee-card-orig-price">RM <?= number_format((float)$p->orig_price, 2) ?></span>
                            </div>
                            <div class="product-stock mt-2">
                                <?php if ($p->stock > $p->low_stock_threshold): ?>
                                    <span class="stock-status-in">In Stock (<?= (int)$p->stock ?>)</span>
                                <?php elseif ($p->stock > 0): ?>
                                    <span class="stock-status-low">Low Stock (<?= (int)$p->stock ?> left)</span>
                                <?php else: ?>
                                    <span class="stock-status-out">Out of Stock</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="product-card-actions">
                            <form action="<?= base_url('wishlist.php') ?>" method="POST" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="product_id" value="<?= (int)$p->id ?>">
                                <button type="submit" class="btn btn-outline-sm btn-card-action" title="Save to Wishlist" style="padding:6px 10px;">
                                    <span class="card-heart-icon">♡</span>
                                </button>
                            </form>
                            <a href="<?= base_url('product.php?id=' . (int)$p->id) ?>" class="btn btn-outline-sm btn-card-action">
                                Details
                            </a>
                            <?php if ($p->stock > 0): ?>
                                <form action="<?= base_url('cart_action.php') ?>" method="POST" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="add">
                                    <input type="hidden" name="product_id" value="<?= (int)$p->id ?>">
                                    <input type="hidden" name="quantity" value="1">
                                    <button type="submit" class="btn btn-primary-sm btn-card-action" style="background:#ea580c; border-color:#ea580c;">
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
