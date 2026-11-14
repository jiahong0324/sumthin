<?php
// ============================================================================
// File: review_add.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Customer Experience (Verified Purchase Review Submission)
// Description: Restricts review submission strictly to members who have a Delivered
//              order containing the product, once per purchased item.
// ============================================================================
require_once __DIR__ . '/_base.php';

auth('Member');

$user_id    = (int)$_SESSION['user']->id;
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

// Enforce rule: Only buyers with a Delivered order and no existing review can review
$reviewable_order_id = find_reviewable_order($user_id, $product_id);
if (!$reviewable_order_id) {
    set_flash('error', 'You can only review products from delivered orders you have not reviewed yet.');
    redirect(base_url('product.php?id=' . $product_id));
}

$rating  = 5;
$comment = '';

if (is_post()) {
    verify_csrf();

    $raw_rating = req('rating', 5);
    $comment    = req('comment');

    // Validation: rating 1-5 integer, comment 10-500 chars
    if (!filter_var($raw_rating, FILTER_VALIDATE_INT) || (int)$raw_rating < 1 || (int)$raw_rating > 5) {
        $errors['rating'] = 'Please select a valid rating between 1 and 5 stars.';
    } else {
        $rating = (int)$raw_rating;
    }

    if ($comment === '') {
        $errors['comment'] = 'Review comment is required.';
    } elseif (mb_strlen($comment) < 10) {
        $errors['comment'] = 'Review comment must be at least 10 characters long.';
    } elseif (mb_strlen($comment) > 500) {
        $errors['comment'] = 'Review comment cannot exceed 500 characters.';
    }

    if (empty($errors)) {
        try {
            $ins = $db->prepare('INSERT INTO `review` (`product_id`, `user_id`, `order_id`, `rating`, `comment`) VALUES (?, ?, ?, ?, ?)');
            $ins->execute([$product_id, $user_id, $reviewable_order_id, $rating, $comment]);

            set_flash('success', 'Thank you! Your verified purchase review has been submitted successfully.');
            redirect(base_url('product.php?id=' . $product_id));
        } catch (PDOException $e) {
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), '1062')) {
                set_flash('warning', 'You have already submitted a review for this purchase.');
                redirect(base_url('product.php?id=' . $product_id));
            } else {
                $errors['comment'] = app_error_message('Failed to submit review. Please try again.', $e);
            }
        }
    }
}

$title = 'Review ' . $product->name;
require_once __DIR__ . '/_head.php';
?>

<div class="container" style="max-width: 600px;">
    <div style="margin-bottom: 24px;">
        <a href="<?= base_url('product.php?id=' . $product_id) ?>" class="form-link">&larr; Back to Product</a>
        <h1 style="font-size:24px; font-weight:800; color:var(--dark); margin-top:8px;">Write a Customer Review</h1>
        <p style="font-size:14px; color:var(--gray);">Verified purchase from Order #<?= (int)$reviewable_order_id ?> for <strong><?= encode($product->name) ?></strong>.</p>
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
                <label for="comment" class="form-label">Your Review (10 - 500 characters) *</label>
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
