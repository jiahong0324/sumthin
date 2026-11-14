<?php
// ============================================================================
// File: admin/index.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Security & Admin Dashboard
// Description: Protected administrative dashboard. Strictly enforced by auth('Admin').
// ============================================================================
require_once __DIR__ . '/../_base.php';

// Enforce Admin Role-Based Security: Guests redirected to login, Members receive 403
auth('Admin');

// Fetch live statistics from database
$stat_members    = $db->query("SELECT COUNT(*) FROM `user` WHERE `role` = 'Member'")->fetchColumn();
$stat_products   = $db->query("SELECT COUNT(*) FROM `product`")->fetchColumn();
$stat_categories = $db->query("SELECT COUNT(*) FROM `category`")->fetchColumn();
$stat_orders     = $db->query("SELECT COUNT(*) FROM `order`")->fetchColumn();
$stat_revenue    = $db->query("SELECT COALESCE(SUM(`final_amount`), 0) FROM `order` WHERE `payment_status` = 'Paid'")->fetchColumn();

// Fetch recent members
$recent_members = $db->query("SELECT `id`, `name`, `email`, `phone`, `status`, `created_at` FROM `user` WHERE `role` = 'Member' ORDER BY `id` DESC LIMIT 5")->fetchAll();

// Fetch recent login security activity
$recent_logins = $db->query("SELECT `email`, `ip_address`, `attempted_at` FROM `login_attempt` ORDER BY `id` DESC LIMIT 5")->fetchAll();

$title = 'Admin Dashboard';
require_once __DIR__ . '/../_head.php';
?>

