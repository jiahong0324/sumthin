<?php
// ============================================================================
// File: verify_email.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Security (Email Address Verification)
// Description: Validates cryptographic email verification token from URL query string.
//              If valid & unexpired: marks user.email_verified_at = NOW(), deletes token,
//              and redirects to login.php with success flash.
//              If invalid or expired: presents clear message and link to resend.
// ============================================================================
require_once __DIR__ . '/_base.php';

// Verification is a guest action
guest_only();

$raw_token   = trim((string)req('token'));
$error_title = '';
$error_msg   = '';

if ($raw_token === '') {
    $error_title = 'Missing Verification Token';
    $error_msg   = 'No verification token was supplied. Please click the exact link from your verification email, or request a new one below.';
} else {
    $token_hash = hash('sha256', $raw_token);

    // Look up token record in database
    $stmt = $db->prepare('SELECT * FROM `email_verification` WHERE `token_hash` = ? LIMIT 1');
    $stmt->execute([$token_hash]);
    $record = $stmt->fetch();

    if ($record) {
        // Check if token has expired
        if (strtotime($record->expires_at) < time()) {
            // Delete expired token
            $del = $db->prepare('DELETE FROM `email_verification` WHERE `id` = ?');
            $del->execute([$record->id]);

            $error_title = 'Verification Link Expired';
            $error_msg   = 'This email verification link has expired (links are valid for 24 hours). Please request a fresh verification link below.';
        } else {
            // Valid and unexpired: verify the user
            $upd = $db->prepare('UPDATE `user` SET `email_verified_at` = NOW() WHERE `id` = ?');
            $upd->execute([$record->user_id]);

            // Delete the token immediately to prevent reuse
            $del = $db->prepare('DELETE FROM `email_verification` WHERE `id` = ?');
            $del->execute([$record->id]);

            set_flash('success', 'Your email address has been verified successfully! You may now sign in.');
            redirect(base_url('login.php'));
        }
    } else {
        // Token not found (tampered, invalid, or already used/deleted)
        $error_title = 'Invalid Verification Link';
        $error_msg   = 'The verification link is invalid, tampered with, or has already been used. Please verify your link or request a new verification email.';
    }
}

$title = 'Email Verification';
require_once __DIR__ . '/_head.php';
?>

<div class="auth-wrapper">
    <div class="auth-card" style="text-align: center;">
        <div style="width: 60px; height: 60px; border-radius: 50%; background: #fee2e2; color: #dc2626; display: flex; align-items: center; justify-content: center; font-size: 26px; margin: 0 auto 18px auto;">
            ✕
        </div>

        <h1 class="auth-title" style="margin-bottom: 8px;"><?= encode($error_title) ?></h1>
        <p class="auth-subtitle" style="margin-bottom: 24px; color: var(--gray); font-size: 14px; line-height: 1.6;">
            <?= encode($error_msg) ?>
        </p>

        <div style="display: flex; flex-direction: column; gap: 10px;">
            <a href="<?= base_url('resend_verification.php') ?>" class="btn btn-primary btn-block">
                Resend Verification Email &rarr;
            </a>
            <a href="<?= base_url('login.php') ?>" class="btn btn-outline btn-block">
                Back to Sign In
            </a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/_foot.php'; ?>
