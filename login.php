<?php
// ============================================================================
// File: login.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Security (Login)
// Description: User authentication with role redirection, password verification,
//              brute-force rate limiting (3 failed attempts), and CSRF protection.
// ============================================================================
require_once __DIR__ . '/_base.php';

// Logged-in users should not access the login page
guest_only();

$email = '';

if (is_post()) {
    // 1. Verify CSRF Token
    verify_csrf();

    // 2. Cloudflare Turnstile Captcha Verification (if enabled)
    // Must be verified BEFORE checking credentials and must NOT count as a failed attempt
    if (!empty($config['captcha']['enabled']) && !captcha_verify()) {
        $errors['captcha'] = 'Please complete the captcha.';
    }

    $email       = req('email');
    $password    = req('password');
    $remember_me = req('remember_me');

    // 3. Server-side validation
    if ($email === '') {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    if ($password === '') {
        $errors['password'] = 'Password is required.';
    }

    // 4. Authenticate if validation passed (only if captcha and form fields passed)
    if (empty($errors)) {
        // Query user record by email
        $stmt = $db->prepare('SELECT * FROM `user` WHERE `email` = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Check account status
            if ($user->status === 'Blocked') {
                $errors['auth'] = 'Your account has been deactivated. Please contact support.';
            }
            // Check temporary brute-force lockout (generic message prevents account enumeration)
            elseif ($user->locked_until && strtotime($user->locked_until) > time()) {
                $errors['auth'] = 'Too many failed attempts. Please try again later.';
            }
            // Verify password hash
            elseif (password_verify($password, $user->password_hash)) {
                // Check if Member email is verified (Admins are exempt)
                if ($user->role === 'Member' && empty($user->email_verified_at)) {
                    $resend_url = base_url('resend_verification.php?email=' . urlencode($user->email));
                    $errors['auth'] = 'Please verify your email first. <a href="' . encode($resend_url) . '" class="form-link" style="text-decoration:underline; font-weight:700;">Resend verification email</a>';
                } else {
                    // Successful Login:
                    // a. Prevent session fixation
                    session_regenerate_id(true);

                    // b. Reset failed login attempts in DB
                    reset_login_attempts($user->id);

                    // c. Store authenticated user profile in session (excluding sensitive password hash)
                    unset($user->password_hash);
                    $_SESSION['user'] = $user;

                    // d. Handle Remember Me token (Store SHA-256 hash in DB, raw in cookie)
                    if ($remember_me) {
                        $token      = bin2hex(random_bytes(32));
                        $token_hash = hash('sha256', $token);
                        $update_tok = $db->prepare('UPDATE `user` SET `remember_token` = ? WHERE `id` = ?');
                        $update_tok->execute([$token_hash, $user->id]);

                        $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
                                 || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

                        setcookie('sumthin_remember', $token, [
                            'expires'  => time() + (30 * 86400), // 30 days
                            'path'     => '/',
                            'httponly' => true,
                            'secure'   => $is_https,
                            'samesite' => 'Lax'
                        ]);
                    }

                    set_flash('success', 'Welcome back, ' . $user->name . '!');

                    // e. Redirect to intended destination or role dashboard
                    if (!empty($_SESSION['redirect_after_login'])) {
                        $redirect_target = $_SESSION['redirect_after_login'];
                        unset($_SESSION['redirect_after_login']);
                        redirect($redirect_target);
                    }

                    if ($user->role === 'Admin') {
                        redirect(base_url('admin/index.php'));
                    } else {
                        redirect(base_url('index.php'));
                    }
                }
            } else {
                // Password incorrect -> record failed attempt
                record_login_attempt($email);
                
                // Check if account has been locked by reaching the attempt limit
                $check_stmt = $db->prepare('SELECT `locked_until` FROM `user` WHERE `id` = ?');
                $check_stmt->execute([$user->id]);
                $fresh = $check_stmt->fetch();

                if ($fresh && $fresh->locked_until && strtotime($fresh->locked_until) > time()) {
                    $errors['auth'] = 'Too many failed attempts. Please try again later.';
                } else {
                    $errors['auth'] = 'Invalid email or password.';
                }
            }
        } else {
            // User does not exist (generic error message prevents user enumeration)
            record_login_attempt($email);
            $errors['auth'] = 'Invalid email or password.';
        }
    }
}

$title = 'Sign In';
require_once __DIR__ . '/_head.php';
?>

<div class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-header">
            <h1 class="auth-title">Welcome Back</h1>
            <p class="auth-subtitle">Sign in to your sumthin tech account</p>
        </div>

        <?php if (isset($errors['auth'])): ?>
            <div class="flash-alert flash-error mb-20">
                <div class="flash-icon">✕</div>
                <div class="flash-message"><?= $errors['auth'] ?></div>
            </div>
        <?php endif; ?>

        <form action="<?= base_url('login.php') ?>" method="POST" novalidate>
            <!-- CSRF Protection Field -->
            <?= csrf_field() ?>

            <!-- Email Field -->
            <div class="form-group">
                <label for="email" class="form-label">Email Address</label>
                <?= html_text('email', $email, [
                    'placeholder' => 'e.g. member@gmail.com',
                    'autocomplete' => 'email'
                ]) ?>
                <?= err('email') ?>
            </div>

            <!-- Password Field -->
            <div class="form-group">
                <div class="flex-between">
                    <label for="password" class="form-label">Password</label>
                    <a href="<?= base_url('forgot_password.php') ?>" class="form-link fs-12">Forgot?</a>
                </div>
                <?= html_password('password', [
                    'placeholder' => 'Enter your password',
                    'autocomplete' => 'current-password'
                ]) ?>
                <?= err('password') ?>
            </div>

            <!-- Remember Me -->
            <div class="form-row">
                <?= html_checkbox('remember_me', '1', !empty($remember_me), 'Remember me for 30 days') ?>
            </div>

            <!-- Cloudflare Turnstile Captcha Widget -->
            <?= captcha_widget() ?>
            <?= err('captcha') ?>

            <!-- Submit Button -->
            <button type="submit" class="btn btn-primary btn-block">Sign In</button>
        </form>

        <div class="auth-footer">
            Don't have an account? <a href="<?= base_url('register.php') ?>" class="form-link">Register here</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/_foot.php'; ?>
