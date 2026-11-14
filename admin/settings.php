<?php
// ============================================================================
// File: admin/settings.php
// Project: sumthin (Tech Gadget Online Store)
// Module: System Settings & Store Configuration (Admin Control)
// Description: Administrative configuration portal allowing full dynamic control
//              over store branding, marketing announcements, shipping fees,
//              and brute-force security lockout thresholds.
// ============================================================================
require_once __DIR__ . '/../_base.php';

auth('Admin');

// Fetch all existing settings
$settings_rows = $db->query('SELECT * FROM `setting` ORDER BY `id` ASC')->fetchAll();
$s = [];
foreach ($settings_rows as $row) {
    $s[$row->key] = $row->value;
}

if (is_post()) {
    verify_csrf();

    $updates = [
        'store_name'              => req('store_name', 'sumthin'),
        'store_tagline'           => req('store_tagline', 'Curated Tech Gadgets & Daily Essentials'),
        'announcement_bar'        => req('announcement_bar', ''),
        'announcement_enabled'    => req('announcement_enabled') ? '1' : '0',
        'free_shipping_threshold' => number_format(max(0, (float)req('free_shipping_threshold', 150)), 2, '.', ''),
        'standard_shipping_fee'   => number_format(max(0, (float)req('standard_shipping_fee', 15)), 2, '.', ''),
        'support_email'           => req('support_email', 'support@sumthin.com'),
        'support_phone'           => req('support_phone', '03-41450123'),
        'max_login_fails'         => (string)max(1, (int)req('max_login_fails', 3)),
        'lockout_minutes'         => (string)max(1, (int)req('lockout_minutes', 15)),
    ];

    if ($updates['store_name'] === '') {
        $errors['store_name'] = 'Store name is required.';
    }

    if (empty($errors)) {
        try {
            $db->beginTransaction();
            $up = $db->prepare('UPDATE `setting` SET `value` = ? WHERE `key` = ?');

            foreach ($updates as $k => $v) {
                $up->execute([$v, $k]);
                $s[$k] = $v;
            }

            $db->commit();
            set_flash('success', 'Store configuration and site settings updated successfully!');
            redirect(base_url('admin/settings.php'));
        } catch (Exception $e) {
            $db->rollBack();
            $errors['db'] = 'Failed to save settings: ' . $e->getMessage();
        }
    }
}

$title = 'Store Settings';
require_once __DIR__ . '/../_head.php';
?>

