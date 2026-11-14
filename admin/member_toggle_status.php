<?php
// ============================================================================
// File: admin/member_toggle_status.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Member Maintenance (Admin Block/Unblock Member)
// Description: Toggles member status between Active and Blocked, invalidating
//              active sessions and remember cookies if blocked.
// ============================================================================
require_once __DIR__ . '/../_base.php';

auth('Admin');

if (is_post()) {
    verify_csrf();

    $id = (int)req('id');
    if ($id > 0) {
        $stmt = $db->prepare('SELECT `id`, `name`, `status`, `role` FROM `user` WHERE `id` = ?');
        $stmt->execute([$id]);
        $m = $stmt->fetch();

        if ($m && $m->role === 'Member') {
            $new_status = ($m->status === 'Active') ? 'Blocked' : 'Active';

            // If blocking, clear remember token
            if ($new_status === 'Blocked') {
                $up = $db->prepare('UPDATE `user` SET `status` = ?, `remember_token` = NULL WHERE `id` = ?');
            } else {
                $up = $db->prepare('UPDATE `user` SET `status` = ?, `failed_attempts` = 0, `locked_until` = NULL WHERE `id` = ?');
            }
            $up->execute([$new_status, $id]);

            set_flash('success', "Member '{$m->name}' account status changed to {$new_status}.");
            redirect(base_url('admin/member_detail.php?id=' . $id));
        }
    }
}

redirect(base_url('admin/members.php'));
