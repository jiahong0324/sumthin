<?php
// ============================================================================
// File: admin/voucher_delete.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Voucher Maintenance (Delete Voucher Action)
// Description: Secure deletion with strict safeguard: vouchers with used_count > 0
//              cannot be deleted, only deactivated.
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
        set_flash('error', 'Voucher not found or already deleted.');
        redirect(base_url('admin/vouchers.php'));
    }

    // Safeguard: vouchers that have been used cannot be deleted
    if ($voucher->used_count > 0) {
        set_flash('error', "Voucher '{$voucher->code}' has been redeemed {$voucher->used_count} time(s) and cannot be deleted. You can deactivate it instead.");
        redirect(base_url('admin/vouchers.php'));
    }

    try {
        $del = $db->prepare('DELETE FROM `voucher` WHERE `id` = ?');
        $del->execute([$id]);

        set_flash('success', "Voucher '{$voucher->code}' was permanently deleted.");
    } catch (PDOException $e) {
        set_flash('error', 'Database error while deleting voucher: ' . $e->getMessage());
    }
}

redirect(base_url('admin/vouchers.php'));
