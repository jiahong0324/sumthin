<?php
// ============================================================================
// File: register.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Security (Member Registration)
// Description: Member account registration with comprehensive server-side
//              validation, password strength rules, and unique email check.
// ============================================================================
require_once __DIR__ . '/_base.php';

// Logged-in users should not register a new account while signed in
guest_only();

$name  = '';
$email = '';
$phone = '';

if (is_post()) {
    // 1. Verify CSRF Token
    verify_csrf();

    // 2. Cloudflare Turnstile Captcha Verification (if enabled)
    if (!empty($config['captcha']['enabled']) && !captcha_verify()) {
        $errors['captcha'] = 'Please complete the captcha.';
    }

    $name             = req('name');
    $email            = req('email');
    $phone            = req('phone');
    $password         = req('password');
    $confirm_password = req('confirm_password');

    // 3. Server-side Input Validation
    // Name validation
    if ($err = validate_name($name, 3, 100)) {
        $errors['name'] = $err;
    }

    // Email validation
    if ($email === '') {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please provide a valid email format (e.g. user@domain.com).';
    } else {
        // Check email uniqueness in database
        $stmt = $db->prepare('SELECT COUNT(*) FROM `user` WHERE `email` = ?');
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() > 0) {
            $errors['email'] = 'This email address is already registered. Please sign in or use another email.';
        }
    }

    // Phone validation
    if ($err = validate_phone($phone)) {
        $errors['phone'] = $err;
    }

    // Password validation
    if ($err = validate_password($password)) {
        $errors['password'] = $err;
    }

    // Confirm password validation
    if ($confirm_password === '') {
        $errors['confirm_password'] = 'Please confirm your password.';
    } elseif ($password !== $confirm_password) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    // 4. Database Insertion if Validation Passes
    if (empty($errors)) {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        // Account is created with email_verified_at = NULL
        $stmt = $db->prepare('INSERT INTO `user` (`name`, `email`, `phone`, `password_hash`, `role`, `status`, `email_verified_at`) VALUES (?, ?, ?, ?, ?, ?, NULL)');
        $stmt->execute([
            $name,
            $email,
            $phone ?: null,
            $password_hash,
            'Member',
            'Active'
        ]);
        $new_user_id = (int)$db->lastInsertId();

        // Generate 64-character cryptographic token
        $raw_token   = bin2hex(random_bytes(32));
        $token_hash  = hash('sha256', $raw_token);
        $expires_at  = date('Y-m-d H:i:s', time() + (24 * 3600)); // 24 hours validity

        // Store hash in email_verification table
        $v_stmt = $db->prepare('INSERT INTO `email_verification` (`user_id`, `token_hash`, `expires_at`, `created_at`) VALUES (?, ?, ?, NOW())');
        $v_stmt->execute([$new_user_id, $token_hash, $expires_at]);

        // Construct absolute verification link from $config['app']['url']
        $app_url    = rtrim($config['app']['url'] ?? ('http://localhost' . ($config['app']['base_url'] ?? '/sumthin')), '/');
        $verify_url = $app_url . '/verify_email.php?token=' . urlencode($raw_token);

        // Compose HTML email body
        $html_body = "<p>Hello <strong>" . encode($name) . "</strong>,</p>"
                   . "<p>Thank you for creating an account with sumthin! Please verify your email address to activate your account and start shopping:</p>"
                   . "<p style='text-align:center; margin:26px 0;'>"
                   . "<a href='" . encode($verify_url) . "' style='background-color:#2563eb; color:#ffffff; padding:12px 24px; text-decoration:none; border-radius:6px; font-weight:700; display:inline-block; font-size:14px;'>"
                   . "Verify My Email Address &rarr;"
                   . "</a>"
                   . "</p>"
                   . "<p style='font-size:12px; color:#64748b;'>This link is valid for 24 hours. If you did not create an account on sumthin, please disregard this email.</p>"
                   . "<p style='font-size:12px; color:#64748b; word-break:break-all;'>Direct link: <a href='" . encode($verify_url) . "'>" . encode($verify_url) . "</a></p>";

        // Send email via send_mail() using 'auth' classification
        send_mail($email, $name, 'Verify Your sumthin Account', $html_body, 'auth');

        // Flash message and redirect to login (Do not auto-login)
        set_flash('success', 'Account created. Please check your email to verify your account.');
        redirect(base_url('login.php'));
    }
}

$title = 'Create Account';
require_once __DIR__ . '/_head.php';
?>

<div class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-header">
            <h1 class="auth-title">Create Account</h1>
            <p class="auth-subtitle">Join sumthin for exclusive gadget deals and order tracking</p>
        </div>

        <form action="<?= base_url('register.php') ?>" method="POST" novalidate>
            <!-- CSRF Field -->
            <?= csrf_field() ?>

            <!-- Full Name -->
            <div class="form-group">
                <label for="name" class="form-label">Full Name</label>
                <?= html_text('name', $name, [
                    'placeholder' => 'e.g. Jason Wong',
                    'autocomplete' => 'name'
                ]) ?>
                <?= err('name') ?>
            </div>

            <!-- Email Address -->
            <div class="form-group">
                <label for="email" class="form-label">Email Address</label>
                <?= html_text('email', $email, [
                    'placeholder' => 'e.g. jason@gmail.com',
                    'autocomplete' => 'email'
                ]) ?>
                <?= err('email') ?>
            </div>

            <!-- Phone Number -->
            <div class="form-group">
                <label for="phone" class="form-label">Mobile Phone</label>
                <?= html_text('phone', $phone, [
                    'placeholder' => 'e.g. 012-3456789',
                    'autocomplete' => 'tel'
                ]) ?>
                <?= err('phone') ?>
                <span class="form-help">Format: 01X-XXXXXXX (Malaysian mobile format)</span>
            </div>

            <!-- Password -->
            <div class="form-group">
                <label for="password" class="form-label">Password</label>
                <?= html_password('password', [
                    'placeholder' => 'At least 8 characters (A-Z, a-z, 0-9)',
                    'autocomplete' => 'new-password'
                ]) ?>
                <?= err('password') ?>
            </div>

            <!-- Confirm Password -->
            <div class="form-group">
                <label for="confirm_password" class="form-label">Confirm Password</label>
                <?= html_password('confirm_password', [
                    'placeholder' => 'Re-enter your password',
                    'autocomplete' => 'new-password'
                ]) ?>
                <?= err('confirm_password') ?>
            </div>

            <!-- Cloudflare Turnstile Captcha Widget -->
            <?= captcha_widget() ?>
            <?= err('captcha') ?>

            <!-- Submit Button -->
            <button type="submit" class="btn btn-primary btn-block mt-10">Register Account</button>
        </form>

        <div class="auth-footer">
            Already have an account? <a href="<?= base_url('login.php') ?>" class="form-link">Sign in here</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/_foot.php'; ?>
