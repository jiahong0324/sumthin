<?php
// ============================================================================
// File: admin/member_edit.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Member Maintenance (Edit Member Profile)
// Description: Admin editing of member details: name, phone, avatar photo, and
//              account status (Active / Blocked). Explicitly excludes email & password.
// ============================================================================
require_once __DIR__ . '/../_base.php';

// Enforce Admin authorization
auth('Admin');

$id = (int)req('id');
if ($id <= 0) {
    set_flash('error', 'Invalid member ID.');
    redirect(base_url('admin/members.php'));
}

// Fetch member record (Strictly Members only)
$stmt = $db->prepare("SELECT * FROM `user` WHERE `id` = ? AND `role` = 'Member'");
$stmt->execute([$id]);
$member = $stmt->fetch();

if (!$member) {
    set_flash('error', 'Member account not found.');
    redirect(base_url('admin/members.php'));
}

$errors = [];
$name   = trim(req('name', $member->name));
$phone  = trim(req('phone', $member->phone ?? ''));
$status = req('status', $member->status);

if (is_post()) {
    verify_csrf();

    // 1. Name validation
    if ($name === '') {
        $errors['name'] = 'Full name is required.';
    } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
        $errors['name'] = 'Full name must be between 2 and 100 characters.';
    }

    // 2. Phone validation (optional)
    if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) {
        $errors['phone'] = 'Please enter a valid phone number (7 to 20 digits).';
    }

    // 3. Status validation
    if (!in_array($status, ['Active', 'Blocked'], true)) {
        $errors['status'] = 'Invalid status selected.';
    }

    // 4. Photo upload (Optional)
    $new_photo = null;
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $upload_err = null;
        $new_photo = save_uploaded_image('photo', 'members', 'member_' . $id . '_', $upload_err, 2);
        if ($upload_err) {
            $errors['photo'] = $upload_err;
        }
    }

    if (empty($errors)) {
        try {
            $photo_to_save = $new_photo ?? $member->photo;

            $upd = $db->prepare('
                UPDATE `user` SET
                    `name`   = ?,
                    `phone`  = ?,
                    `photo`  = ?,
                    `status` = ?
                WHERE `id` = ? AND `role` = \'Member\'
            ');
            $upd->execute([
                $name,
                $phone !== '' ? $phone : null,
                $photo_to_save,
                $status,
                $id
            ]);

            // If a new photo was uploaded and an old custom photo existed, clean up old file from disk
            if ($new_photo && $member->photo && $member->photo !== $new_photo) {
                delete_uploaded_image('members', $member->photo);
            }

            set_flash('success', "Member account '{$name}' was updated successfully.");
            redirect(base_url('admin/member_detail.php?id=' . $id));
        } catch (PDOException $e) {
            $errors['general'] = 'Failed to update member account: ' . $e->getMessage();
        }
    }
}

$title = 'Edit Member: ' . $member->name;
require_once __DIR__ . '/../_head.php';
?>

<div class="container" style="max-width:680px; margin:0 auto;">
    <div style="margin-bottom:16px;">
        <a href="<?= base_url('admin/member_detail.php?id=' . $id) ?>" class="btn btn-outline-sm" style="display:inline-flex; align-items:center; gap:6px;">
            &larr; Back to Member Details
        </a>
    </div>

    <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:28px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:1px solid var(--border); padding-bottom:16px;">
            <div>
                <h1 style="font-size:22px; font-weight:800; color:var(--dark); margin:0;">Edit Member Account</h1>
                <p style="font-size:13px; color:var(--gray); margin:4px 0 0 0;">
                    Update personal profile, avatar photo, and account standing.
                </p>
            </div>
            <div style="width:48px; height:48px; border-radius:50%; overflow:hidden; border:2px solid var(--border); flex-shrink:0;">
                <img src="<?= base_url('uploads/members/' . encode($member->photo ?? 'default_user.png')) ?>" 
                     alt="<?= encode($member->name) ?>" 
                     style="width:100%; height:100%; object-fit:cover;">
            </div>
        </div>

        <?php if (!empty($errors['general'])): ?>
            <div style="background:#fee2e2; border:1px solid #fecaca; color:#b91c1c; border-radius:8px; padding:12px 16px; margin-bottom:20px; font-size:14px;">
                <?= encode($errors['general']) ?>
            </div>
        <?php endif; ?>

        <form action="<?= base_url('admin/member_edit.php?id=' . $id) ?>" method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>

            <!-- Email (Read-Only) -->
            <div style="margin-bottom:20px;">
                <label class="form-label">Email Address (Read-Only)</label>
                <input type="email" value="<?= encode($member->email) ?>" disabled class="form-control" style="background:#f1f5f9; cursor:not-allowed;">
                <span style="font-size:12px; color:var(--gray);">Per security policies, member email addresses cannot be modified by administrators.</span>
            </div>

            <!-- Full Name -->
            <div style="margin-bottom:20px;">
                <label for="name" class="form-label">Full Name <span style="color:var(--error);">*</span></label>
                <input type="text" name="name" id="name" value="<?= encode($name) ?>" required class="form-control" placeholder="e.g. Alice Tan">
                <?php if (!empty($errors['name'])): ?>
                    <div style="color:var(--error); font-size:12px; margin-top:4px;"><?= encode($errors['name']) ?></div>
                <?php endif; ?>
            </div>

            <!-- Phone Number -->
            <div style="margin-bottom:20px;">
                <label for="phone" class="form-label">Phone Number</label>
                <input type="text" name="phone" id="phone" value="<?= encode($phone) ?>" class="form-control" placeholder="e.g. 012-3456789">
                <?php if (!empty($errors['phone'])): ?>
                    <div style="color:var(--error); font-size:12px; margin-top:4px;"><?= encode($errors['phone']) ?></div>
                <?php endif; ?>
            </div>

            <!-- Account Status -->
            <div style="margin-bottom:20px;">
                <label for="status" class="form-label">Account Status <span style="color:var(--error);">*</span></label>
                <select name="status" id="status" class="form-select">
                    <option value="Active" <?= $status === 'Active' ? 'selected' : '' ?>>Active (Normal access)</option>
                    <option value="Blocked" <?= $status === 'Blocked' ? 'selected' : '' ?>>Blocked (Suspended login access)</option>
                </select>
                <?php if (!empty($errors['status'])): ?>
                    <div style="color:var(--error); font-size:12px; margin-top:4px;"><?= encode($errors['status']) ?></div>
                <?php endif; ?>
            </div>

            <!-- Avatar Photo Upload -->
            <div style="margin-bottom:24px;">
                <label for="photo" class="form-label">Update Profile Photo</label>
                <input type="file" name="photo" id="photo" accept="image/jpeg,image/png,image/webp" class="form-control">
                <span style="font-size:12px; color:var(--gray);">Optional. Supported formats: JPG, PNG, WebP (Max 2MB).</span>
                <?php if (!empty($errors['photo'])): ?>
                    <div style="color:var(--error); font-size:12px; margin-top:4px;"><?= encode($errors['photo']) ?></div>
                <?php endif; ?>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px;">
                <a href="<?= base_url('admin/member_detail.php?id=' . $id) ?>" class="btn btn-outline">Cancel</a>
                <button type="submit" class="btn btn-primary" style="padding:10px 24px;">
                    Save Member Changes
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../_foot.php'; ?>
