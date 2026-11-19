<?php
// ============================================================================
// File: ajax/search_suggest.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Product Autocomplete & Instant Search Suggestion
// Description: Real-time search suggestions with min 2 chars, max 5 results,
//              prepared LIKE statements, and product links.
// ============================================================================
require_once __DIR__ . '/../_base.php';

// Public GET endpoint
$q = trim(req('q'));

if (mb_strlen($q) < 2) {
    json_response(['ok' => true, 'results' => []]);
}

$stmt = $db->prepare("
    SELECT p.id, p.name, p.price,
           COALESCE(pp.photo_url, 'default_product.png') AS photo_url
    FROM `product` p
    LEFT JOIN `product_photo` pp ON p.id = pp.product_id AND pp.is_primary = 1
    WHERE p.name LIKE ? OR p.description LIKE ?
    ORDER BY p.is_featured DESC, p.id DESC
    LIMIT 5
");

$term = "%{$q}%";
$stmt->execute([$term, $term]);
$rows = $stmt->fetchAll();

$results = [];
foreach ($rows as $r) {
    $results[] = [
        'id'              => (int)$r->id,
        'name'            => $r->name,
        'price'           => (float)$r->price,
        'formatted_price' => 'RM ' . number_format((float)$r->price, 2),
        'photo_url'       => base_url('uploads/products/' . encode($r->photo_url)),
        'link'            => base_url('product.php?id=' . (int)$r->id)
    ];
}

json_response([
    'ok'      => true,
    'query'   => $q,
    'results' => $results
]);