<div class="container" style="max-width: 850px;">
    <!-- Header -->
    <div class="admin-header-panel">
        <div>
            <h1 style="font-size:24px; font-weight:800; color:var(--dark);">Store Configuration & Settings</h1>
            <p style="font-size:14px; color:var(--gray); margin-top:4px;">
                Customize branding, marketing announcements, shipping policies, and security thresholds.
            </p>
        </div>
        <span class="role-pill pill-admin" style="font-size:13px; padding:6px 14px;">Administrator Control</span>
    </div>

    <?php if (isset($errors['db'])): ?>
        <div class="flash-alert flash-error" style="margin-bottom:20px;">
            <div class="flash-icon">✕</div>
            <div class="flash-message"><?= encode($errors['db']) ?></div>
        </div>
    <?php endif; ?>

    <form action="<?= base_url('admin/settings.php') ?>" method="POST" novalidate>
        <?= csrf_field() ?>

        <!-- 1. Store Identity & Branding -->
        <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:28px; margin-bottom:24px; box-shadow:var(--shadow-sm);">
            <h2 style="font-size:16px; font-weight:800; color:var(--dark); margin-bottom:16px; border-bottom:1px solid var(--border); padding-bottom:10px;">
                1. Store Identity & Public Contact
            </h2>

            <div style="display:flex; gap:16px; flex-wrap:wrap;">
                <div class="form-group" style="flex:1; min-width:240px;">
                    <label for="store_name" class="form-label">Store Brand Name *</label>
                    <input type="text" name="store_name" id="store_name" value="<?= encode($s['store_name'] ?? 'sumthin') ?>" class="form-control">
                    <?= err('store_name') ?>
                </div>

                <div class="form-group" style="flex:1; min-width:240px;">
                    <label for="store_tagline" class="form-label">Store Slogan / Tagline</label>
                    <input type="text" name="store_tagline" id="store_tagline" value="<?= encode($s['store_tagline'] ?? '') ?>" class="form-control">
                </div>
            </div>

            <div style="display:flex; gap:16px; flex-wrap:wrap;">
                <div class="form-group" style="flex:1; min-width:240px;">
                    <label for="support_email" class="form-label">Customer Support Email</label>
                    <input type="email" name="support_email" id="support_email" value="<?= encode($s['support_email'] ?? '') ?>" class="form-control">
                </div>

                <div class="form-group" style="flex:1; min-width:240px;">
                    <label for="support_phone" class="form-label">Customer Support Hotline</label>
                    <input type="text" name="support_phone" id="support_phone" value="<?= encode($s['support_phone'] ?? '') ?>" class="form-control">
                </div>
            </div>
        </div>

        <!-- 2. Marketing & Top Announcement Bar -->
        <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:28px; margin-bottom:24px; box-shadow:var(--shadow-sm);">
            <h2 style="font-size:16px; font-weight:800; color:var(--dark); margin-bottom:16px; border-bottom:1px solid var(--border); padding-bottom:10px;">
                2. Marketing & Announcement Banner
            </h2>

            <div class="form-group">
                <label for="announcement_bar" class="form-label">Top Banner Message</label>
                <input type="text" name="announcement_bar" id="announcement_bar" value="<?= encode($s['announcement_bar'] ?? '') ?>" class="form-control" placeholder="e.g. ⚡ Flash Deal: 20% off all custom mechanical keyboards this weekend!">
                <span class="form-help">Displayed at the very top of all visitor and member pages.</span>
            </div>

            <div class="form-row" style="margin-bottom:0;">
                <label class="checkbox-label">
                    <input type="checkbox" name="announcement_enabled" value="1" <?= (!empty($s['announcement_enabled']) && $s['announcement_enabled'] === '1') ? 'checked' : '' ?>>
                    <strong>Enable and display the top announcement banner</strong>
                </label>
            </div>
        </div>

        <!-- 3. Shipping & Delivery Rates -->
        <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:28px; margin-bottom:24px; box-shadow:var(--shadow-sm);">
            <h2 style="font-size:16px; font-weight:800; color:var(--dark); margin-bottom:16px; border-bottom:1px solid var(--border); padding-bottom:10px;">
                3. Shipping & Delivery Fees
            </h2>

            <div style="display:flex; gap:16px; flex-wrap:wrap;">
                <div class="form-group" style="flex:1; min-width:240px;">
                    <label for="free_shipping_threshold" class="form-label">Free Express Shipping Threshold (RM)</label>
                    <input type="text" name="free_shipping_threshold" id="free_shipping_threshold" value="<?= encode($s['free_shipping_threshold'] ?? '150.00') ?>" class="form-control">
                    <span class="form-help">Orders equal to or above this subtotal enjoy RM 0.00 delivery.</span>
                </div>

                <div class="form-group" style="flex:1; min-width:240px;">
                    <label for="standard_shipping_fee" class="form-label">Standard Courier Delivery Fee (RM)</label>
                    <input type="text" name="standard_shipping_fee" id="standard_shipping_fee" value="<?= encode($s['standard_shipping_fee'] ?? '15.00') ?>" class="form-control">
                    <span class="form-help">Applied to carts below the free shipping threshold.</span>
                </div>
            </div>
        </div>

        <!-- 4. Security & Brute-Force Rate Limiting -->
        <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:28px; margin-bottom:28px; box-shadow:var(--shadow-sm);">
            <h2 style="font-size:16px; font-weight:800; color:var(--dark); margin-bottom:16px; border-bottom:1px solid var(--border); padding-bottom:10px;">
                4. Security Policies & Account Protection
            </h2>

            <div style="display:flex; gap:16px; flex-wrap:wrap;">
                <div class="form-group" style="flex:1; min-width:240px;">
                    <label for="max_login_fails" class="form-label">Max Failed Login Attempts</label>
                    <input type="number" name="max_login_fails" id="max_login_fails" value="<?= encode($s['max_login_fails'] ?? '3') ?>" min="1" max="10" class="form-control">
                    <span class="form-help">Locks account after consecutive wrong password entries.</span>
                </div>

                <div class="form-group" style="flex:1; min-width:240px;">
                    <label for="lockout_minutes" class="form-label">Lockout Duration (Minutes)</label>
                    <input type="number" name="lockout_minutes" id="lockout_minutes" value="<?= encode($s['lockout_minutes'] ?? '15') ?>" min="1" max="120" class="form-control">
                    <span class="form-help">Time duration the user must wait before trying again.</span>
                </div>
            </div>
        </div>

        <!-- Submit Button -->
        <div style="margin-bottom:40px;">
            <button type="submit" class="btn btn-primary btn-block" style="padding:14px; font-size:16px;">
                Save Store Settings
            </button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../_foot.php'; ?>
