<?php
// ============================================================================
// File: 403.php
// Project: sumthin (Tech Gadget Online Store)
// Description: Custom HTTP 403 Forbidden error page for unauthorized role access.
// ============================================================================
http_response_code(403);
require_once __DIR__ . '/_base.php';

$title = '403 Forbidden';
require_once __DIR__ . '/_head.php';
?>

<div class="container">
    <div class="error-page-wrapper">
        <div class="error-code text-danger">403</div>
        <h1 class="error-title">Access Denied</h1>
        <p class="error-desc">
            You do not have administrative permissions to view this resource. 
            If you believe this is in error, please contact the site administrator.
        </p>
        <div class="flex-center gap-12">
            <a href="<?= base_url('index.php') ?>" class="btn btn-primary">Return to Shop</a>
            <?php if (is_logged_in()): ?>
                <a href="<?= base_url('logout.php') ?>" class="btn btn-outline-sm">Switch Account</a>
            <?php else: ?>
                <a href="<?= base_url('login.php') ?>" class="btn btn-outline-sm">Sign In</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/_foot.php'; ?>
