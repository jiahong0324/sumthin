<?php
// ============================================================================
// File: admin/voucher_toggle.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Voucher Maintenance (Quick Toggle Status)
// Description: Instantly activate or deactivate a promotional discount code.
// ============================================================================
require_once __DIR__ . '/../_base.php';

// Enforce Admin authorization
auth('Admin');

if (is_post()) {
    verify_csrf();

    $id = (int)req('id');
    if ($id <= 0) {
        set_flash('error', 'Invalid voucher ID.');
        redirect(base_url('admin/vouchers.php'));
    }

    $stmt = $db->prepare('SELECT * FROM `voucher` WHERE `id` = ?');
    $stmt->execute([$id]);
    $voucher = $stmt->fetch();

    if (!$voucher) {
        set_flash('error', 'Voucher not found.');
        redirect(base_url('admin/vouchers.php'));
    }

    $new_status = $voucher->is_active ? 0 : 1;
    $upd = $db->prepare('UPDATE `voucher` SET `is_active` = ? WHERE `id` = ?');
    $upd->execute([$new_status, $id]);

    $status_label = $new_status ? 'activated' : 'deactivated';
    set_flash('success', "Voucher '{$voucher->code}' was successfully {$status_label}.");
}

redirect(base_url('admin/vouchers.php'));
