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

    $email       = req('email');
    $password    = req('password');
    $remember_me = req('remember_me');

    // 2. Server-side validation
    if ($email === '') {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    if ($password === '') {
        $errors['password'] = 'Password is required.';
    }

    // 3. Authenticate if validation passed
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
            // Check temporary brute-force lockout
            elseif ($user->locked_until && strtotime($user->locked_until) > time()) {
                $remaining_mins = ceil((strtotime($user->locked_until) - time()) / 60);
                $errors['auth'] = "Account is temporarily locked due to multiple failed attempts. Please try again in {$remaining_mins} minute(s).";
            }
            // Verify password hash
            elseif (password_verify($password, $user->password_hash)) {
                // Successful Login:
                // a. Prevent session fixation
                session_regenerate_id(true);

                // b. Reset failed login attempts in DB
                reset_login_attempts($user->id);

                // c. Store authenticated user profile in session (excluding sensitive password hash)
                unset($user->password_hash);
                $_SESSION['user'] = $user;

                // d. Handle Remember Me token (optional convenience)
                if ($remember_me) {
                    $token = bin2hex(random_bytes(32));
                    $update_tok = $db->prepare('UPDATE `user` SET `remember_token` = ? WHERE `id` = ?');
                    $update_tok->execute([$token, $user->id]);
                    setcookie('sumthin_remember', $token, [
                        'expires'  => time() + (30 * 86400), // 30 days
                        'path'     => '/',
                        'httponly' => true,
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
            } else {
                // Password incorrect -> record failed attempt
                record_login_attempt($email);
                
                // Check newly updated attempt count
                $check_stmt = $db->prepare('SELECT `failed_attempts` FROM `user` WHERE `id` = ?');
                $check_stmt->execute([$user->id]);
                $fresh = $check_stmt->fetch();
                $max_fails = (int)setting('max_login_fails', (string)$config['app']['max_login_fails']);
                $remaining = max(0, $max_fails - $fresh->failed_attempts);

                if ($remaining === 0) {
                    $errors['auth'] = 'Account locked! 3 consecutive failed login attempts reached. Locked for 15 minutes.';
                } else {
                    $errors['auth'] = "Invalid email or password. ({$remaining} attempt(s) remaining before temporary lockout).";
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
            <div class="flash-alert flash-error" style="margin-bottom: 20px;">
                <div class="flash-icon">✕</div>
                <div class="flash-message"><?= encode($errors['auth']) ?></div>
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
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <label for="password" class="form-label">Password</label>
                    <a href="<?= base_url('forgot_password.php') ?>" class="form-link" style="font-size:12px;">Forgot?</a>
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

            <!-- Submit Button -->
            <button type="submit" class="btn btn-primary btn-block">Sign In</button>
        </form>

        <!-- Quick Demo Credentials for Lecturer Evaluation -->
        <div class="demo-credentials-box">
            <div class="demo-credentials-title">
                <span>🔑</span> Quick Demo Credentials (Click to fill)
            </div>
            <ul class="demo-credentials-list">
                <li>
                    <strong>Admin:</strong> 
                    <a href="#" class="form-link btn-fill-demo" data-email="admin@sumthin.com" data-password="Admin123!">admin@sumthin.com</a>
                    <code>Admin123!</code>
                </li>
                <li>
                    <strong>Member:</strong> 
                    <a href="#" class="form-link btn-fill-demo" data-email="alice@gmail.com" data-password="Member123!">alice@gmail.com</a>
                    <code>Member123!</code>
                </li>
            </ul>
        </div>

        <div class="auth-footer">
            Don't have an account? <a href="<?= base_url('register.php') ?>" class="form-link">Register here</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/_foot.php'; ?>
