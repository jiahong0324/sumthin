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
    if ($name === '') {
        $errors['name'] = 'Full name is required.';
    } elseif (strlen($name) < 3) {
        $errors['name'] = 'Full name must be at least 3 characters.';
    } elseif (strlen($name) > 100) {
        $errors['name'] = 'Full name cannot exceed 100 characters.';
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

    // Phone validation (format: 01X-XXXXXXX or 10-12 digits)
    if ($phone === '') {
        $errors['phone'] = 'Phone number is required.';
    } elseif (!preg_match('/^(\+?6?01)[0-46-9]-*[0-9]{7,8}$/', $phone)) {
        $errors['phone'] = 'Please enter a valid Malaysian mobile number (e.g. 012-3456789).';
    }

    // Password validation (min 8 chars, 1 uppercase, 1 lowercase, 1 number)
    if ($password === '') {
        $errors['password'] = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters long.';
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $errors['password'] = 'Password must contain at least one uppercase letter (A-Z).';
    } elseif (!preg_match('/[a-z]/', $password)) {
        $errors['password'] = 'Password must contain at least one lowercase letter (a-z).';
    } elseif (!preg_match('/[0-9]/', $password)) {
        $errors['password'] = 'Password must contain at least one number (0-9).';
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
