<?php
// ============================================================================
// File: resend_verification.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Security (Resend Email Verification Link)
// Description: Allows unverified members to request a fresh verification link.
//              Enforces POST + CSRF, max 3 requests per hour per user, deletes
//              older tokens, and returns a generic response to prevent enumeration.
// ============================================================================
require_once __DIR__ . '/_base.php';

guest_only();

$email = req('email');

if (is_post()) {
    // 1. Verify CSRF Token
    verify_csrf();

    $email = req('email');

    // 2. Server-side Validation
    if ($email === '') {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    // 3. Process Resend if Validation Passes
    if (empty($errors)) {
        // Query user table
        $stmt = $db->prepare('SELECT `id`, `name`, `email`, `role`, `status`, `email_verified_at` FROM `user` WHERE `email` = ? LIMIT 1');
        $stmt->execute([$email]);
        $target_user = $stmt->fetch();

        // Only send if user exists, is a Member, and is not already verified
        if ($target_user && $target_user->role === 'Member' && empty($target_user->email_verified_at)) {
            // Rate limiting: Max 3 verification emails per hour per user
            $rl_stmt = $db->prepare("
                SELECT COUNT(*) FROM `email_log` 
                WHERE `recipient` = ? AND `type` = 'auth' AND `subject` LIKE '%Verify%' 
                  AND `sent_at` >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $rl_stmt->execute([$email]);
            $recent_count = (int)$rl_stmt->fetchColumn();

            if ($recent_count < 3) {
                // Delete older tokens for this user
                $del = $db->prepare('DELETE FROM `email_verification` WHERE `user_id` = ?');
                $del->execute([$target_user->id]);

                // Generate fresh 64-char cryptographic token
                $raw_token   = bin2hex(random_bytes(32));
                $token_hash  = hash('sha256', $raw_token);
                $expires_at  = date('Y-m-d H:i:s', time() + (24 * 3600)); // 24 hours validity

                $ins = $db->prepare('INSERT INTO `email_verification` (`user_id`, `token_hash`, `expires_at`, `created_at`) VALUES (?, ?, ?, NOW())');
                $ins->execute([$target_user->id, $token_hash, $expires_at]);

                // Construct verification link from $config['app']['url']
                $app_url    = rtrim($config['app']['url'] ?? ('http://localhost' . ($config['app']['base_url'] ?? '/sumthin')), '/');
                $verify_url = $app_url . '/verify_email.php?token=' . urlencode($raw_token);

                $html_body = "<p>Hello <strong>" . encode($target_user->name) . "</strong>,</p>"
                           . "<p>You requested a new verification link for your sumthin account. Please click the button below to verify your email address:</p>"
                           . "<p style='text-align:center; margin:26px 0;'>"
                           . "<a href='" . encode($verify_url) . "' style='background-color:#2563eb; color:#ffffff; padding:12px 24px; text-decoration:none; border-radius:6px; font-weight:700; display:inline-block; font-size:14px;'>"
                           . "Verify My Email Address &rarr;"
                           . "</a>"
                           . "</p>"
                           . "<p style='font-size:12px; color:#64748b;'>This link is valid for 24 hours. If you did not request this, you can safely ignore this email.</p>"
                           . "<p style='font-size:12px; color:#64748b; word-break:break-all;'>Direct link: <a href='" . encode($verify_url) . "'>" . encode($verify_url) . "</a></p>";

                send_mail($email, $target_user->name, 'Verify Your sumthin Account', $html_body, 'auth');
            }
        }

        // Always the same generic response to prevent user enumeration
        set_flash('info', 'If an unverified account exists with that email address, a new verification link has been dispatched to your inbox.');
        redirect(base_url('resend_verification.php'));
    }
}

$title = 'Resend Verification Email';
require_once __DIR__ . '/_head.php';
?>

<div class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-header">
            <h1 class="auth-title">Resend Verification</h1>
            <p class="auth-subtitle">Enter your registered email address to receive a new verification link</p>
        </div>

        <form action="<?= base_url('resend_verification.php') ?>" method="POST" novalidate>
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="email" class="form-label">Registered Email Address</label>
                <?= html_text('email', $email, [
                    'placeholder' => 'e.g. member@gmail.com',
                    'autocomplete' => 'email'
                ]) ?>
                <?= err('email') ?>
                <span class="form-help">Verification links expire after 24 hours. Max 3 requests per hour.</span>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Resend Verification Link</button>
        </form>

        <div class="auth-footer">
            Remembered your credentials? <a href="<?= base_url('login.php') ?>" class="form-link">Sign in here</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/_foot.php'; ?>