<div class="container">
    <!-- Admin Header Panel -->
    <div class="admin-header-panel">
        <div>
            <h1 style="font-size:24px; font-weight:800; color:var(--dark);">Admin Control Center</h1>
            <p style="font-size:14px; color:var(--gray); margin-top:4px;">
                Role-Based Access Control verified: Signed in as <strong><?= encode($user->name) ?></strong> (<?= encode($user->role) ?>)
            </p>
        </div>
        <div style="display:flex; gap:10px; align-items:center;">
            <a href="<?= base_url('admin/settings.php') ?>" class="btn btn-outline-sm">⚙ Settings</a>
            <a href="<?= base_url('admin/categories.php') ?>" class="btn btn-outline-sm">Categories (<?= (int)$stat_categories ?>)</a>
            <a href="<?= base_url('admin/products.php') ?>" class="btn btn-outline-sm">Manage Products</a>
            <a href="<?= base_url('admin/product_add.php') ?>" class="btn btn-primary-sm">+ Add Product</a>
        </div>
    </div>

    <!-- Live Statistics Cards -->
    <div class="admin-stats-grid">
        <a href="<?= base_url('admin/members.php') ?>" class="stat-card" style="text-decoration:none; display:block;">
            <div class="stat-label">Total Members &rarr;</div>
            <div class="stat-value"><?= (int)$stat_members ?></div>
        </a>
        <a href="<?= base_url('admin/products.php') ?>" class="stat-card" style="text-decoration:none; display:block;">
            <div class="stat-label">Active Products &rarr;</div>
            <div class="stat-value"><?= (int)$stat_products ?></div>
        </a>
        <a href="<?= base_url('admin/orders.php') ?>" class="stat-card" style="text-decoration:none; display:block;">
            <div class="stat-label">Total Orders &rarr;</div>
            <div class="stat-value"><?= (int)$stat_orders ?></div>
        </a>
        <div class="stat-card">
            <div class="stat-label">Paid Revenue</div>
            <div class="stat-value">RM <?= number_format((float)$stat_revenue, 2) ?></div>
        </div>
    </div>

    <!-- Current Store Configuration Overview Panel -->
    <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:20px; margin-bottom:32px; box-shadow:var(--shadow-sm); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
        <div style="display:flex; gap:24px; flex-wrap:wrap; align-items:center;">
            <div>
                <span style="font-size:11px; text-transform:uppercase; color:var(--gray); font-weight:700; letter-spacing:0.05em; display:block;">Store Brand</span>
                <strong style="font-size:16px; color:var(--dark);"><?= encode(setting('store_name', 'sumthin')) ?></strong>
            </div>
            <div style="border-left:1px solid var(--border); padding-left:20px;">
                <span style="font-size:11px; text-transform:uppercase; color:var(--gray); font-weight:700; letter-spacing:0.05em; display:block;">Top Banner</span>
                <span class="role-pill <?= setting('announcement_enabled', '1') === '1' ? 'pill-member' : 'pill-admin' ?>">
                    <?= setting('announcement_enabled', '1') === '1' ? 'Enabled' : 'Disabled' ?>
                </span>
            </div>
            <div style="border-left:1px solid var(--border); padding-left:20px;">
                <span style="font-size:11px; text-transform:uppercase; color:var(--gray); font-weight:700; letter-spacing:0.05em; display:block;">Free Shipping</span>
                <strong style="color:var(--dark);">Orders &ge; RM <?= number_format((float)setting('free_shipping_threshold', 150), 2) ?></strong>
            </div>
            <div style="border-left:1px solid var(--border); padding-left:20px;">
                <span style="font-size:11px; text-transform:uppercase; color:var(--gray); font-weight:700; letter-spacing:0.05em; display:block;">Security Lockout</span>
                <span style="color:var(--dark); font-size:13px;">Max <?= encode(setting('max_login_fails', 3)) ?> fails (<?= encode(setting('lockout_minutes', 15)) ?>m lock)</span>
            </div>
        </div>
        <div>
            <a href="<?= base_url('admin/settings.php') ?>" class="btn btn-outline-sm" style="font-weight:700;">
                Modify Store Settings &rarr;
            </a>
        </div>
    </div>

    <!-- Recent Registered Members Table -->
    <div style="margin-bottom: 40px;">
        <div class="section-title-wrap">
            <h2 class="section-title">Latest Member Registrations</h2>
            <span style="font-size:13px; color:var(--gray);">Live from database `user`</span>
        </div>

        <div class="table-responsive">
            <table class="table-custom">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Status</th>
                        <th>Registered Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_members)): ?>
                        <tr><td colspan="6" style="text-align:center; color:var(--gray); padding:20px;">No registered members found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recent_members as $m): ?>
                            <tr>
                                <td>#<?= (int)$m->id ?></td>
                                <td><strong><?= encode($m->name) ?></strong></td>
                                <td><?= encode($m->email) ?></td>
                                <td><?= encode($m->phone ?? 'N/A') ?></td>
                                <td>
                                    <span class="role-pill <?= $m->status === 'Active' ? 'pill-member' : 'pill-admin' ?>">
                                        <?= encode($m->status) ?>
                                    </span>
                                </td>
                                <td><?= encode(date('d M Y, h:i A', strtotime($m->created_at))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Security Activity Log -->
    <div>
        <div class="section-title-wrap">
            <h2 class="section-title">Security & Login Activity</h2>
            <span style="font-size:13px; color:var(--gray);">Brute-force monitoring (`login_attempt`)</span>
        </div>

        <div class="table-responsive">
            <table class="table-custom">
                <thead>
                    <tr>
                        <th>Attempted Email</th>
                        <th>IP Address</th>
                        <th>Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_logins)): ?>
                        <tr><td colspan="3" style="text-align:center; color:var(--gray); padding:20px;">No recent failed login attempts logged.</td></tr>
                    <?php else: ?>
                        <?php foreach ($recent_logins as $l): ?>
                            <tr>
                                <td><code><?= encode($l->email) ?></code></td>
                                <td><?= encode($l->ip_address) ?></td>
                                <td><?= encode(date('d M Y, h:i:s A', strtotime($l->attempted_at))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../_foot.php'; ?>
