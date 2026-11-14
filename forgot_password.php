<?php
// ============================================================================
// File: forgot_password.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Security (Password Reset Request)
// Description: Allows users to request a time-limited (1-hour) cryptographic
//              reset token. Displays link for live demonstration & logs to DB.
// ============================================================================
require_once __DIR__ . '/_base.php';

guest_only();

$email = '';
$demo_reset_link = null;

if (is_post()) {
    verify_csrf();

    $email = req('email');

    if ($email === '') {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    if (empty($errors)) {
        // Query user table
        $stmt = $db->prepare('SELECT `id`, `name`, `email` FROM `user` WHERE `email` = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Generate 64-character cryptographic token
            $token = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', time() + 3600); // 1 hour validity

            // Clear previous pending tokens for this email
            $del = $db->prepare('DELETE FROM `password_reset` WHERE `email` = ?');
            $del->execute([$email]);

            // Save new reset token in database
            $ins = $db->prepare('INSERT INTO `password_reset` (`email`, `token`, `expires_at`) VALUES (?, ?, ?)');
            $ins->execute([$email, $token, $expires_at]);

            // Construct full reset URL
            $demo_reset_link = base_url("reset_password.php?token={$token}");

            // Log notification to audit table
            $mail_body = "Hello {$user->name},\n\nA password reset request was initiated for your sumthin account.\n"
                       . "Please use the following secure link to reset your password:\n{$demo_reset_link}\n\n"
                       . "This link will expire in 1 hour.";
            log_email($email, 'Password Reset Request', $mail_body, 'Sent');

            set_flash('info', 'A password reset link has been generated. For your demonstration, see below.');
        } else {
            // Friendly message preventing email enumeration
            set_flash('info', 'If an account exists with that email, a password reset link has been dispatched.');
        }
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

        <?php if ($demo_reset_link): ?>
            <!-- Demo Simulation Box: Displays reset link for seamless offline/localhost demonstration -->
            <div style="background-color:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:16px; margin-bottom:20px;">
                <p style="font-size:13px; font-weight:700; color:#1e40af; margin-bottom:6px;">
                    ✉️ Demo Email Simulation (Logged to database `email_log`):
                </p>
                <p style="font-size:12px; color:#1e3a8a; word-break:break-all; margin-bottom:10px;">
                    <a href="<?= encode($demo_reset_link) ?>" class="form-link"><strong><?= encode($demo_reset_link) ?></strong></a>
                </p>
                <a href="<?= encode($demo_reset_link) ?>" class="btn btn-primary-sm">Click to Reset Password Now &rarr;</a>
            </div>
        <?php endif; ?>

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

            <button type="submit" class="btn btn-primary btn-block">Generate Reset Link</button>
        </form>

        <div class="auth-footer">
            Remember your password? <a href="<?= base_url('login.php') ?>" class="form-link">Back to Sign In</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/_foot.php'; ?>
