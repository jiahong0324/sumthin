<?php
// ============================================================================
// File: change_password.php
// Project: sumthin (Tech Gadget Online Store)
// Module: User Profile (Password Update)
// Description: Secure password change requiring current password verification,
//              password complexity validation, and bcrypt re-hashing.
// ============================================================================
require_once __DIR__ . '/_base.php';

auth(['Member', 'Admin']);

$user = current_user();

if (is_post()) {
    verify_csrf();

    $current_password = req('current_password');
    $new_password     = req('new_password');
    $confirm_password = req('confirm_password');

    // 1. Fetch user hash from database
    $stmt = $db->prepare('SELECT `password_hash` FROM `user` WHERE `id` = ?');
    $stmt->execute([$user->id]);
    $u = $stmt->fetch();

    // 2. Validate current password
    if ($current_password === '') {
        $errors['current_password'] = 'Current password is required.';
    } elseif (!password_verify($current_password, $u->password_hash)) {
        $errors['current_password'] = 'The current password you entered is incorrect.';
    }

    // 3. Validate new password using reusable helper
    if ($err = validate_password($new_password)) {
        $errors['new_password'] = $err;
    } elseif ($current_password === $new_password) {
        $errors['new_password'] = 'New password cannot be the same as your current password.';
    }

    if ($confirm_password === '') {
        $errors['confirm_password'] = 'Please confirm your new password.';
    } elseif ($new_password !== $confirm_password) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    // 4. Update if validation passed (revoke remember_token for security)
    if (empty($errors)) {
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $update = $db->prepare('UPDATE `user` SET `password_hash` = ?, `remember_token` = NULL WHERE `id` = ?');
        $update->execute([$new_hash, $user->id]);

        if (isset($_COOKIE['sumthin_remember'])) {
            setcookie('sumthin_remember', '', time() - 3600, '/');
        }

        set_flash('success', 'Your password has been changed successfully!');
        redirect(base_url('profile.php'));
    }
}

$title = 'Change Password';
require_once __DIR__ . '/_head.php';
?>

<div class="container" style="max-width: 550px;">
    <div style="margin-bottom: 24px;">
        <a href="<?= base_url('profile.php') ?>" class="form-link">&larr; Back to Profile</a>
        <h1 style="font-size:24px; font-weight:800; color:var(--dark); margin-top:8px;">Change Password</h1>
        <p style="font-size:14px; color:var(--gray);">Ensure your account uses a strong, unique password.</p>
    </div>

    <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:32px; box-shadow:var(--shadow-sm);">
        <form action="<?= base_url('change_password.php') ?>" method="POST" novalidate>
            <?= csrf_field() ?>

            <!-- Current Password -->
            <div class="form-group">
                <label for="current_password" class="form-label">Current Password *</label>
                <?= html_password('current_password', ['placeholder' => 'Enter your current password']) ?>
                <?= err('current_password') ?>
            </div>

            <!-- New Password -->
            <div class="form-group">
                <label for="new_password" class="form-label">New Password *</label>
                <?= html_password('new_password', ['placeholder' => 'At least 8 chars (A-Z, a-z, 0-9)']) ?>
                <?= err('new_password') ?>
            </div>

            <!-- Confirm New Password -->
            <div class="form-group">
                <label for="confirm_password" class="form-label">Confirm New Password *</label>
                <?= html_password('confirm_password', ['placeholder' => 'Re-enter your new password']) ?>
                <?= err('confirm_password') ?>
            </div>

            <div style="margin-top:24px; border-top:1px solid var(--border); padding-top:20px; display:flex; gap:12px;">
                <button type="submit" class="btn btn-primary">Update Password</button>
                <a href="<?= base_url('profile.php') ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/_foot.php'; ?>
