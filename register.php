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

    $name             = req('name');
    $email            = req('email');
    $phone            = req('phone');
    $password         = req('password');
    $confirm_password = req('confirm_password');

    // 2. Server-side Input Validation
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

    // 3. Database Insertion if Validation Passes
    if (empty($errors)) {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $db->prepare('INSERT INTO `user` (`name`, `email`, `phone`, `password_hash`, `role`, `status`) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $name,
            $email,
            $phone,
            $password_hash,
            'Member',
            'Active'
        ]);

        // Audit log welcome notice
        log_email($email, 'Welcome to sumthin!', "Hello {$name}, your member account is ready. Start shopping today!");

        set_flash('success', 'Registration successful! You can now sign in with your credentials.');
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

            <!-- Submit Button -->
            <button type="submit" class="btn btn-primary btn-block" style="margin-top: 10px;">Register Account</button>
        </form>

        <div class="auth-footer">
            Already have an account? <a href="<?= base_url('login.php') ?>" class="form-link">Sign in here</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/_foot.php'; ?>
