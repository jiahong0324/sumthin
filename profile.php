<?php
// ============================================================================
// File: profile.php
// Project: sumthin (Tech Gadget Online Store)
// Module: User Profile (Update Details & Photo Upload)
// Description: Allows authenticated Members and Admins to edit profile details
//              and upload profile photos with secure MIME/extension validation.
// ============================================================================
require_once __DIR__ . '/_base.php';

// Requires logged-in session (Member or Admin)
auth(['Member', 'Admin']);

$user = current_user();

// Fetch fresh user data from database
$stmt = $db->prepare('SELECT * FROM `user` WHERE `id` = ?');
$stmt->execute([$user->id]);
$u = $stmt->fetch();

$name  = $u->name;
$phone = $u->phone ?? '';
$email = $u->email;
$photo = $u->photo ?? 'default_user.png';

if (is_post()) {
    verify_csrf();

    $name  = req('name');
    $phone = req('phone');

    // 1. Validation using reusable helpers
    if ($err = validate_name($name, 3, 100)) {
        $errors['name'] = $err;
    }

    if ($phone !== '' && ($err = validate_phone($phone))) {
        $errors['phone'] = $err;
    }

    // 2. Profile Photo Upload Handling via reusable helper
    $photo_err = null;
    $new_photo_filename = save_uploaded_image('photo', 'members', 'user', $photo_err, 2);
    if ($photo_err) {
        $errors['photo'] = $photo_err;
    }

    // 3. Update Database
    if (empty($errors)) {
        $update_photo = $new_photo_filename ?: $photo;

        $update_stmt = $db->prepare('UPDATE `user` SET `name` = ?, `phone` = ?, `photo` = ? WHERE `id` = ?');
        $update_stmt->execute([$name, $phone, $update_photo, $u->id]);

        // Clean up old custom photo if replaced
        if ($new_photo_filename) {
            delete_uploaded_image('members', $photo);
        }

        // Refresh session user object
        $_SESSION['user']->name = $name;
        $_SESSION['user']->photo = $update_photo;

        set_flash('success', 'Your profile details have been updated successfully!');
        redirect(base_url('profile.php'));
    }
}

$title = 'My Profile';
require_once __DIR__ . '/_head.php';
?>

<div class="container container-profile">
    <!-- Profile Header -->
    <div class="profile-header-card">
        <div class="profile-avatar-wrapper">
            <img src="<?= base_url('uploads/members/' . encode($photo)) ?>" alt="<?= encode($name) ?>" class="profile-avatar-img">
        </div>
        <div>
            <h1 class="profile-name"><?= encode($name) ?></h1>
            <p class="profile-meta"><?= encode($email) ?> &bull; <span class="role-pill <?= is_admin() ? 'pill-admin' : 'pill-member' ?>"><?= encode($u->role) ?></span></p>
            <a href="<?= base_url('change_password.php') ?>" class="btn btn-outline-sm fs-12">Change Password &rarr;</a>
        </div>
    </div>

    <!-- Edit Profile Card -->
    <div class="card-panel-body">
        <h2 class="card-heading-bordered">Edit Personal Details</h2>

        <form action="<?= base_url('profile.php') ?>" method="POST" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>

            <!-- Email (Read-Only) -->
            <div class="form-group">
                <label class="form-label">Email Address (Account Identifier)</label>
                <input type="text" value="<?= encode($email) ?>" class="form-control" disabled>
                <span class="form-help">Email cannot be changed for account security.</span>
            </div>

            <!-- Full Name -->
            <div class="form-group">
                <label for="name" class="form-label">Full Name *</label>
                <?= html_text('name', $name, ['placeholder' => 'Enter your full name']) ?>
                <?= err('name') ?>
            </div>

            <!-- Phone Number -->
            <div class="form-group">
                <label for="phone" class="form-label">Mobile Phone Number</label>
                <?= html_text('phone', $phone, ['placeholder' => 'e.g. 012-3456789']) ?>
                <?= err('phone') ?>
                <span class="form-help">Format: 01X-XXXXXXX (Malaysian phone number)</span>
            </div>

            <!-- Profile Photo Upload -->
            <div class="form-group">
                <label for="photo" class="form-label">Upload New Profile Photo</label>
                <?= html_file('photo', 'image/png,image/jpeg,image/webp') ?>
                <?= err('photo') ?>
                <span class="form-help">Accepted formats: JPG, JPEG, PNG, WEBP. Maximum file size: 2 MB.</span>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save Profile Changes</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/_foot.php'; ?>
