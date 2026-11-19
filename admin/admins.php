<?php
// ============================================================================
// File: admin/admins.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Admin Account Maintenance (List, Add, Edit & Protect Admins)
// Description: Admin management interface with strict safety checks:
//              - Admins cannot block, demote, or delete themselves.
//              - The last remaining active Admin can never be disabled.
// ============================================================================
require_once __DIR__ . '/../_base.php';

// Enforce Admin authorization
auth('Admin');

$current_admin = current_user();
$errors = [];
$action = req('action');
$edit_id = (int)req('edit', 0);

// ----------------------------------------------------------------------------
// 1. DELETE ADMIN
// ----------------------------------------------------------------------------
if (is_post() && $action === 'delete') {
    verify_csrf();

    $id = (int)req('id');
    if ($id <= 0) {
        set_flash('error', 'Invalid admin ID.');
        redirect(base_url('admin/admins.php'));
    }

    // Safeguard 1: Admin cannot delete themselves
    if ($id === (int)$current_admin->id) {
        set_flash('error', 'Action rejected: You cannot delete your own administrator account.');
        redirect(base_url('admin/admins.php'));
    }

    // Safeguard 2: The last active Admin can never be deleted
    $active_admins_stmt = $db->prepare("SELECT COUNT(*) FROM `user` WHERE `role` = 'Admin' AND `status` = 'Active' AND `id` != ?");
    $active_admins_stmt->execute([$id]);
    $remaining_active = (int)$active_admins_stmt->fetchColumn();

    if ($remaining_active === 0) {
        set_flash('error', 'Action rejected: The system must retain at least one active Administrator account.');
        redirect(base_url('admin/admins.php'));
    }

    $del = $db->prepare("DELETE FROM `user` WHERE `id` = ? AND `role` = 'Admin'");
    $del->execute([$id]);

    set_flash('success', 'Administrator account was deleted successfully.');
    redirect(base_url('admin/admins.php'));
}

// ----------------------------------------------------------------------------
// 2. TOGGLE STATUS (BLOCK / UNBLOCK)
// ----------------------------------------------------------------------------
if (is_post() && $action === 'toggle_status') {
    verify_csrf();

    $id = (int)req('id');
    if ($id <= 0) {
        set_flash('error', 'Invalid admin ID.');
        redirect(base_url('admin/admins.php'));
    }

    // Safeguard 1: Admin cannot block themselves
    if ($id === (int)$current_admin->id) {
        set_flash('error', 'Action rejected: You cannot block or suspend your own administrator account.');
        redirect(base_url('admin/admins.php'));
    }

    $stmt = $db->prepare("SELECT * FROM `user` WHERE `id` = ? AND `role` = 'Admin'");
    $stmt->execute([$id]);
    $target_admin = $stmt->fetch();

    if (!$target_admin) {
        set_flash('error', 'Administrator account not found.');
        redirect(base_url('admin/admins.php'));
    }

    $new_status = ($target_admin->status === 'Active') ? 'Blocked' : 'Active';

    // Safeguard 2: If attempting to block, verify at least one other active admin remains
    if ($new_status === 'Blocked') {
        $other_active = (int)$db->query("SELECT COUNT(*) FROM `user` WHERE `role` = 'Admin' AND `status` = 'Active' AND `id` != {$id}")->fetchColumn();
        if ($other_active === 0) {
            set_flash('error', 'Action rejected: The last active Administrator cannot be disabled.');
            redirect(base_url('admin/admins.php'));
        }
    }

    $db->prepare("UPDATE `user` SET `status` = ? WHERE `id` = ?")->execute([$new_status, $id]);
    set_flash('success', "Administrator '{$target_admin->name}' is now {$new_status}.");
    redirect(base_url('admin/admins.php'));
}

// ----------------------------------------------------------------------------
// 3. ADD NEW ADMIN
// ----------------------------------------------------------------------------
$add_name  = trim(req('add_name'));
$add_email = trim(req('add_email'));
$add_phone = trim(req('add_phone'));
$add_pass  = req('add_password');

