<?php
// ============================================================================
// File: admin/voucher_add.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Voucher Maintenance (Create Discount Voucher)
// Description: Add new discount codes with strict server-side validation.
// ============================================================================
require_once __DIR__ . '/../_base.php';

// Enforce Admin authorization
auth('Admin');

$errors = [];
$code           = strtoupper(trim(req('code')));
$discount_type  = req('discount_type', 'percentage');
$discount_value = req('discount_value', '');
$min_spend      = req('min_spend', '0.00');
$max_discount   = req('max_discount', '');
$usage_limit    = req('usage_limit', '100');
$expires_at     = req('expires_at', date('Y-m-d\TH:i', strtotime('+30 days')));
$is_active      = req('is_active', '1');

if (is_post()) {
    verify_csrf();

    // 1. Code validation: 4-20 chars A-Z0-9, unique
    if ($code === '') {
        $errors['code'] = 'Voucher code is required.';
    } elseif (!preg_match('/^[A-Z0-9]{4,20}$/', $code)) {
        $errors['code'] = 'Voucher code must be 4 to 20 alphanumeric characters (A-Z, 0-9).';
    } else {
        $stmt = $db->prepare('SELECT COUNT(*) FROM `voucher` WHERE `code` = ?');
        $stmt->execute([$code]);
        if ($stmt->fetchColumn() > 0) {
            $errors['code'] = "Voucher code '{$code}' already exists. Please choose a unique code.";
        }
    }

    // 2. Discount Type validation
    if (!in_array($discount_type, ['percentage', 'fixed'], true)) {
        $errors['discount_type'] = 'Invalid discount type selected.';
    }

    // 3. Discount Value validation
    if ($discount_value === '' || !is_numeric($discount_value)) {
        $errors['discount_value'] = 'Valid discount value is required.';
    } else {
        $val = (float)$discount_value;
        if ($discount_type === 'percentage') {
            if ($val < 1 || $val > 100) {
                $errors['discount_value'] = 'Percentage discount must be between 1% and 100%.';
            }
        } else {
            if ($val <= 0) {
                $errors['discount_value'] = 'Fixed discount must be greater than RM 0.00.';
            }
        }
    }

    // 4. Min Spend validation
    if ($min_spend === '' || !is_numeric($min_spend) || (float)$min_spend < 0) {
        $errors['min_spend'] = 'Minimum spend must be a number greater than or equal to 0.00.';
    }

    // 5. Max Discount validation (optional, only for percentage)
    $clean_max_discount = null;
    if ($discount_type === 'percentage' && $max_discount !== '') {
        if (!is_numeric($max_discount) || (float)$max_discount <= 0) {
            $errors['max_discount'] = 'Maximum discount cap must be greater than RM 0.00.';
        } else {
            $clean_max_discount = number_format((float)$max_discount, 2, '.', '');
        }
    }

    // 6. Usage Limit validation
    if ($usage_limit === '' || !ctype_digit((string)$usage_limit) || (int)$usage_limit < 1) {
        $errors['usage_limit'] = 'Usage limit must be an integer of at least 1.';
    }

    // 7. Expiry Date validation: must be in the future on create
    $exp_timestamp = strtotime($expires_at);
    if (!$exp_timestamp) {
        $errors['expires_at'] = 'Please provide a valid expiration date and time.';
    } elseif ($exp_timestamp <= time()) {
        $errors['expires_at'] = 'Expiration date must be set in the future.';
    }

    // Process insertion
    if (empty($errors)) {
        try {
            $stmt = $db->prepare('
                INSERT INTO `voucher` 
                (`code`, `discount_type`, `discount_value`, `min_spend`, `max_discount`, `usage_limit`, `used_count`, `is_active`, `expires_at`)
                VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)
            ');
            $stmt->execute([
                $code,
                $discount_type,
                number_format((float)$discount_value, 2, '.', ''),
                number_format((float)$min_spend, 2, '.', ''),
                $clean_max_discount,
                (int)$usage_limit,
                $is_active ? 1 : 0,
                date('Y-m-d H:i:s', $exp_timestamp)
            ]);

            set_flash('success', "Voucher '{$code}' created successfully!");
            redirect(base_url('admin/vouchers.php'));
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errors['code'] = "Voucher code '{$code}' already exists.";
            } else {
                $errors['general'] = 'Failed to create voucher. Please try again.';
            }
        }
    }
}

$title = 'Create Voucher';
require_once __DIR__ . '/../_head.php';
?>

