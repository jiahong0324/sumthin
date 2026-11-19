<?php
// ============================================================================
// File: admin/email_templates.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Admin Email Template Maintenance
// Description: Manage reusable marketing and communication email templates.
//              Supports listing, creating, editing, and deleting templates.
//              Enforces role auth, CSRF protection, unique template names,
//              and comprehensive server-side field validation.
// ============================================================================
require_once __DIR__ . '/../_base.php';

auth('Admin');

$action = req('action', 'list'); // 'list', 'add', 'edit', 'delete'
$id     = (int)req('id');

$name    = '';
$subject = '';
$body    = '';

// 1. Handle Delete Action
if (is_post() && $action === 'delete') {
    verify_csrf();
    $delete_id = (int)req('id');
    if ($delete_id > 0) {
        $del = $db->prepare('DELETE FROM `email_template` WHERE `id` = ?');
        $del->execute([$delete_id]);
        set_flash('success', 'Email template was deleted successfully.');
    } else {
        set_flash('error', 'Invalid template ID.');
    }
    redirect(base_url('admin/email_templates.php'));
}

// 2. Fetch existing template if in Edit mode
if ($action === 'edit' && $id > 0 && !is_post()) {
    $stmt = $db->prepare('SELECT * FROM `email_template` WHERE `id` = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if ($existing) {
        $name    = $existing->name;
        $subject = $existing->subject;
        $body    = $existing->body;
    } else {
        set_flash('error', 'Email template not found.');
        redirect(base_url('admin/email_templates.php'));
    }
}

// 3. Handle Add / Edit Submission
if (is_post() && in_array($action, ['add', 'edit'], true)) {
    verify_csrf();

    $name    = trim((string)req('name'));
    $subject = trim((string)req('subject'));
    $body    = trim((string)req('body'));

    // Name validation (2 - 100 chars, unique)
    $name_len = mb_strlen($name);
    if ($name === '') {
        $errors['name'] = 'Template name is required.';
    } elseif ($name_len < 2 || $name_len > 100) {
        $errors['name'] = 'Template name must be between 2 and 100 characters.';
    } else {
        if ($action === 'add') {
            $chk = $db->prepare('SELECT COUNT(*) FROM `email_template` WHERE `name` = ?');
            $chk->execute([$name]);
        } else {
            $chk = $db->prepare('SELECT COUNT(*) FROM `email_template` WHERE `name` = ? AND `id` != ?');
            $chk->execute([$name, $id]);
        }
        if ($chk->fetchColumn() > 0) {
            $errors['name'] = 'A template with this name already exists. Names must be unique.';
        }
    }

    // Subject validation (3 - 150 chars)
    $sub_len = mb_strlen($subject);
    if ($subject === '') {
        $errors['subject'] = 'Subject line is required.';
    } elseif ($sub_len < 3 || $sub_len > 150) {
        $errors['subject'] = 'Subject must be between 3 and 150 characters.';
    }

    // Body validation (10 - 5000 chars)
    $body_len = mb_strlen($body);
    if ($body === '') {
        $errors['body'] = 'Template body is required.';
    } elseif ($body_len < 10 || $body_len > 5000) {
        $errors['body'] = 'Body must be between 10 and 5000 characters.';
    }

    if (empty($errors)) {
        if ($action === 'add') {
            $ins = $db->prepare('INSERT INTO `email_template` (`name`, `subject`, `body`, `created_at`) VALUES (?, ?, ?, NOW())');
            $ins->execute([$name, $subject, $body]);
            set_flash('success', "Email template '{$name}' created successfully!");
        } else {
            $upd = $db->prepare('UPDATE `email_template` SET `name` = ?, `subject` = ?, `body` = ? WHERE `id` = ?');
            $upd->execute([$name, $subject, $body, $id]);
            set_flash('success', "Email template '{$name}' updated successfully!");
        }
        redirect(base_url('admin/email_templates.php'));
    }
}

// 4. Fetch all templates for list view
$templates = $db->query('SELECT * FROM `email_template` ORDER BY `name` ASC')->fetchAll();

$title = 'Email Templates';
require_once __DIR__ . '/../_head.php';
?>