if (is_post() && $action === 'add') {
    verify_csrf();

    if ($add_name === '' || mb_strlen($add_name) < 2 || mb_strlen($add_name) > 100) {
        $errors['add_name'] = 'Full name must be between 2 and 100 characters.';
    }

    if ($add_email === '' || !filter_var($add_email, FILTER_VALIDATE_EMAIL)) {
        $errors['add_email'] = 'A valid email address is required.';
    } else {
        $chk_email = $db->prepare("SELECT COUNT(*) FROM `user` WHERE `email` = ?");
        $chk_email->execute([$add_email]);
        if ($chk_email->fetchColumn() > 0) {
            $errors['add_email'] = 'This email address is already registered.';
        }
    }

    $pw_err = validate_password($add_pass);
    if ($pw_err !== '') {
        $errors['add_password'] = $pw_err;
    }

    if ($add_phone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $add_phone)) {
        $errors['add_phone'] = 'Please enter a valid phone number.';
    }

    if (empty($errors)) {
        try {
            $ins = $db->prepare("
                INSERT INTO `user` (`role`, `name`, `email`, `password_hash`, `phone`, `status`, `email_verified_at`, `created_at`)
                VALUES ('Admin', ?, ?, ?, ?, 'Active', NOW(), NOW())
            ");
            $ins->execute([
                $add_name,
                $add_email,
                password_hash($add_pass, PASSWORD_DEFAULT),
                $add_phone !== '' ? $add_phone : null
            ]);

            set_flash('success', "New administrator account '{$add_name}' created successfully!");
            redirect(base_url('admin/admins.php'));
        } catch (PDOException $e) {
            $errors['general'] = 'Failed to create admin: ' . $e->getMessage();
        }
    }
}

// ----------------------------------------------------------------------------
// 4. EDIT ADMIN
// ----------------------------------------------------------------------------
$edit_admin = null;
if ($edit_id > 0) {
    $stmt = $db->prepare("SELECT * FROM `user` WHERE `id` = ? AND `role` = 'Admin'");
    $stmt->execute([$edit_id]);
    $edit_admin = $stmt->fetch();
}

$edit_name   = trim(req('edit_name', $edit_admin->name ?? ''));
$edit_phone  = trim(req('edit_phone', $edit_admin->phone ?? ''));
$edit_status = req('edit_status', $edit_admin->status ?? 'Active');
$edit_pass   = req('edit_password');

if (is_post() && $action === 'edit' && $edit_admin) {
    verify_csrf();

    if ($edit_name === '' || mb_strlen($edit_name) < 2 || mb_strlen($edit_name) > 100) {
        $errors['edit_name'] = 'Full name must be between 2 and 100 characters.';
    }

    if ($edit_phone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $edit_phone)) {
        $errors['edit_phone'] = 'Please enter a valid phone number.';
    }

    // Safeguard 1: Cannot block self
    if ($edit_admin->id === $current_admin->id && $edit_status === 'Blocked') {
        $errors['edit_status'] = 'You cannot block your own administrator account.';
    }

    // Safeguard 2: Last active admin cannot be disabled
    if ($edit_status === 'Blocked' && $edit_admin->status === 'Active') {
        $other_active = (int)$db->query("SELECT COUNT(*) FROM `user` WHERE `role` = 'Admin' AND `status` = 'Active' AND `id` != {$edit_admin->id}")->fetchColumn();
        if ($other_active === 0) {
            $errors['edit_status'] = 'Action rejected: The system must retain at least one active Admin account.';
        }
    }

    // Optional password update
    if ($edit_pass !== '') {
        $pw_err = validate_password($edit_pass);
        if ($pw_err !== '') {
            $errors['edit_password'] = $pw_err;
        }
    }

    if (empty($errors)) {
        try {
            if ($edit_pass !== '') {
                $upd = $db->prepare("UPDATE `user` SET `name` = ?, `phone` = ?, `status` = ?, `password_hash` = ? WHERE `id` = ? AND `role` = 'Admin'");
                $upd->execute([$edit_name, $edit_phone !== '' ? $edit_phone : null, $edit_status, password_hash($edit_pass, PASSWORD_DEFAULT), $edit_admin->id]);
            } else {
                $upd = $db->prepare("UPDATE `user` SET `name` = ?, `phone` = ?, `status` = ? WHERE `id` = ? AND `role` = 'Admin'");
                $upd->execute([$edit_name, $edit_phone !== '' ? $edit_phone : null, $edit_status, $edit_admin->id]);
            }

            set_flash('success', "Administrator '{$edit_name}' was updated successfully.");
            redirect(base_url('admin/admins.php'));
        } catch (PDOException $e) {
            $errors['general'] = 'Failed to update admin: ' . $e->getMessage();
        }
    }
}

// Fetch all admins
$admins = $db->query("SELECT * FROM `user` WHERE `role` = 'Admin' ORDER BY `id` ASC")->fetchAll();

$title = 'Administrator Accounts';
require_once __DIR__ . '/../_head.php';
?>