<div class="container" style="max-width:760px; margin:0 auto;">
    <!-- Breadcrumb / Back Link -->
    <div style="margin-bottom:16px;">
        <a href="<?= base_url('admin/vouchers.php') ?>" class="btn btn-outline-sm" style="display:inline-flex; align-items:center; gap:6px;">
            &larr; Back to Vouchers
        </a>
    </div>

    <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:28px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
        <h1 style="font-size:22px; font-weight:800; color:var(--dark); margin-bottom:4px;">Create Discount Voucher</h1>
        <p style="font-size:14px; color:var(--gray); margin-bottom:24px;">
            Configure customer promotional codes, redemption caps, and eligibility requirements.
        </p>

        <?php if (!empty($errors['general'])): ?>
            <div style="background:#fee2e2; border:1px solid #fecaca; color:#b91c1c; border-radius:8px; padding:12px 16px; margin-bottom:20px; font-size:14px;">
                <?= encode($errors['general']) ?>
            </div>
        <?php endif; ?>

        <form action="<?= base_url('admin/voucher_add.php') ?>" method="POST" id="voucher-form">
            <?= csrf_field() ?>

            <!-- Voucher Code -->
            <div style="margin-bottom:20px;">
                <label for="code" class="form-label">Voucher Code <span style="color:var(--error);">*</span></label>
                <input type="text" name="code" id="code" value="<?= encode($code) ?>" required 
                       placeholder="e.g. MEGA20, WELCOME50" maxlength="20" class="form-control"
                       style="font-family:monospace; font-weight:700; text-transform:uppercase;">
                <span style="font-size:12px; color:var(--gray);">4 to 20 uppercase alphanumeric characters (A-Z, 0-9). Automatically uppercased.</span>
                <?php if (!empty($errors['code'])): ?>
                    <div style="color:var(--error); font-size:12px; margin-top:4px;"><?= encode($errors['code']) ?></div>
                <?php endif; ?>
            </div>

            <!-- Discount Type & Value Row -->
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px;">
                <div>
                    <label for="discount_type" class="form-label">Discount Type <span style="color:var(--error);">*</span></label>
                    <select name="discount_type" id="discount_type" class="form-select">
                        <option value="percentage" <?= $discount_type === 'percentage' ? 'selected' : '' ?>>Percentage (%)</option>
                        <option value="fixed" <?= $discount_type === 'fixed' ? 'selected' : '' ?>>Fixed Amount (RM)</option>
                    </select>
                    <?php if (!empty($errors['discount_type'])): ?>
                        <div style="color:var(--error); font-size:12px; margin-top:4px;"><?= encode($errors['discount_type']) ?></div>
                    <?php endif; ?>
                </div>

                <div>
                    <label for="discount_value" class="form-label">
                        Discount Value <span style="color:var(--error);">*</span>
                        <span id="discount_unit_label" style="font-weight:400; color:var(--gray);">(<?= $discount_type === 'percentage' ? '%' : 'RM' ?>)</span>
                    </label>
                    <input type="number" name="discount_value" id="discount_value" step="0.01" min="0.01" value="<?= encode($discount_value) ?>" required class="form-control" placeholder="e.g. 15 or 50.00">
                    <?php if (!empty($errors['discount_value'])): ?>
                        <div style="color:var(--error); font-size:12px; margin-top:4px;"><?= encode($errors['discount_value']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Min Spend & Max Discount Row -->
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px;">
                <div>
                    <label for="min_spend" class="form-label">Minimum Spend (RM)</label>
                    <input type="number" name="min_spend" id="min_spend" step="0.01" min="0" value="<?= encode($min_spend) ?>" class="form-control" placeholder="0.00">
                    <span style="font-size:12px; color:var(--gray);">Leave 0.00 for no minimum order requirement.</span>
                    <?php if (!empty($errors['min_spend'])): ?>
                        <div style="color:var(--error); font-size:12px; margin-top:4px;"><?= encode($errors['min_spend']) ?></div>
                    <?php endif; ?>
                </div>

                <div id="max_discount_group" style="<?= $discount_type === 'fixed' ? 'display:none;' : '' ?>">
                    <label for="max_discount" class="form-label">Maximum Discount Cap (RM)</label>
                    <input type="number" name="max_discount" id="max_discount" step="0.01" min="0.01" value="<?= encode($max_discount) ?>" class="form-control" placeholder="e.g. 50.00">
                    <span style="font-size:12px; color:var(--gray);">Optional ceiling cap for percentage discounts.</span>
                    <?php if (!empty($errors['max_discount'])): ?>
                        <div style="color:var(--error); font-size:12px; margin-top:4px;"><?= encode($errors['max_discount']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Usage Limit & Expiration Row -->
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px;">
                <div>
                    <label for="usage_limit" class="form-label">Total Usage Limit <span style="color:var(--error);">*</span></label>
                    <input type="number" name="usage_limit" id="usage_limit" min="1" step="1" value="<?= encode($usage_limit) ?>" required class="form-control" placeholder="100">
                    <span style="font-size:12px; color:var(--gray);">Maximum lifetime redemptions across all customers.</span>
                    <?php if (!empty($errors['usage_limit'])): ?>
                        <div style="color:var(--error); font-size:12px; margin-top:4px;"><?= encode($errors['usage_limit']) ?></div>
                    <?php endif; ?>
                </div>

                <div>
                    <label for="expires_at" class="form-label">Expiration Date & Time <span style="color:var(--error);">*</span></label>
                    <input type="datetime-local" name="expires_at" id="expires_at" value="<?= encode($expires_at) ?>" required class="form-control">
                    <span style="font-size:12px; color:var(--gray);">Must be set to a future date and time.</span>
                    <?php if (!empty($errors['expires_at'])): ?>
                        <div style="color:var(--error); font-size:12px; margin-top:4px;"><?= encode($errors['expires_at']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Active Status Checkbox -->
            <div style="margin-bottom:24px;">
                <label style="display:inline-flex; align-items:center; gap:8px; cursor:pointer; font-size:14px; font-weight:600; color:var(--dark);">
                    <input type="checkbox" name="is_active" value="1" <?= $is_active ? 'checked' : '' ?> style="width:18px; height:18px;">
                    Voucher is Active immediately upon creation
                </label>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px;">
                <a href="<?= base_url('admin/vouchers.php') ?>" class="btn btn-outline">Cancel</a>
                <button type="submit" class="btn btn-primary" style="padding:10px 24px;">
                    Create Voucher
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../_foot.php'; ?>
