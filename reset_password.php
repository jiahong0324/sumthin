<?php
// ============================================================================
// File: reset_password.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Security (Password Reset Completion)
// Description: Validates the token against the password_reset table and updates
//              the user's hashed password securely with password_hash().
// ============================================================================
require_once __DIR__ . '/_base.php';

guest_only();

$token = req('token');
$valid_token = false;
$reset_record = null;

// Validate token presence and expiration in database
if ($token !== '') {
    $stmt = $db->prepare('SELECT * FROM `password_reset` WHERE `token` = ? AND `expires_at` > NOW() LIMIT 1');
    $stmt->execute([$token]);
    $reset_record = $stmt->fetch();
    if ($reset_record) {
        $valid_token = true;
    }
}

if (is_post() && $valid_token) {
    verify_csrf();

    $password         = req('password');
    $confirm_password = req('confirm_password');

    // Server-side validation
    if ($password === '') {
        $errors['password'] = 'New password is required.';
    } elseif (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters long.';
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $errors['password'] = 'Password must contain at least one uppercase letter (A-Z).';
    } elseif (!preg_match('/[a-z]/', $password)) {
        $errors['password'] = 'Password must contain at least one lowercase letter (a-z).';
    } elseif (!preg_match('/[0-9]/', $password)) {
        $errors['password'] = 'Password must contain at least one number (0-9).';
    }

    if ($confirm_password === '') {
        $errors['confirm_password'] = 'Please confirm your new password.';
    } elseif ($password !== $confirm_password) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        // Hash the new password
        $new_hash = password_hash($password, PASSWORD_DEFAULT);

        // Update user record and clear any failed login lockouts
        $update = $db->prepare('UPDATE `user` SET `password_hash` = ?, `failed_attempts` = 0, `locked_until` = NULL WHERE `email` = ?');
        $update->execute([$new_hash, $reset_record->email]);

        // Delete the used reset token so it cannot be reused
        $del = $db->prepare('DELETE FROM `password_reset` WHERE `token` = ?');
        $del->execute([$token]);

        set_flash('success', 'Your password has been reset successfully! Please sign in with your new password.');
        redirect(base_url('login.php'));
    }
}

$title = 'Set New Password';
require_once __DIR__ . '/_head.php';
?>

<div class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-header">
            <h1 class="auth-title">Set New Password</h1>
            <p class="auth-subtitle">Create a strong new password for your account</p>
        </div>

        <?php if (!$valid_token): ?>
            <!-- Invalid or Expired Token State -->
            <div class="flash-alert flash-error" style="margin-bottom: 24px;">
                <div class="flash-icon">✕</div>
                <div class="flash-message">
                    This password reset link is invalid or has expired (links are valid for 1 hour).
                </div>
            </div>
            <a href="<?= base_url('forgot_password.php') ?>" class="btn btn-primary btn-block">Request a New Link</a>
        <?php else: ?>
            <!-- Valid Token Form -->
            <form action="<?= base_url('reset_password.php?token=' . encode($token)) ?>" method="POST" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= encode($token) ?>">

                <div class="form-group">
                    <label for="password" class="form-label">New Password</label>
                    <?= html_password('password', [
                        'placeholder' => 'At least 8 chars (A-Z, a-z, 0-9)',
                        'autocomplete' => 'new-password'
                    ]) ?>
                    <?= err('password') ?>
                </div>

                <div class="form-group">
                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                    <?= html_password('confirm_password', [
                        'placeholder' => 'Re-enter your new password',
                        'autocomplete' => 'new-password'
                    ]) ?>
                    <?= err('confirm_password') ?>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Update Password</button>
            </form>
        <?php endif; ?>

        <div class="auth-footer">
            <a href="<?= base_url('login.php') ?>" class="form-link">Return to Sign In</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/_foot.php'; ?>