<div class="container">
    <!-- Header -->
    <div class="admin-header-panel">
        <div>
            <h1 style="font-size:24px; font-weight:800; color:var(--dark);">Administrator Accounts</h1>
            <p style="font-size:14px; color:var(--gray); margin-top:4px;">
                Manage administrative credentials, privilege levels, and system safeguards.
            </p>
        </div>
        <div>
            <a href="#add-admin-panel" class="btn btn-primary" style="height:40px; display:inline-flex; align-items:center; gap:6px;">
                + Add Administrator
            </a>
        </div>
    </div>

    <!-- Active Safeguards Notification Box -->
    <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:var(--radius); padding:16px 20px; margin-bottom:24px; font-size:13px; color:#1e40af;">
        <strong>🛡️ Administrative Security Safeguards Enforced:</strong>
        <ul style="margin:6px 0 0 20px; padding:0; line-height:1.6;">
            <li>Admins cannot block, demote, or delete their own active account.</li>
            <li>The last remaining active Administrator can never be disabled or deleted, ensuring continuous system access.</li>
        </ul>
    </div>

    <!-- Edit Admin Panel (Conditionally rendered when ?edit=ID) -->
    <?php if ($edit_admin): ?>
        <div style="background:#fff; border:2px solid var(--primary); border-radius:var(--radius); padding:24px; margin-bottom:28px; box-shadow:0 4px 12px rgba(37,99,235,0.08);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                <h2 style="font-size:18px; font-weight:800; color:var(--dark); margin:0;">
                    Edit Administrator: <?= encode($edit_admin->name) ?>
                    <?php if ($edit_admin->id === $current_admin->id): ?>
                        <span style="font-size:12px; color:var(--primary); font-weight:600;">(Your Account)</span>
                    <?php endif; ?>
                </h2>
                <a href="<?= base_url('admin/admins.php') ?>" class="btn btn-outline-sm">&times; Cancel Edit</a>
            </div>

            <form action="<?= base_url('admin/admins.php?edit=' . $edit_admin->id) ?>" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit">

                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin-bottom:16px;">
                    <div>
                        <label class="form-label">Email (Read-Only)</label>
                        <input type="text" value="<?= encode($edit_admin->email) ?>" disabled class="form-control" style="background:#f1f5f9;">
                    </div>
                    <div>
                        <label for="edit_name" class="form-label">Full Name <span style="color:var(--error);">*</span></label>
                        <input type="text" name="edit_name" id="edit_name" value="<?= encode($edit_name) ?>" required class="form-control">
                        <?php if (!empty($errors['edit_name'])): ?>
                            <div style="color:var(--error); font-size:12px; margin-top:4px;"><?= encode($errors['edit_name']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label for="edit_phone" class="form-label">Phone Number</label>
                        <input type="text" name="edit_phone" id="edit_phone" value="<?= encode($edit_phone) ?>" class="form-control">
                        <?php if (!empty($errors['edit_phone'])): ?>
                            <div style="color:var(--error); font-size:12px; margin-top:4px;"><?= encode($errors['edit_phone']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label for="edit_status" class="form-label">Account Status</label>
                        <select name="edit_status" id="edit_status" class="form-select" <?= ($edit_admin->id === $current_admin->id) ? 'disabled' : '' ?>>
                            <option value="Active" <?= $edit_status === 'Active' ? 'selected' : '' ?>>Active</option>
                            <option value="Blocked" <?= $edit_status === 'Blocked' ? 'selected' : '' ?>>Blocked</option>
                        </select>
                        <?php if ($edit_admin->id === $current_admin->id): ?>
                            <input type="hidden" name="edit_status" value="Active">
                            <span style="font-size:11px; color:var(--gray);">You cannot alter your own status.</span>
                        <?php endif; ?>
                        <?php if (!empty($errors['edit_status'])): ?>
                            <div style="color:var(--error); font-size:12px; margin-top:4px;"><?= encode($errors['edit_status']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="margin-bottom:20px;">
                    <label for="edit_password" class="form-label">Change Password (Leave blank to keep existing)</label>
                    <input type="password" name="edit_password" id="edit_password" class="form-control" placeholder="Enter new strong password if updating">
                    <span style="font-size:12px; color:var(--gray);">Min 8 chars with uppercase, lowercase, and digit.</span>
                    <?php if (!empty($errors['edit_password'])): ?>
                        <div style="color:var(--error); font-size:12px; margin-top:4px;"><?= encode($errors['edit_password']) ?></div>
                    <?php endif; ?>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:10px;">
                    <a href="<?= base_url('admin/admins.php') ?>" class="btn btn-outline">Cancel</a>
                    <button type="submit" class="btn btn-primary">Save Administrator</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <!-- Administrators Table -->
    <div class="table-responsive" style="margin-bottom:32px;">
        <table class="table-custom">
            <thead>
                <tr>
                    <th>Administrator</th>
                    <th>Email Address</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th style="text-align:right; width:190px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($admins as $a): ?>
                    <?php $is_self = ($a->id === $current_admin->id); ?>
                    <tr>
                        <td>
                            <div style="font-weight:700; color:var(--dark);">
                                <?= encode($a->name) ?>
                                <?php if ($is_self): ?>
                                    <span class="role-pill pill-member" style="background:#eff6ff; color:#2563eb; font-size:10px; margin-left:4px;">You</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?= encode($a->email) ?></td>
                        <td><?= encode($a->phone ?? '-') ?></td>
                        <td>
                            <span class="role-pill <?= $a->status === 'Active' ? 'pill-member' : 'pill-admin' ?>" style="<?= $a->status === 'Active' ? 'background:#dcfce7; color:#15803d;' : 'background:#fee2e2; color:#b91c1c;' ?>">
                                <?= encode($a->status) ?>
                            </span>
                        </td>
                        <td><?= date('d M Y', strtotime($a->created_at)) ?></td>
                        <td style="text-align:right;">
                            <div style="display:inline-flex; gap:6px;">
                                <a href="<?= base_url('admin/admins.php?edit=' . (int)$a->id) ?>" class="btn btn-outline-sm" title="Edit Admin">
                                    Edit
                                </a>

                                <?php if (!$is_self): ?>
                                    <!-- Toggle Block/Unblock Form -->
                                    <form action="<?= base_url('admin/admins.php') ?>" method="POST" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="id" value="<?= (int)$a->id ?>">
                                        <button type="submit" class="btn btn-outline-sm" data-confirm="Are you sure you want to change status for '<?= encode($a->name) ?>'?">
                                            <?= $a->status === 'Active' ? 'Block' : 'Unblock' ?>
                                        </button>
                                    </form>

                                    <!-- Delete Form -->
                                    <form action="<?= base_url('admin/admins.php') ?>" method="POST" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int)$a->id ?>">
                                        <button type="submit" class="btn btn-outline-sm" style="color:var(--error); border-color:#fca5a5;" data-confirm="Permanently delete admin account '<?= encode($a->name) ?>'? This cannot be undone.">
                                            Delete
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <button type="button" class="btn btn-outline-sm" style="opacity:0.4; cursor:not-allowed;" title="You cannot block or delete your own admin account.">
                                        Protected
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Add Admin Panel -->
    <div id="add-admin-panel" style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:24px;">
        <h2 style="font-size:18px; font-weight:800; color:var(--dark); margin-bottom:4px;">Add New Administrator</h2>
        <p style="font-size:13px; color:var(--gray); margin-bottom:20px;">
            Grant full administrative privileges. New admins are automatically marked with verified emails.
        </p>

        <form action="<?= base_url('admin/admins.php') ?>" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin-bottom:16px;">
                <div>
                    <label for="add_name" class="form-label">Full Name <span style="color:var(--error);">*</span></label>
                    <input type="text" name="add_name" id="add_name" value="<?= encode($add_name) ?>" required class="form-control" placeholder="e.g. John Doe">
                    <?php if (!empty($errors['add_name'])): ?>
                        <div style="color:var(--error); font-size:12px; margin-top:4px;"><?= encode($errors['add_name']) ?></div>
                    <?php endif; ?>
                </div>

                <div>
                    <label for="add_email" class="form-label">Email Address <span style="color:var(--error);">*</span></label>
                    <input type="email" name="add_email" id="add_email" value="<?= encode($add_email) ?>" required class="form-control" placeholder="john@sumthin.com">
                    <?php if (!empty($errors['add_email'])): ?>
                        <div style="color:var(--error); font-size:12px; margin-top:4px;"><?= encode($errors['add_email']) ?></div>
                    <?php endif; ?>
                </div>

                <div>
                    <label for="add_phone" class="form-label">Phone Number</label>
                    <input type="text" name="add_phone" id="add_phone" value="<?= encode($add_phone) ?>" class="form-control" placeholder="e.g. 012-3456789">
                    <?php if (!empty($errors['add_phone'])): ?>
                        <div style="color:var(--error); font-size:12px; margin-top:4px;"><?= encode($errors['add_phone']) ?></div>
                    <?php endif; ?>
                </div>

                <div>
                    <label for="add_password" class="form-label">Password <span style="color:var(--error);">*</span></label>
                    <input type="password" name="add_password" id="add_password" required class="form-control" placeholder="Min 8 chars (A-Z, a-z, 0-9)">
                    <?php if (!empty($errors['add_password'])): ?>
                        <div style="color:var(--error); font-size:12px; margin-top:4px;"><?= encode($errors['add_password']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end;">
                <button type="submit" class="btn btn-primary" style="height:40px; padding:0 24px;">
                    Create Administrator
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../_foot.php'; ?>
