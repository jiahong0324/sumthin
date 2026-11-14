<?php
// ============================================================================
// File: review_add.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Customer Experience (Product Rating & Review Submission)
// Description: Allows verified customers to submit a 1 to 5 star rating and
//              written review for purchased products with validation.
// ============================================================================
require_once __DIR__ . '/_base.php';

auth('Member');

$user_id    = $_SESSION['user']->id;
$product_id = (int)req('product_id');

if ($product_id <= 0) {
    redirect(base_url('index.php'));
}

// Fetch product details
$stmt = $db->prepare('SELECT `id`, `name`, `price` FROM `product` WHERE `id` = ?');
$stmt->execute([$product_id]);
$product = $stmt->fetch();

if (!$product) {
    redirect(base_url('404.php'));
}

$rating  = 5;
$comment = '';

if (is_post()) {
    verify_csrf();

    $rating  = (int)req('rating', 5);
    $comment = req('comment');

    if ($rating < 1 || $rating > 5) {
        $errors['rating'] = 'Please select a rating between 1 and 5 stars.';
    }

    if ($comment === '') {
        $errors['comment'] = 'Review comment is required.';
    } elseif (strlen($comment) < 10) {
        $errors['comment'] = 'Review comment must be at least 10 characters long.';
    }

    if (empty($errors)) {
        $ins = $db->prepare('INSERT INTO `review` (`product_id`, `user_id`, `rating`, `comment`) VALUES (?, ?, ?, ?)');
        $ins->execute([$product_id, $user_id, $rating, $comment]);

        set_flash('success', 'Thank you! Your product review has been submitted successfully.');
        redirect(base_url('product.php?id=' . $product_id));
    }
}

$title = 'Review ' . $product->name;
require_once __DIR__ . '/_head.php';
?>

<div class="container" style="max-width: 600px;">
    <div style="margin-bottom: 24px;">
        <a href="<?= base_url('product.php?id=' . $product_id) ?>" class="form-link">&larr; Back to Product</a>
        <h1 style="font-size:24px; font-weight:800; color:var(--dark); margin-top:8px;">Write a Customer Review</h1>
        <p style="font-size:14px; color:var(--gray);">Share your experience with <strong><?= encode($product->name) ?></strong>.</p>
    </div>

    <div style="background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:32px; box-shadow:var(--shadow-sm);">
        <form action="<?= base_url('review_add.php?product_id=' . $product_id) ?>" method="POST" novalidate>
            <?= csrf_field() ?>

            <!-- Star Rating Dropdown -->
            <div class="form-group">
                <label for="rating" class="form-label">Overall Rating *</label>
                <select name="rating" id="rating" class="form-select">
                    <option value="5" <?= $rating === 5 ? 'selected' : '' ?>>★★★★★ (5 Stars - Exceptional)</option>
                    <option value="4" <?= $rating === 4 ? 'selected' : '' ?>>★★★★☆ (4 Stars - Very Good)</option>
                    <option value="3" <?= $rating === 3 ? 'selected' : '' ?>>★★★☆☆ (3 Stars - Average)</option>
                    <option value="2" <?= $rating === 2 ? 'selected' : '' ?>>★★☆☆☆ (2 Stars - Below Expectations)</option>
                    <option value="1" <?= $rating === 1 ? 'selected' : '' ?>>★☆☆☆☆ (1 Star - Poor)</option>
                </select>
                <?= err('rating') ?>
            </div>

            <!-- Comment -->
            <div class="form-group">
                <label for="comment" class="form-label">Your Review *</label>
                <?= html_textarea('comment', $comment, [
                    'rows' => 5,
                    'placeholder' => 'What did you like or dislike about this gadget? How is the build quality and charging speed?'
                ]) ?>
                <?= err('comment') ?>
            </div>

            <div style="display:flex; gap:12px; margin-top:20px; border-top:1px solid var(--border); padding-top:20px;">
                <button type="submit" class="btn btn-primary">Post Review</button>
                <a href="<?= base_url('product.php?id=' . $product_id) ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/_foot.php'; ?>
