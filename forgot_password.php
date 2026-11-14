<?php
// ============================================================================
// File: forgot_password.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Security (Password Reset Request)
// Description: Allows users to request a cryptographic password reset token.
//              Protects against user enumeration, enforces rate limiting,
//              stores SHA-256 token digests, and dispatches via PHPMailer.
// ============================================================================
require_once __DIR__ . '/_base.php';

guest_only();

$email = '';

if (is_post()) {
    verify_csrf();

    $email = req('email');

    if ($email === '') {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    if (empty($errors)) {
        // 1. Rate Limiting: Max 3 requests per email per hour
        $rate_stmt = $db->prepare('SELECT COUNT(*) FROM `password_reset` WHERE `email` = ? AND `created_at` >= DATE_SUB(NOW(), INTERVAL 1 HOUR)');
        $rate_stmt->execute([$email]);
        $recent_attempts = (int)$rate_stmt->fetchColumn();

        // 2. Query user table (Only Active members can reset passwords)
        $stmt = $db->prepare('SELECT `id`, `name`, `email`, `status` FROM `user` WHERE `email` = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && $user->status === 'Active' && $recent_attempts < 3) {
            // Generate 64-character cryptographic token
            $raw_token   = bin2hex(random_bytes(32));
            $token_hash  = hash('sha256', $raw_token);
            $expires_at  = date('Y-m-d H:i:s', time() + 3600); // 1 hour validity

            // Save SHA-256 hashed token in database
            $ins = $db->prepare('INSERT INTO `password_reset` (`email`, `token`, `expires_at`, `created_at`) VALUES (?, ?, ?, NOW())');
            $ins->execute([$email, $token_hash, $expires_at]);

            // Construct secure reset link with raw unhashed token
            $host_prefix = isset($_SERVER['HTTP_HOST'])
                ? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'])
                : '';
            $reset_url = $host_prefix . base_url("reset_password.php?token={$raw_token}");

            // Compose HTML email body
            $html_body = "<p>Hello <strong>" . encode($user->name) . "</strong>,</p>"
                       . "<p>A password reset request was initiated for your sumthin account. Click the button below to choose a new password:</p>"
                       . "<p style='text-align:center; margin:26px 0;'>"
                       . "<a href='" . encode($reset_url) . "' style='background-color:#2563eb; color:#ffffff; padding:12px 24px; text-decoration:none; border-radius:6px; font-weight:700; display:inline-block; font-size:14px;'>"
                       . "Reset My Password &rarr;"
                       . "</a>"
                       . "</p>"
                       . "<p style='font-size:12px; color:#64748b;'>This link is valid for 1 hour. If you did not request this password reset, please disregard this email.</p>"
                       . "<p style='font-size:12px; color:#64748b; word-break:break-all;'>Direct link: <a href='" . encode($reset_url) . "'>" . encode($reset_url) . "</a></p>";

            // Dispatch email via PHPMailer (Logs to email_log automatically)
            send_mail($email, $user->name, 'Reset Your sumthin Password', $html_body, 'auth');
        }

        // Generic confirmation message to prevent user enumeration
        set_flash('info', 'If an account exists with that email address, a password reset link has been dispatched to your inbox.');
        redirect(base_url('forgot_password.php'));
    }
}

$title = 'Forgot Password';
require_once __DIR__ . '/_head.php';
?>

<div class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-header">
            <h1 class="auth-title">Reset Password</h1>
            <p class="auth-subtitle">Enter your registered email to receive a password reset link</p>
        </div>

        <form action="<?= base_url('forgot_password.php') ?>" method="POST" novalidate>
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="email" class="form-label">Registered Email</label>
                <?= html_text('email', $email, [
                    'placeholder' => 'e.g. member@gmail.com',
                    'autocomplete' => 'email'
                ]) ?>
                <?= err('email') ?>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Send Reset Link</button>
        </form>

        <div class="auth-footer">
            Remember your password? <a href="<?= base_url('login.php') ?>" class="form-link">Back to Sign In</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/_foot.php'; ?>
