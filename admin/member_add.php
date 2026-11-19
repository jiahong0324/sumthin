<?php
// ============================================================================
// File: admin/member_add.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Member & Admin Maintenance (Create User / Staff)
// Description: Allows site administrators to provision new Member or Admin
//              accounts with validation, password hashing, and optional photo upload.
// ============================================================================
require_once __DIR__ . '/../_base.php';

auth('Admin');

$name     = '';
$email    = '';
$phone    = '';
$role     = 'Member';
$status   = 'Active';

if (is_post()) {
    verify_csrf();

    $name             = req('name');
    $email            = req('email');
    $phone            = req('phone');
    $role             = req('role', 'Member');
    $password         = req('password');
    $confirm_password = req('confirm_password');

    // 1. Text validations using reusable helpers
    if ($err = validate_name($name, 3, 100)) {
        $errors['name'] = $err;
    }

    if ($email === '') {
        $errors['email'] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email format (e.g. user@domain.com).';
    } else {
        $chk = $db->prepare('SELECT COUNT(*) FROM `user` WHERE `email` = ?');
        $chk->execute([$email]);
        if ($chk->fetchColumn() > 0) {
            $errors['email'] = 'This email address is already registered in the system.';
        }
    }

    if ($phone !== '' && ($err = validate_phone($phone))) {
        $errors['phone'] = $err;
    }

    if (!in_array($role, ['Admin', 'Member'], true)) {
        $errors['role'] = 'Invalid account role selected.';
    }

    if ($err = validate_password($password)) {
        $errors['password'] = $err;
    }

    if ($confirm_password === '') {
        $errors['confirm_password'] = 'Please confirm the initial password.';
    } elseif ($password !== $confirm_password) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    // 2. Optional Profile Photo Upload via reusable helper
    $photo_filename = 'default_user.png';
    $photo_err = null;
    $uploaded_photo = save_uploaded_image('photo', 'members', 'user', $photo_err, 2);
    if ($photo_err) {
        $errors['photo'] = $photo_err;
    } elseif ($uploaded_photo) {
        $photo_filename = $uploaded_photo;
    }

    // 3. Database Insertion (Admin-created accounts are verified automatically)
    if (empty($errors)) {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $db->prepare('
            INSERT INTO `user` (`name`, `email`, `phone`, `password_hash`, `role`, `photo`, `status`, `email_verified_at`) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ');
        $stmt->execute([$name, $email, $phone ?: null, $password_hash, $role, $photo_filename, 'Active']);

        log_email($email, "Welcome to sumthin ({$role} Account Created)", "Hello {$name},\n\nAn administrative account has been provisioned for you at sumthin with role: {$role}.\nYou can now log in using your registered email address.");

        set_flash('success', "New {$role} account for '{$name}' was created successfully!");
        redirect(base_url('admin/members.php'));
    }
}

$title = 'Add New Account';
require_once __DIR__ . '/../_head.php';
?>

<div class="container" style="max-width: 700px;">
    <!-- Breadcrumb & Header -->
    <div style="margin-bottom: 24px;">
        <a href="<?= base_url('admin/members.php') ?>" class="form-link">&larr; Back to Directory</a>
        <h1 style="font-size:26px; font-weight:800; color:var(--dark); margin-top:8px;">Provision New User Account</h1>
        <p style="font-size:14px; color:var(--gray);">Create a new Member or Admin account with custom credentials and roles.</p>
    </div>

    <!-- Form Card -->
    <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:32px; box-shadow:var(--shadow-sm);">
        <form action="<?= base_url('admin/member_add.php') ?>" method="POST" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>

            <!-- Role Selection -->
            <div class="form-group">
                <label for="role" class="form-label">Account Role *</label>
                <select name="role" id="role" class="form-select">
                    <option value="Member" <?= $role === 'Member' ? 'selected' : '' ?>>Member (Store Customer)</option>
                    <option value="Admin" <?= $role === 'Admin' ? 'selected' : '' ?>>Admin (Staff & System Administrator)</option>
                </select>
                <?= err('role') ?>
            </div>

            <!-- Full Name -->
            <div class="form-group">
                <label for="name" class="form-label">Full Name *</label>
                <?= html_text('name', $name, ['placeholder' => 'e.g. Sarah Jenkins', 'autocomplete' => 'name']) ?>
                <?= err('name') ?>
            </div>

            <!-- Email & Phone Grid -->
            <div style="display:flex; gap:16px; flex-wrap:wrap;">
                <div class="form-group" style="flex:1; min-width:240px;">
                    <label for="email" class="form-label">Email Address *</label>
                    <?= html_text('email', $email, ['placeholder' => 'e.g. sarah@sumthin.com', 'autocomplete' => 'email']) ?>
                    <?= err('email') ?>
                </div>

                <div class="form-group" style="flex:1; min-width:200px;">
                    <label for="phone" class="form-label">Phone Number</label>
                    <?= html_text('phone', $phone, ['placeholder' => 'e.g. 012-3456789']) ?>
                    <?= err('phone') ?>
                </div>
            </div>

            <!-- Password & Confirm Password Grid -->
            <div style="display:flex; gap:16px; flex-wrap:wrap;">
                <div class="form-group" style="flex:1; min-width:220px;">
                    <label for="password" class="form-label">Initial Password *</label>
                    <?= html_password('password', ['placeholder' => 'At least 8 chars (A-Z, a-z, 0-9)']) ?>
                    <?= err('password') ?>
                </div>

                <div class="form-group" style="flex:1; min-width:220px;">
                    <label for="confirm_password" class="form-label">Confirm Password *</label>
                    <?= html_password('confirm_password', ['placeholder' => 'Re-type password']) ?>
                    <?= err('confirm_password') ?>
                </div>
            </div>

            <!-- Profile Photo Upload -->
            <div class="form-group">
                <label for="photo" class="form-label">Avatar Photo (Optional)</label>
                <?= html_file('photo', 'image/png,image/jpeg,image/webp') ?>
                <?= err('photo') ?>
                <span class="form-help">Accepted formats: PNG, JPG, JPEG, WEBP. Max size: 2 MB.</span>
            </div>

            <!-- Buttons -->
            <div style="display:flex; gap:12px; margin-top:24px; border-top:1px solid var(--border); padding-top:20px;">
                <button type="submit" class="btn btn-primary">Create User Account</button>
                <a href="<?= base_url('admin/members.php') ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../_foot.php'; ?>
