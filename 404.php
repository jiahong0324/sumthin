<?php
// ============================================================================
// File: 404.php
// Project: sumthin (Tech Gadget Online Store)
// Description: Custom HTTP 404 Not Found error page.
// ============================================================================
http_response_code(404);
require_once __DIR__ . '/_base.php';

$title = '404 Not Found';
require_once __DIR__ . '/_head.php';
?>

<div class="container">
    <div class="error-page-wrapper">
        <div class="error-code">404</div>
        <h1 class="error-title">Page Not Found</h1>
        <p class="error-desc">
            The gadget page or resource you are looking for has been moved, removed, or does not exist.
        </p>
        <div>
            <a href="<?= base_url('index.php') ?>" class="btn btn-primary">Back to Homepage</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/_foot.php'; ?>
