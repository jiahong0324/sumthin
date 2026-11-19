<?php
// ============================================================================
// File: _head.php
// Project: sumthin (Tech Gadget Online Store)
// Description: Shared layout header, responsive navigation, flash messaging.
// ============================================================================
$current_page = basename($_SERVER['PHP_SELF']);
$store_name   = setting('store_name', 'sumthin');
$page_title   = isset($title) ? encode($title) . ' | ' . encode($store_name) : encode($store_name) . ' | Tech Gadgets & Daily Essentials';
$flash        = get_flash();
$user         = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <title><?= $page_title ?></title>
    <!-- Prevent theme flickering on reload -->
    <script>
        if (localStorage.getItem('sumthin_theme') === 'dark') {
            document.documentElement.classList.add('dark-theme');
        }
    </script>
    <!-- Modern Minimal Tech handwritten CSS -->
    <link rel="stylesheet" href="<?= base_url('css/app.css') ?>">
</head>
<body>
    <!-- Top announcement bar (Configured dynamically in Settings) -->
    <?php if (setting('announcement_enabled', '1') === '1' && setting('announcement_bar') !== ''): ?>
        <aside class="announcement-bar">
            <div class="container announcement-inner">
                <span><?= encode(setting('announcement_bar')) ?></span>
                <span class="announcement-tag"><?= encode(setting('store_tagline', 'BMIT2013 Project')) ?></span>
            </div>
        </aside>
    <?php endif; ?>

    <!-- Main Navigation Header -->
    <header class="site-header">
        <div class="container header-inner">
            <!-- Brand Logo -->
            <a href="<?= base_url('index.php') ?>" class="brand-logo">
                <span class="brand-badge"><?= strtoupper(substr($store_name, 0, 1)) ?></span>
                <span class="brand-text"><?= encode($store_name) ?><span class="brand-dot">.</span></span>
            </a>

            <!-- Navigation Links -->
            <nav class="nav-links">
                <a href="<?= base_url('index.php') ?>" class="nav-link <?= ($current_page === 'index.php' && !str_contains($_SERVER['REQUEST_URI'], '/admin/')) ? 'active' : '' ?>">Shop</a>
                
                <?php if (is_admin()): ?>
                    <!-- Admin specific navigation -->
                    <?php
                    $nav_low_stock = 0;
                    $nav_admin_unread_chat = 0;
                    try {
                        $nav_low_stock = (int)$db->query("SELECT COUNT(*) FROM `product` WHERE `stock` <= `low_stock_threshold`")->fetchColumn();
                        $nav_admin_unread_chat = (int)$db->query("SELECT COUNT(*) FROM `chat_message` WHERE `sender_role` = 'Member' AND `is_read` = 0")->fetchColumn();
                    } catch (Exception $e) {}
                    ?>
                    <span class="nav-badge admin-badge">Admin</span>
                    <a href="<?= base_url('admin/index.php') ?>" class="nav-link <?= ($current_page === 'index.php' && str_contains($_SERVER['REQUEST_URI'], '/admin/')) ? 'active' : '' ?>">Dashboard</a>
                    <a href="<?= base_url('admin/products.php') ?>" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], '/admin/product') ? 'active' : '' ?>">
                        Products<?php if ($nav_low_stock > 0): ?><span class="badge-low-stock" title="<?= $nav_low_stock ?> product(s) low on inventory"><?= $nav_low_stock ?></span><?php endif; ?>
                    </a>
                    <a href="<?= base_url('admin/categories.php') ?>" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], '/admin/categor') ? 'active' : '' ?>">Categories</a>
                    <a href="<?= base_url('admin/vouchers.php') ?>" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], '/admin/voucher') ? 'active' : '' ?>">Vouchers</a>
                    <a href="<?= base_url('admin/members.php') ?>" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], '/admin/member') ? 'active' : '' ?>">Members</a>
                    <a href="<?= base_url('admin/admins.php') ?>" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], '/admin/admin') ? 'active' : '' ?>">Admins</a>
                    <a href="<?= base_url('admin/orders.php') ?>" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], '/admin/order') ? 'active' : '' ?>">Orders</a>
                    <a href="<?= base_url('admin/email_send.php') ?>" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], '/admin/email') ? 'active' : '' ?>">Email</a>
                    <a href="<?= base_url('admin/chat.php') ?>" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], '/admin/chat') ? 'active' : '' ?>">
                        Chat<span class="badge-unread-chat" id="admin-chat-badge" style="<?= $nav_admin_unread_chat > 0 ? '' : 'display:none;' ?>"><?= $nav_admin_unread_chat ?></span>
                    </a>
                    <a href="<?= base_url('admin/settings.php') ?>" class="nav-link <?= str_contains($_SERVER['REQUEST_URI'], '/admin/setting') ? 'active' : '' ?>">Settings</a>
                <?php elseif (is_member()): ?>
                    <!-- Member specific navigation -->
                    <?php
                    $nav_member_unread_chat = 0;
                    try {
                        $c_stmt = $db->prepare("SELECT COUNT(*) FROM `chat_message` WHERE `member_id` = ? AND `sender_role` = 'Admin' AND `is_read` = 0");
                        $c_stmt->execute([$user->id]);
                        $nav_member_unread_chat = (int)$c_stmt->fetchColumn();
                    } catch (Exception $e) {}
                    ?>
                    <a href="<?= base_url('orders.php') ?>" class="nav-link <?= $current_page === 'orders.php' ? 'active' : '' ?>">My Orders</a>
                    <a href="<?= base_url('wishlist.php') ?>" class="nav-link <?= $current_page === 'wishlist.php' ? 'active' : '' ?>">Wishlist</a>
                    <a href="<?= base_url('chat.php') ?>" class="nav-link <?= $current_page === 'chat.php' ? 'active' : '' ?>" id="member-chat-nav">
                        Support Chat<span class="badge-unread-chat" id="member-chat-badge" style="<?= $nav_member_unread_chat > 0 ? '' : 'display:none;' ?>"><?= $nav_member_unread_chat ?></span>
                    </a>
                <?php endif; ?>
            </nav>

            <!-- User Auth & Action Bar -->
            <div class="nav-actions">
                <!-- Theme Switcher (Light / Dark mode toggle) -->
                <button type="button" id="theme-toggle" class="btn-theme-toggle" title="Toggle Light/Dark Theme" aria-label="Toggle theme">🌙</button>

                <?php if (is_logged_in()): ?>
                    <?php if (is_member()): ?>
                        <!-- Shopping Cart Link with Counter -->
                        <a href="<?= base_url('cart.php') ?>" class="btn btn-outline-sm nav-cart-btn <?= $current_page === 'cart.php' ? 'active' : '' ?>">
                            <span>🛒 Cart</span>
                            <?php $cc = cart_count(); if ($cc > 0): ?>
                                <span class="cart-badge"><?= $cc ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>

                    <div class="user-menu-wrapper">
                        <a href="<?= base_url('profile.php') ?>" class="nav-link user-profile-link" title="Account Profile & Settings">
                            <span>Hi, <strong><?= encode($user->name) ?></strong></span>
                        </a>
                        <span class="role-pill <?= is_admin() ? 'pill-admin' : 'pill-member' ?>">
                            <?= encode($user->role) ?>
                        </span>
                        <a href="<?= base_url('logout.php') ?>" class="btn btn-outline-sm btn-logout">Sign Out</a>
                    </div>
                <?php else: ?>
                    <a href="<?= base_url('login.php') ?>" class="btn btn-outline-sm <?= $current_page === 'login.php' ? 'active' : '' ?>">Sign In</a>
                    <a href="<?= base_url('register.php') ?>" class="btn btn-primary-sm <?= $current_page === 'register.php' ? 'active' : '' ?>">Register</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- Flash Notification Toast/Banner -->
    <?php if ($flash): ?>
        <aside class="container flash-container" aria-label="Notification">
            <div class="flash-alert flash-<?= encode($flash['type']) ?>" role="alert">
                <div class="flash-icon">
                    <?php if ($flash['type'] === 'success'): ?>✓
                    <?php elseif ($flash['type'] === 'error'): ?>✕
                    <?php elseif ($flash['type'] === 'warning'): ?>⚠
                    <?php else: ?>ℹ<?php endif; ?>
                </div>
                <div class="flash-message"><?= encode($flash['message']) ?></div>
                <button type="button" class="flash-close" aria-label="Dismiss">&times;</button>
            </div>
        </aside>
    <?php endif; ?>

    <!-- Main Content Container -->
    <main class="main-content">
