<?php
// ============================================================================
// File: admin/voucher_edit.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Voucher Maintenance (Edit Discount Voucher)
// Description: Edit existing voucher details with usage_limit >= used_count validation.
// ============================================================================
require_once __DIR__ . '/../_base.php';

// Enforce Admin authorization
auth('Admin');

$id = (int)req('id');
if ($id <= 0) {
    set_flash('error', 'Invalid voucher ID.');
    redirect(base_url('admin/vouchers.php'));
}

// Fetch voucher
$stmt = $db->prepare('SELECT * FROM `voucher` WHERE `id` = ?');
$stmt->execute([$id]);
$voucher = $stmt->fetch();

if (!$voucher) {
    set_flash('error', 'Voucher not found or has been deleted.');
    redirect(base_url('admin/vouchers.php'));
}

$errors = [];
$code           = strtoupper(trim(req('code', $voucher->code)));
$discount_type  = req('discount_type', $voucher->discount_type);
$discount_value = req('discount_value', $voucher->discount_value);
$min_spend      = req('min_spend', $voucher->min_spend);
$max_discount   = req('max_discount', $voucher->max_discount ?? '');
$usage_limit    = req('usage_limit', $voucher->usage_limit);
$expires_at     = req('expires_at', date('Y-m-d\TH:i', strtotime($voucher->expires_at)));
$is_active      = is_post() ? (req('is_active') ? 1 : 0) : $voucher->is_active;