<div class="container" style="max-width: 950px;">
    <!-- Breadcrumb & Top Bar -->
    <div style="margin-bottom: 24px;">
        <h1 style="font-size:26px; font-weight:800; color:var(--dark);">Email Templates</h1>
        <p style="font-size:14px; color:var(--gray); margin-top:4px;">
            Predefined notification and marketing templates with <code>{name}</code> and <code>{email}</code> placeholders.
        </p>

        <!-- Navigation Sub-Tabs -->
        <div style="display:flex; gap:10px; margin-top:16px; border-bottom:1px solid var(--border); padding-bottom:12px;">
            <a href="<?= base_url('admin/email_send.php') ?>" class="btn btn-outline-sm">Compose & Send</a>
            <a href="<?= base_url('admin/email_templates.php') ?>" class="btn btn-primary-sm">Email Templates</a>
            <a href="<?= base_url('admin/email_log.php') ?>" class="btn btn-outline-sm">Email Audit Log</a>
        </div>
    </div>

    <!-- Add / Edit Template Form Card -->
    <?php if ($action === 'add' || $action === 'edit'): ?>
        <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:28px; margin-bottom:30px; box-shadow:var(--shadow-sm);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:1px solid var(--border); padding-bottom:12px;">
                <h2 style="font-size:18px; font-weight:800; color:var(--dark); margin:0;">
                    <?= $action === 'edit' ? 'Edit Template #' . (int)$id : 'Create New Email Template' ?>
                </h2>
                <a href="<?= base_url('admin/email_templates.php') ?>" class="btn btn-outline-sm">&times; Close Form</a>
            </div>

            <form action="<?= base_url('admin/email_templates.php?action=' . $action . ($action === 'edit' ? '&id=' . $id : '')) ?>" method="POST" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= encode($action) ?>">
                <?php if ($action === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)$id ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label for="name" class="form-label">Template Identifier Name (Unique)</label>
                    <?= html_text('name', $name, [
                        'placeholder' => 'e.g. Promotion, Order Shipped, Welcome VIP',
                        'maxlength'   => '100',
                    ]) ?>
                    <?= err('name') ?>
                    <span class="form-help">Unique internal name used to identify the template in dropdowns.</span>
                </div>

                <div class="form-group">
                    <label for="subject" class="form-label">Default Subject Line</label>
                    <?= html_text('subject', $subject, [
                        'placeholder' => 'e.g. Special Deal for You, {name}!',
                        'maxlength'   => '150',
                    ]) ?>
                    <?= err('subject') ?>
                    <span class="form-help">Supports {name} and {email} placeholders. (3 - 150 characters)</span>
                </div>

                <div class="form-group">
                    <label for="body" class="form-label">Template Body Content</label>
                    <?= html_textarea('body', $body, [
                        'rows'        => 8,
                        'placeholder' => "Hello {name},\n\nWe appreciate your support...",
                    ]) ?>
                    <?= err('body') ?>
                    <span class="form-help">Supports {name} and {email} placeholders. (10 - 5000 characters)</span>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                    <a href="<?= base_url('admin/email_templates.php') ?>" class="btn btn-outline">Cancel</a>
                    <button type="submit" class="btn btn-primary">
                        <?= $action === 'edit' ? 'Save Changes' : 'Create Template' ?>
                    </button>
                </div>
            </form>
        </div>
    <?php else: ?>
        <div style="display:flex; justify-content:flex-end; margin-bottom:16px;">
            <a href="<?= base_url('admin/email_templates.php?action=add') ?>" class="btn btn-primary-sm">
                + Add New Template
            </a>
        </div>
    <?php endif; ?>

    <!-- Templates Table -->
    <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; box-shadow:var(--shadow-sm);">
        <div class="table-responsive">
            <table class="table-custom">
                <thead>
                    <tr>
                        <th style="width:60px;">ID</th>
                        <th style="width:180px;">Template Name</th>
                        <th>Subject Line</th>
                        <th>Body Snippet</th>
                        <th style="text-align:right; width:150px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($templates)): ?>
                        <tr>
                            <td colspan="5" style="text-align:center; padding:36px; color:var(--gray);">
                                No email templates created yet. Click "+ Add New Template" above.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($templates as $t): ?>
                            <tr>
                                <td>#<?= (int)$t->id ?></td>
                                <td>
                                    <strong><?= encode($t->name) ?></strong>
                                </td>
                                <td>
                                    <div style="font-weight:600; color:var(--dark);"><?= encode($t->subject) ?></div>
                                </td>
                                <td style="font-size:12px; color:var(--gray); max-width:260px;">
                                    <?= encode(mb_substr($t->body, 0, 75)) ?><?= mb_strlen($t->body) > 75 ? '...' : '' ?>
                                </td>
                                <td style="text-align:right;">
                                    <div style="display:inline-flex; gap:6px;">
                                        <a href="<?= base_url('admin/email_templates.php?action=edit&id=' . (int)$t->id) ?>" class="btn btn-outline-sm">
                                            Edit
                                        </a>
                                        <form action="<?= base_url('admin/email_templates.php') ?>" method="POST" style="display:inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$t->id ?>">
                                            <button type="submit" class="btn btn-outline-sm" style="color:var(--error); border-color:#fca5a5;" data-confirm="Are you sure you want to delete template '<?= encode($t->name) ?>'?">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../_foot.php'; ?>