if (is_post()) {
    verify_csrf();

    // 1. Code validation: 4-20 chars A-Z0-9, unique across other records
    if ($code === '') {
        $errors['code'] = 'Voucher code is required.';
    } elseif (!preg_match('/^[A-Z0-9]{4,20}$/', $code)) {
        $errors['code'] = 'Voucher code must be 4 to 20 alphanumeric characters (A-Z, 0-9).';
    } else {
        $chk_stmt = $db->prepare('SELECT COUNT(*) FROM `voucher` WHERE `code` = ? AND `id` != ?');
        $chk_stmt->execute([$code, $id]);
        if ($chk_stmt->fetchColumn() > 0) {
            $errors['code'] = "Voucher code '{$code}' is already in use by another voucher.";
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

    // 6. Usage Limit validation: must be >= 1 and NOT LOWER THAN used_count on edit
    if ($usage_limit === '' || !ctype_digit((string)$usage_limit) || (int)$usage_limit < 1) {
        $errors['usage_limit'] = 'Usage limit must be an integer of at least 1.';
    } elseif ((int)$usage_limit < (int)$voucher->used_count) {
        $errors['usage_limit'] = "Usage limit cannot be lower than the current redeemed count ({$voucher->used_count}).";
    }

    // 7. Expiry Date validation
    $exp_timestamp = strtotime($expires_at);
    if (!$exp_timestamp) {
        $errors['expires_at'] = 'Please provide a valid expiration date and time.';
    }

    // Process update
    if (empty($errors)) {
        try {
            $upd = $db->prepare('
                UPDATE `voucher` SET
                    `code`           = ?,
                    `discount_type`  = ?,
                    `discount_value` = ?,
                    `min_spend`      = ?,
                    `max_discount`   = ?,
                    `usage_limit`    = ?,
                    `is_active`      = ?,
                    `expires_at`     = ?
                WHERE `id` = ?
            ');
            $upd->execute([
                $code,
                $discount_type,
                number_format((float)$discount_value, 2, '.', ''),
                number_format((float)$min_spend, 2, '.', ''),
                $clean_max_discount,
                (int)$usage_limit,
                $is_active ? 1 : 0,
                date('Y-m-d H:i:s', $exp_timestamp),
                $id
            ]);

            set_flash('success', "Voucher '{$code}' updated successfully!");
            redirect(base_url('admin/vouchers.php'));
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errors['code'] = "Voucher code '{$code}' already exists.";
            } else {
                $errors['general'] = 'Failed to update voucher. Please try again.';
            }
        }
    }
}

$title = 'Edit Voucher: ' . $voucher->code;
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
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:20px;">
            <div>
                <h1 style="font-size:22px; font-weight:800; color:var(--dark); margin-bottom:4px;">
                    Edit Voucher: <span style="font-family:monospace; color:var(--primary);"><?= encode($voucher->code) ?></span>
                </h1>
                <p style="font-size:14px; color:var(--gray); margin:0;">
                    Modify discount parameters, usage limits, or toggle active status.
                </p>
            </div>
            <div>
                <span class="role-pill <?= $voucher->is_active ? 'pill-member' : 'pill-admin' ?>" style="<?= $voucher->is_active ? 'background:#dcfce7; color:#15803d;' : 'background:#fee2e2; color:#b91c1c;' ?>">
                    <?= $voucher->is_active ? 'Active' : 'Inactive' ?>
                </span>
            </div>
        </div>

        <!-- Used Count Notice -->
        <?php if ($voucher->used_count > 0): ?>
            <div style="background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; border-radius:8px; padding:12px 16px; margin-bottom:20px; font-size:13px; line-height:1.5;">
                ℹ️ <strong>Redeemed <?= (int)$voucher->used_count ?> time(s):</strong> This voucher has existing customer redemptions and cannot be deleted to preserve order integrity. You may deactivate it below at any time to prevent new redemptions. The usage limit cannot be set below <?= (int)$voucher->used_count ?>.
            </div>
        <?php endif; ?>

        <?php if (!empty($errors['general'])): ?>
            <div style="background:#fee2e2; border:1px solid #fecaca; color:#b91c1c; border-radius:8px; padding:12px 16px; margin-bottom:20px; font-size:14px;">
                <?= encode($errors['general']) ?>
            </div>
        <?php endif; ?>

        <form action="<?= base_url('admin/voucher_edit.php?id=' . $id) ?>" method="POST" id="voucher-form">
            <?= csrf_field() ?>

            <!-- Voucher Code -->
            <div style="margin-bottom:20px;">
                <label for="code" class="form-label">Voucher Code <span style="color:var(--error);">*</span></label>
                <input type="text" name="code" id="code" value="<?= encode($code) ?>" required 
                       maxlength="20" class="form-control"
                       style="font-family:monospace; font-weight:700; text-transform:uppercase;">
                <span style="font-size:12px; color:var(--gray);">4 to 20 uppercase alphanumeric characters (A-Z, 0-9).</span>
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
                    <input type="number" name="discount_value" id="discount_value" step="0.01" min="0.01" value="<?= encode($discount_value) ?>" required class="form-control">
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
                    <input type="number" name="usage_limit" id="usage_limit" min="<?= max(1, (int)$voucher->used_count) ?>" step="1" value="<?= encode($usage_limit) ?>" required class="form-control">
                    <span style="font-size:12px; color:var(--gray);">Redeemed: <strong><?= (int)$voucher->used_count ?></strong>. Cannot be lower than redeemed count.</span>
                    <?php if (!empty($errors['usage_limit'])): ?>
                        <div style="color:var(--error); font-size:12px; margin-top:4px;"><?= encode($errors['usage_limit']) ?></div>
                    <?php endif; ?>
                </div>

                <div>
                    <label for="expires_at" class="form-label">Expiration Date & Time <span style="color:var(--error);">*</span></label>
                    <input type="datetime-local" name="expires_at" id="expires_at" value="<?= encode($expires_at) ?>" required class="form-control">
                    <?php if (!empty($errors['expires_at'])): ?>
                        <div style="color:var(--error); font-size:12px; margin-top:4px;"><?= encode($errors['expires_at']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Active Status Checkbox -->
            <div style="margin-bottom:24px;">
                <label style="display:inline-flex; align-items:center; gap:8px; cursor:pointer; font-size:14px; font-weight:600; color:var(--dark);">
                    <input type="checkbox" name="is_active" value="1" <?= $is_active ? 'checked' : '' ?> style="width:18px; height:18px;">
                    Voucher is Active
                </label>
                <div style="font-size:12px; color:var(--gray); margin-left:26px;">Unchecking this immediately deactivates the voucher from the checkout and cart.</div>
            </div>

            <div style="display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <?php if ($voucher->used_count == 0): ?>
                        <button type="submit" formaction="<?= base_url('admin/voucher_delete.php') ?>" class="btn btn-outline" style="color:var(--error); border-color:#fca5a5;" data-confirm="Are you sure you want to delete this voucher? This cannot be undone.">
                            Delete Voucher
                        </button>
                    <?php endif; ?>
                </div>
                <div style="display:flex; gap:12px;">
                    <a href="<?= base_url('admin/vouchers.php') ?>" class="btn btn-outline">Cancel</a>
                    <button type="submit" class="btn btn-primary" style="padding:10px 24px;">
                        Save Changes
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../_foot.php'; ?>
