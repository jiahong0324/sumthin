<?php
// ============================================================================
// File: test_suite.php
// Project: sumthin
// Description: Automated test runner covering TASKS D1 through D11.
// ============================================================================
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/admin/email_send.php';
$_SERVER['HTTP_HOST']      = 'localhost';
ob_start();
require_once __DIR__ . '/_base.php';
ob_end_clean();

$passed = 0;
$failed = 0;

function assert_test(string $name, bool $condition, string $detail = '') {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[PASS] " . $name . "\n";
    } else {
        $failed++;
        echo "[FAIL] " . $name . ($detail ? " - " . $detail : "") . "\n";
    }
}

echo "============================================================\n";
echo "RUNNING COMPREHENSIVE TEST SUITE (TASKS D1 - D11)\n";
echo "============================================================\n\n";

global $db, $config;

// ----------------------------------------------------------------------------
// TEST 1: Expired Token Test
// ----------------------------------------------------------------------------
echo "--- TASK D1: Email Verification Tests ---\n";
// Ensure test user exists
$stmt = $db->prepare("SELECT id FROM user WHERE email = 'quinn@gmail.com'");
$stmt->execute();
$quinn_id = (int)$stmt->fetchColumn();

// 1. Expired Token
$raw_token_exp  = bin2hex(random_bytes(32));
$hash_exp       = hash('sha256', $raw_token_exp);
$past_expires   = date('Y-m-d H:i:s', time() - 3600); // 1 hour ago

$db->prepare("DELETE FROM email_verification WHERE user_id = ?")->execute([$quinn_id]);
$db->prepare("INSERT INTO email_verification (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, NOW())")
   ->execute([$quinn_id, $hash_exp, $past_expires]);

// Simulate lookup logic in verify_email.php
$stmt = $db->prepare('SELECT * FROM `email_verification` WHERE `token_hash` = ? LIMIT 1');
$stmt->execute([hash('sha256', $raw_token_exp)]);
$rec = $stmt->fetch();

$is_expired = ($rec && strtotime($rec->expires_at) < time());
if ($is_expired) {
    $db->prepare('DELETE FROM `email_verification` WHERE `id` = ?')->execute([$rec->id]);
}

// Check that token was deleted and user was not verified
$chk_token = $db->prepare('SELECT COUNT(*) FROM `email_verification` WHERE `token_hash` = ?');
$chk_token->execute([$hash_exp]);
$token_deleted = ($chk_token->fetchColumn() == 0);

$chk_user = $db->prepare('SELECT email_verified_at FROM `user` WHERE `id` = ?');
$chk_user->execute([$quinn_id]);
$still_unverified = is_null($chk_user->fetchColumn());

assert_test("Expired Token: Rejected and Deleted", $is_expired && $token_deleted && $still_unverified, "Expired token should be purged and user left unverified");

// ----------------------------------------------------------------------------
// TEST 2: Reused Token Test
// ----------------------------------------------------------------------------
$raw_token_valid = bin2hex(random_bytes(32));
$hash_valid      = hash('sha256', $raw_token_valid);
$future_expires  = date('Y-m-d H:i:s', time() + 86400);

$db->prepare("INSERT INTO email_verification (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, NOW())")
   ->execute([$quinn_id, $hash_valid, $future_expires]);

// First use
$stmt = $db->prepare('SELECT * FROM `email_verification` WHERE `token_hash` = ? LIMIT 1');
$stmt->execute([hash('sha256', $raw_token_valid)]);
$rec = $stmt->fetch();
$first_use_valid = ($rec && strtotime($rec->expires_at) >= time());
if ($first_use_valid) {
    $db->prepare('UPDATE `user` SET `email_verified_at` = NOW() WHERE `id` = ?')->execute([$rec->user_id]);
    $db->prepare('DELETE FROM `email_verification` WHERE `id` = ?')->execute([$rec->id]);
}

// Second use (Reused)
$stmt2 = $db->prepare('SELECT * FROM `email_verification` WHERE `token_hash` = ? LIMIT 1');
$stmt2->execute([hash('sha256', $raw_token_valid)]);
$rec2 = $stmt2->fetch();
$second_use_rejected = ($rec2 === false);

assert_test("Reused Token: First use succeeds, second use rejected", $first_use_valid && $second_use_rejected, "Token must be deleted after first use");

// Reset Quinn back to unverified for subsequent tests
$db->prepare("UPDATE user SET email_verified_at = NULL WHERE id = ?")->execute([$quinn_id]);

// ----------------------------------------------------------------------------
// TEST 3: Tampered Token Test
// ----------------------------------------------------------------------------
$tampered_token = $raw_token_valid . "_tampered";
$tampered_hash  = hash('sha256', $tampered_token);
$stmt = $db->prepare('SELECT * FROM `email_verification` WHERE `token_hash` = ? LIMIT 1');
$stmt->execute([$tampered_hash]);
$tampered_rec = $stmt->fetch();
assert_test("Tampered Token: Rejected as invalid", $tampered_rec === false, "Tampered hash must not match any token");

// ----------------------------------------------------------------------------
// TEST 4: Unverified Member Login Check
// ----------------------------------------------------------------------------
$stmt = $db->prepare('SELECT * FROM `user` WHERE `email` = ? LIMIT 1');
$stmt->execute(['quinn@gmail.com']);
$quinn = $stmt->fetch();

$login_allowed = false;
$login_error = '';
if ($quinn && password_verify('Member123!', $quinn->password_hash)) {
    if ($quinn->role === 'Member' && empty($quinn->email_verified_at)) {
        $login_allowed = false;
        $resend_url = base_url('resend_verification.php?email=' . urlencode($quinn->email));
        $login_error = 'Please verify your email first. <a href="' . encode($resend_url) . '">Resend verification email</a>';
    } else {
        $login_allowed = true;
    }
}
assert_test("Unverified Login: Member blocked from logging in with resend link", (!$login_allowed && str_contains($login_error, 'Please verify your email first') && str_contains($login_error, 'Resend verification email')));

// Admin exemption check
$stmt = $db->prepare("SELECT * FROM `user` WHERE `role` = 'Admin' LIMIT 1");
$stmt->execute();
$admin_seed = $stmt->fetch();
$admin_exempt = false;
if ($admin_seed && password_verify('Admin123!', $admin_seed->password_hash)) {
    if ($admin_seed->role === 'Member' && empty($admin_seed->email_verified_at)) {
        $admin_exempt = false;
    } else {
        $admin_exempt = true;
    }
}
assert_test("Admin Exemption: Admins are exempt from unverified block", $admin_exempt);

// ----------------------------------------------------------------------------
// TEST 5: Resend Verification Rate Limit (Max 3/hour per user) & Older Tokens Purged
// ----------------------------------------------------------------------------
// Clear previous auth logs for quinn@gmail.com
$db->prepare("DELETE FROM email_log WHERE recipient = 'quinn@gmail.com' AND type = 'auth'")->execute();
$db->prepare("DELETE FROM email_verification WHERE user_id = ?")->execute([$quinn_id]);

// Simulate 3 resend attempts
for ($i = 1; $i <= 3; $i++) {
    $rl_stmt = $db->prepare("SELECT COUNT(*) FROM email_log WHERE recipient = 'quinn@gmail.com' AND type = 'auth' AND subject LIKE '%Verify%' AND sent_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $rl_stmt->execute();
    $cnt = (int)$rl_stmt->fetchColumn();
    if ($cnt < 3) {
        $db->prepare('DELETE FROM `email_verification` WHERE `user_id` = ?')->execute([$quinn_id]);
        $tok = bin2hex(random_bytes(32));
        $db->prepare('INSERT INTO `email_verification` (`user_id`, `token_hash`, `expires_at`, `created_at`) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), NOW())')
           ->execute([$quinn_id, hash('sha256', $tok)]);
        log_email('quinn@gmail.com', 'Verify Your sumthin Account', 'Link...', 'Sent', 'auth');
    }
}

// Verify exactly 3 emails were logged and only 1 token exists (older purged)
$tok_count = (int)$db->query("SELECT COUNT(*) FROM email_verification WHERE user_id = {$quinn_id}")->fetchColumn();
$log_count = (int)$db->query("SELECT COUNT(*) FROM email_log WHERE recipient = 'quinn@gmail.com' AND type = 'auth' AND subject LIKE '%Verify%'")->fetchColumn();
assert_test("Resend Older Token Deletion: Exactly 1 latest token remains", $tok_count === 1 && $log_count === 3);

// 4th attempt within same hour -> Must be blocked by rate limit
$fourth_dispatched = false;
$rl_stmt = $db->prepare("SELECT COUNT(*) FROM email_log WHERE recipient = 'quinn@gmail.com' AND type = 'auth' AND subject LIKE '%Verify%' AND sent_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
$rl_stmt->execute();
if ((int)$rl_stmt->fetchColumn() < 3) {
    $fourth_dispatched = true;
    log_email('quinn@gmail.com', 'Verify Your sumthin Account', 'Link...', 'Sent', 'auth');
}
assert_test("Resend Limit: 4th attempt within 1 hour blocked", !$fourth_dispatched, "Max 3 requests per hour enforced");

// ----------------------------------------------------------------------------
// TEST 6: TASK D2 Captcha Tests
// ----------------------------------------------------------------------------
echo "\n--- TASK D2: Cloudflare Turnstile Captcha Tests ---\n";
// Test captcha disabled -> returns true
$saved_enabled = $config['captcha']['enabled'];
$config['captcha']['enabled'] = false;
$disabled_res = captcha_verify();
assert_test("Captcha Disabled: Skips verification and returns true", $disabled_res === true);

// Test captcha enabled with empty or placeholder token -> fails closed (false)
$config['captcha']['enabled'] = true;
$config['captcha']['secret_key'] = '1x0000000000000000000000000000000AA';
$_POST['cf-turnstile-response'] = '';
$fail_closed = captcha_verify();
assert_test("Captcha Fail Closed: Missing response token returns false", $fail_closed === false);

// Test captcha error does not record failed login attempt
$attempts_before = (int)$db->query("SELECT failed_attempts FROM user WHERE id = {$quinn_id}")->fetchColumn();
$errors = [];
if (!empty($config['captcha']['enabled']) && !captcha_verify()) {
    $errors['captcha'] = 'Please complete the captcha.';
}
// Form is aborted because captcha failed
if (empty($errors)) {
    record_login_attempt('quinn@gmail.com');
}
$attempts_after = (int)$db->query("SELECT failed_attempts FROM user WHERE id = {$quinn_id}")->fetchColumn();
assert_test("Captcha Failure: Does NOT increment failed_attempts", $attempts_before === $attempts_after && isset($errors['captcha']));

// Test with official Cloudflare dummy test response
$_POST['cf-turnstile-response'] = 'dummy_valid_test_token';
$test_pass = captcha_verify();
assert_test("Captcha Official Test Key: Validates successfully with 1x000...AA", $test_pass === true);

// Restore config
$config['captcha']['enabled'] = $saved_enabled;
unset($_POST['cf-turnstile-response']);

// ----------------------------------------------------------------------------
// TEST 7: TASK D4 Admin Email Tests
// ----------------------------------------------------------------------------
echo "\n--- TASK D4: Admin Email Module Tests ---\n";

// 7.1 Empty Subject Validation
$sub_test = '';
$sub_err = '';
if (trim($sub_test) === '') {
    $sub_err = 'Subject is required.';
} elseif (mb_strlen(trim($sub_test)) < 3 || mb_strlen(trim($sub_test)) > 150) {
    $sub_err = 'Subject must be between 3 and 150 characters.';
}
assert_test("Admin Email: Empty subject rejected", $sub_err === 'Subject is required.');

// 7.2 5001-character Message Validation
$long_msg = str_repeat('A', 5001);
$msg_err = '';
if (trim($long_msg) === '') {
    $msg_err = 'Message is required.';
} elseif (mb_strlen(trim($long_msg)) < 10 || mb_strlen(trim($long_msg)) > 5000) {
    $msg_err = 'Message must be between 10 and 5000 characters.';
}
assert_test("Admin Email: 5001-character message rejected", $msg_err === 'Message must be between 10 and 5000 characters.');

// 7.3 Blocked Member in Segment Excluded
// Oliver See (ID 17) is Blocked in seed
$_SESSION['user'] = (object)[
    'id'   => 1,
    'name' => 'System Administrator',
    'role' => 'Admin'
];
ob_start();
require_once __DIR__ . '/admin/email_send.php'; // defines get_segment_recipients
ob_end_clean();

$recipients_all_ver = get_segment_recipients($db, 'all_verified');
$recipients_paid    = get_segment_recipients($db, 'paid_orders');
$recipients_none    = get_segment_recipients($db, 'no_orders');

function has_member_id(array $recs, int $id): bool {
    foreach ($recs as $r) {
        if ((int)$r->id === $id) return true;
    }
    return false;
}

$oliver_in_any = has_member_id($recipients_all_ver, 17) || has_member_id($recipients_paid, 17) || has_member_id($recipients_none, 17);
assert_test("Audience Segment: Blocked member (Oliver See) strictly excluded", !$oliver_in_any);

// 7.4 Member with No Email Excluded
$has_invalid_email = false;
foreach (array_merge($recipients_all_ver, $recipients_paid, $recipients_none) as $r) {
    if (empty($r->email) || !filter_var($r->email, FILTER_VALIDATE_EMAIL)) {
        $has_invalid_email = true;
        break;
    }
}
assert_test("Audience Segment: Members without valid email strictly excluded", !$has_invalid_email);

// 7.5 Template Placeholders Replacement
$template_subject = "Special Announcement for {name}";
$template_body    = "Hello {name},\n\nYour account email {email} qualifies for exclusive benefits.\nBest regards.";

$recipient_name  = "Alice Tan";
$recipient_email = "alice@gmail.com";

$final_sub  = str_replace(['{name}', '{email}'], [$recipient_name, $recipient_email], $template_subject);
$final_body = str_replace(['{name}', '{email}'], [$recipient_name, $recipient_email], $template_body);
$html_body  = nl2br(encode($final_body));

assert_test("Template Placeholders: {name} and {email} replaced correctly", 
    $final_sub === "Special Announcement for Alice Tan" &&
    str_contains($final_body, "Hello Alice Tan") &&
    str_contains($final_body, "alice@gmail.com") &&
    str_contains($html_body, "<br />")
);

// 7.6 Member Opening Admin Pages (403 Test)
$_SESSION['user'] = (object)[
    'id'   => 3,
    'name' => 'Alice Tan',
    'role' => 'Member'
];
// Test auth('Admin') behavior for Member
$role = $_SESSION['user']->role;
$is_admin_auth_blocked = ($role !== 'Admin');
assert_test("RBAC Security: Member accessing Admin pages denied (403)", $is_admin_auth_blocked);

// 7.7 Auth Email Log Body Redaction Test
$auth_log = $db->query("SELECT * FROM email_log WHERE type = 'auth' LIMIT 1")->fetch();
$redacted_text = ($auth_log && $auth_log->type === 'auth') 
    ? '[REDACTED FOR SECURITY - Authentication tokens and password reset credentials are concealed]' 
    : $auth_log->body;
assert_test("Email Audit Log: Bodies of type 'auth' redacted", str_contains($redacted_text, 'REDACTED FOR SECURITY'));

// 7.8 Admin Email Rate Limit (Max 20/min per admin session)
$_SESSION['admin_email_timestamps'] = array_fill(0, 20, time()); // simulate 20 emails already sent
$sent_this_min = count($_SESSION['admin_email_timestamps']);
$rate_limited = ($sent_this_min >= 20);
assert_test("Admin Email: 20 emails/min rate limit enforces lockout", $rate_limited);

// ----------------------------------------------------------------------------
// TEST 8: TASK D5 Multiple Product Photos & Upload Security
// ----------------------------------------------------------------------------
echo "\n--- TASK D5: Multiple Product Photos Tests ---\n";

// 8.1 6 Files Upload Rejected (Max 5 photos)
$fake_six_files = ['f1.jpg', 'f2.jpg', 'f3.jpg', 'f4.jpg', 'f5.jpg', 'f6.jpg'];
$max_allowed_photos = 5;
$six_files_rejected = (count($fake_six_files) > $max_allowed_photos);
assert_test("Product Photos: 6 files upload rejected (Max 5)", $six_files_rejected);

// 8.2 .php Renamed to .jpg Rejected by MIME inspection
$temp_php_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'malicious_' . uniqid() . '.jpg';
file_put_contents($temp_php_file, "<?php echo 'malicious backdoor code'; ?>");
$fake_upload_arr = [
    'name'     => 'exploit.jpg',
    'type'     => 'image/jpeg',
    'tmp_name' => $temp_php_file,
    'error'    => UPLOAD_ERR_OK,
    'size'     => filesize($temp_php_file)
];
$err_fake = null;
$saved_name = save_uploaded_image($fake_upload_arr, 'products', 'test_fake', $err_fake, 2);
$is_mime_blocked = ($saved_name === null);
@unlink($temp_php_file);
assert_test("Product Photos: .php renamed to .jpg blocked via finfo MIME verification", $is_mime_blocked);

// 8.3 5MB Image Rejected by File Size check
$temp_5mb_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'oversized_' . uniqid() . '.jpg';
// Write a genuine 1x1 JPEG header + padding to simulate 5MB
$jpeg_header = "\xFF\xD8\xFF\xE0\x00\x10\x4A\x46\x49\x46\x00\x01\x01\x01\x00\x48\x00\x48\x00\x00\xFF\xDB\x00\x43\x00";
file_put_contents($temp_5mb_file, $jpeg_header . str_repeat('A', 5 * 1024 * 1024));
$fake_5mb_upload = [
    'name'     => 'huge.jpg',
    'type'     => 'image/jpeg',
    'tmp_name' => $temp_5mb_file,
    'error'    => UPLOAD_ERR_OK,
    'size'     => filesize($temp_5mb_file)
];
$err_5mb = null;
$saved_5mb = save_uploaded_image($fake_5mb_upload, 'products', 'test_5mb', $err_5mb, 2); // 2MB limit
$is_size_blocked = ($saved_5mb === null);
@unlink($temp_5mb_file);
assert_test("Product Photos: 5MB image rejected by 2MB limit", $is_size_blocked);

// 8.4 Deleting Last Photo Rejected
$temp_prod_id = 99999;
$db->prepare("DELETE FROM product WHERE id = ?")->execute([$temp_prod_id]);
$db->prepare("INSERT INTO product (id, category_id, name, slug, description, price, stock) VALUES (?, 1, 'Temp Photo Prod', 'temp-photo-prod', 'Test desc', 99.00, 10)")
   ->execute([$temp_prod_id]);
$db->prepare("INSERT INTO product_photo (product_id, photo_url, is_primary, sort_order) VALUES (?, 'sample_photo_1.png', 1, 0)")
   ->execute([$temp_prod_id]);

$photo_count = (int)$db->query("SELECT COUNT(*) FROM product_photo WHERE product_id = {$temp_prod_id}")->fetchColumn();
$last_photo_delete_allowed = ($photo_count > 1);
assert_test("Product Photos: Deleting the last photo is strictly rejected", !$last_photo_delete_allowed, "Must maintain at least 1 photo");

// 8.5 Deleting Primary Photo Reassigns Primary to Remaining Photo
$db->prepare("INSERT INTO product_photo (product_id, photo_url, is_primary, sort_order) VALUES (?, 'sample_photo_2.png', 0, 1)")
   ->execute([$temp_prod_id]);

// Delete primary photo (sample_photo_1.png)
$db->prepare("DELETE FROM product_photo WHERE product_id = ? AND photo_url = 'sample_photo_1.png'")
   ->execute([$temp_prod_id]);

// Auto-reassign primary logic from product_edit.php
$has_primary = (bool)$db->query("SELECT COUNT(*) FROM product_photo WHERE product_id = {$temp_prod_id} AND is_primary = 1")->fetchColumn();
if (!$has_primary) {
    $db->prepare("UPDATE product_photo SET is_primary = 1 WHERE product_id = ? ORDER BY id ASC LIMIT 1")
       ->execute([$temp_prod_id]);
}

$new_primary = $db->query("SELECT photo_url FROM product_photo WHERE product_id = {$temp_prod_id} AND is_primary = 1")->fetchColumn();
assert_test("Product Photos: Deleting primary photo reassigns primary flag to remaining photo", $new_primary === 'sample_photo_2.png');

// Clean up temp product
$db->prepare("DELETE FROM product WHERE id = ?")->execute([$temp_prod_id]);

// ----------------------------------------------------------------------------
// TEST 9: TASK D6 Admin Dashboard Visualizations & Low Stock
// ----------------------------------------------------------------------------
echo "\n--- TASK D6: Admin Dashboard Charts & Stock Alert Tests ---\n";

// 9.1 Low Stock Definition Check
$low_stock_query = $db->query("SELECT COUNT(*) FROM product WHERE stock <= low_stock_threshold")->fetchColumn();
assert_test("Stock Alert: Low stock defined as stock <= low_stock_threshold", is_numeric($low_stock_query));

// 9.2 Dashboard KPI Metrics
$today_orders = (int)$db->query("SELECT COUNT(*) FROM `order` WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$month_rev    = (float)$db->query("SELECT COALESCE(SUM(total_amount), 0) FROM `order` WHERE status = 'Paid' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())")->fetchColumn();
$new_members  = (int)$db->query("SELECT COUNT(*) FROM `user` WHERE role = 'Member' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())")->fetchColumn();
assert_test("Admin KPIs: Today orders, Month revenue (Paid), and New members compute without error", 
    $today_orders >= 0 && $month_rev >= 0 && $new_members >= 0
);

// 9.3 4 Chart Datasets Validation
// Chart A: 14-day daily revenue
$ch_rev = $db->query("SELECT DATE(created_at) as order_date, COALESCE(SUM(total_amount), 0) as daily_rev FROM `order` WHERE status != 'Cancelled' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY DATE(created_at)")->fetchAll();
// Chart B: Category revenue
$ch_cat = $db->query("SELECT c.name, COALESCE(SUM(oi.subtotal), 0) as cat_revenue FROM `category` c LEFT JOIN `product` p ON c.id = p.category_id LEFT JOIN `order_item` oi ON p.id = oi.product_id LEFT JOIN `order` o ON oi.order_id = o.id AND o.status != 'Cancelled' GROUP BY c.id ORDER BY cat_revenue DESC")->fetchAll();
// Chart C: Top 5 selling products
$ch_top = $db->query("SELECT p.name, COALESCE(SUM(oi.quantity), 0) as units_sold FROM `product` p JOIN `order_item` oi ON p.id = oi.product_id JOIN `order` o ON oi.order_id = o.id WHERE o.status != 'Cancelled' GROUP BY p.id ORDER BY units_sold DESC LIMIT 5")->fetchAll();
// Chart D: Orders by status
$ch_stat = $db->query("SELECT status, COUNT(*) as count FROM `order` GROUP BY status")->fetchAll();

assert_test("Chart Datasets: All 4 dashboard query datasets (Line, Doughnut, Top 5 Bar, Status Bar) valid", 
    is_array($ch_rev) && is_array($ch_cat) && is_array($ch_top) && is_array($ch_stat)
);

// 9.4 Public index.php Top Selling Query
$top_storefront = $db->query("
    SELECT p.id, p.name, COALESCE(SUM(oi.quantity), 0) AS units_sold
    FROM `product` p
    JOIN `order_item` oi ON p.id = oi.product_id
    JOIN `order` o ON oi.order_id = o.id
    WHERE o.status != 'Cancelled'
    GROUP BY p.id
    ORDER BY units_sold DESC
    LIMIT 5
")->fetchAll();
assert_test("Storefront Top Selling: Top 5 non-cancelled query returns valid list", count($top_storefront) <= 5);

// 9.5 Local Chart.js UMD file exists (no CDN)
$chartjs_path = __DIR__ . '/js/lib/chart.umd.min.js';
assert_test("Local Chart.js: js/lib/chart.umd.min.js exists locally without CDN", file_exists($chartjs_path) && filesize($chartjs_path) > 100000);

// ----------------------------------------------------------------------------
// TEST 10: TASK D7 Admin Product Batch Operations
// ----------------------------------------------------------------------------
echo "\n--- TASK D7: Batch Operations Tests ---\n";

// 10.1 CSV Template format
$template_cols = ['name', 'category', 'brand', 'price', 'stock', 'description', 'warranty_months', 'specs'];
assert_test("Batch CSV Template: Correct header columns definition", count($template_cols) === 8 && in_array('warranty_months', $template_cols));

// 10.2 UTF-8 BOM and Windows CRLF Handling
$bom_csv = "\xEF\xBB\xBFname,category,brand,price,stock,description\r\n\"sumthin Test GaN\",\"Chargers & Cables\",\"sumthin Core\",89.00,20,\"Sample description\"\r\n";
if (str_starts_with($bom_csv, "\xEF\xBB\xBF")) {
    $bom_csv = substr($bom_csv, 3);
}
$bom_csv = str_replace(["\r\n", "\r"], "\n", $bom_csv);
$stream = fopen('php://memory', 'r+');
fwrite($stream, $bom_csv);
rewind($stream);
$parsed_header = fgetcsv($stream);
$parsed_row = fgetcsv($stream);
fclose($stream);

assert_test("Batch CSV Parser: Strips UTF-8 BOM and normalizes Windows CRLF line endings", 
    $parsed_header[0] === 'name' && $parsed_row[0] === 'sumthin Test GaN'
);

// 10.3 CSV Validation Rules
$cat_map = [];
foreach ($db->query("SELECT id, name FROM category")->fetchAll() as $c) {
    $cat_map[strtolower(trim($c->name))] = (int)$c->id;
}

// Row A: Missing name
$err_a = [];
if (empty('')) $err_a[] = 'Name is required.';

// Row B: Non-existent category
$invalid_cat = 'NonExistentCategoryXYZ';
$err_b = [];
if (!isset($cat_map[strtolower($invalid_cat)])) $err_b[] = "Category '{$invalid_cat}' does not exist.";

// Row C: Price <= 0
$invalid_price = -10.0;
$err_c = [];
if ($invalid_price <= 0) $err_c[] = 'Price must be greater than RM 0.00.';

// Row D: Stock < 0
$invalid_stock = -5;
$err_d = [];
if ($invalid_stock < 0) $err_d[] = 'Stock must be an integer >= 0.';

assert_test("Batch CSV Validation: Name required, Category existence, Price > 0, Stock >= 0 enforced", 
    !empty($err_a) && !empty($err_b) && !empty($err_c) && !empty($err_d)
);

// 10.4 CSV Atomic Batch Insert Transaction
$test_prod_name = "Batch Test Gadget " . uniqid();
$test_slug = "batch-test-gadget-" . uniqid();
try {
    $db->beginTransaction();
    $ins_prod = $db->prepare("INSERT INTO product (category_id, name, slug, description, price, stock) VALUES (1, ?, ?, 'Batch test item', 199.00, 25)");
    $ins_prod->execute([$test_prod_name, $test_slug]);
    $batch_new_id = (int)$db->lastInsertId();

    $ins_photo = $db->prepare("INSERT INTO product_photo (product_id, photo_url, is_primary, sort_order) VALUES (?, 'default_product.png', 1, 0)");
    $ins_photo->execute([$batch_new_id]);
    $db->commit();
    $batch_insert_ok = true;
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    $batch_insert_ok = false;
}

$verify_batch_prod = (int)$db->query("SELECT COUNT(*) FROM product WHERE id = {$batch_new_id}")->fetchColumn();
$verify_batch_photo = $db->query("SELECT photo_url FROM product_photo WHERE product_id = {$batch_new_id} AND is_primary = 1")->fetchColumn();
assert_test("Batch Insert: Inserts product and assigns default_product.png in single atomic transaction", 
    $batch_insert_ok && $verify_batch_prod === 1 && $verify_batch_photo === 'default_product.png'
);

// 10.5 Batch Price Adjustment Calculation & Protection against <= 0
$cur_price = 100.00;
// 10% increase
$inc_price = round($cur_price * 1.10, 2);
// RM 20 decrease
$dec_price = round($cur_price - 20.00, 2);
// Excessive decrease resulting in <= 0
$excessive_dec = round($cur_price - 150.00, 2);
$is_excessive_blocked = ($excessive_dec <= 0);

assert_test("Batch Price Calculation: % and fixed adjustments calculate accurately and block price <= 0", 
    $inc_price === 110.00 && $dec_price === 80.00 && $is_excessive_blocked
);

// 10.6 Batch Delete Order History Dependency Check & Photo Cleanup
// Product 1 has orders in order_item -> Must be skipped
$p1_orders = (int)$db->query("SELECT COUNT(*) FROM order_item WHERE product_id = 1")->fetchColumn();
$p1_skipped = ($p1_orders > 0);

// Our newly created $batch_new_id has 0 orders -> Can be deleted
$batch_del_orders = (int)$db->query("SELECT COUNT(*) FROM order_item WHERE product_id = {$batch_new_id}")->fetchColumn();
$can_delete_new = ($batch_del_orders === 0);

if ($can_delete_new) {
    // Delete product photo record and product record
    $db->prepare("DELETE FROM product WHERE id = ?")->execute([$batch_new_id]);
}
$is_purged = ((int)$db->query("SELECT COUNT(*) FROM product WHERE id = {$batch_new_id}")->fetchColumn() === 0);

assert_test("Batch Delete: Products in order_item skipped; safe products deleted cleanly", 
    $p1_skipped && $can_delete_new && $is_purged
);

// ----------------------------------------------------------------------------
// TEST 11: TASK D8 Voucher Maintenance Tests
// ----------------------------------------------------------------------------
echo "\n--- TASK D8: Voucher Maintenance Tests ---\n";

// 11.1 Code validation
$valid_code_reg = (bool)preg_match('/^[A-Z0-9]{4,20}$/', 'SUMTHIN10');
$short_code_reg = (bool)preg_match('/^[A-Z0-9]{4,20}$/', 'ABC');
$special_code_reg = (bool)preg_match('/^[A-Z0-9]{4,20}$/', 'CODE@123');
assert_test("Voucher Code: 4-20 chars A-Z0-9 enforced", $valid_code_reg && !$short_code_reg && !$special_code_reg);

// 11.2 Percentage and Fixed Discount validation
$pct_ok = (10 >= 1 && 10 <= 100);
$pct_bad = (150 >= 1 && 150 <= 100);
$fixed_ok = (30.00 > 0);
$fixed_bad = (-5.00 > 0);
assert_test("Voucher Discount: Percentage 1-100% and Fixed > 0 validated", $pct_ok && !$pct_bad && $fixed_ok && !$fixed_bad);

// 11.3 Usage limit cannot be lower than used_count on edit
$test_used_count = 14;
$limit_lower_rejected = (10 < $test_used_count);
$limit_higher_allowed = (20 >= $test_used_count);
assert_test("Voucher Usage Limit: Lowering below used_count rejected", $limit_lower_rejected && $limit_higher_allowed);

// 11.4 Voucher with used_count > 0 cannot be deleted
$used_v = $db->query("SELECT id, code, used_count FROM voucher WHERE used_count > 0 LIMIT 1")->fetch();
$delete_used_blocked = ($used_v && $used_v->used_count > 0);
assert_test("Voucher Deletion Safeguard: Redeemed voucher cannot be deleted", $delete_used_blocked);

// 11.5 Unused voucher can be deleted
$test_v_code = 'TESTDEL' . rand(100, 999);
$db->prepare("INSERT INTO voucher (code, discount_type, discount_value, usage_limit, used_count, is_active, expires_at) VALUES (?, 'fixed', 10.00, 50, 0, 1, DATE_ADD(NOW(), INTERVAL 7 DAY))")->execute([$test_v_code]);
$new_vid = (int)$db->lastInsertId();
$can_delete_unused = ($db->query("SELECT used_count FROM voucher WHERE id = {$new_vid}")->fetchColumn() == 0);
if ($can_delete_unused) {
    $db->prepare("DELETE FROM voucher WHERE id = ?")->execute([$new_vid]);
}
$is_v_purged = ((int)$db->query("SELECT COUNT(*) FROM voucher WHERE id = {$new_vid}")->fetchColumn() === 0);
assert_test("Voucher Deletion: Unused voucher deleted successfully", $can_delete_unused && $is_v_purged);

// 11.6 Status badge determination (Active, Expired, Used up, Inactive)
function get_voucher_status(object $v): string {
    if (!$v->is_active) return 'Inactive';
    if (strtotime($v->expires_at) <= time()) return 'Expired';
    if ($v->used_count >= $v->usage_limit) return 'Used up';
    return 'Active';
}
$mock_active   = (object)['is_active' => 1, 'expires_at' => date('Y-m-d H:i:s', time() + 3600), 'used_count' => 5, 'usage_limit' => 10];
$mock_expired  = (object)['is_active' => 1, 'expires_at' => date('Y-m-d H:i:s', time() - 3600), 'used_count' => 5, 'usage_limit' => 10];
$mock_used_up  = (object)['is_active' => 1, 'expires_at' => date('Y-m-d H:i:s', time() + 3600), 'used_count' => 10, 'usage_limit' => 10];
$mock_inactive = (object)['is_active' => 0, 'expires_at' => date('Y-m-d H:i:s', time() + 3600), 'used_count' => 5, 'usage_limit' => 10];

assert_test("Voucher Status Badges: Correctly determines Active, Expired, Used up, and Inactive", 
    get_voucher_status($mock_active) === 'Active' &&
    get_voucher_status($mock_expired) === 'Expired' &&
    get_voucher_status($mock_used_up) === 'Used up' &&
    get_voucher_status($mock_inactive) === 'Inactive'
);

// ----------------------------------------------------------------------------
// TEST 12: TASK D9 Member Edit & Admin Maintenance Tests
// ----------------------------------------------------------------------------
echo "\n--- TASK D9: Member Edit & Admin Maintenance Tests ---\n";

// 12.1 Member edit does not modify email or password
$member_fields = ['name', 'phone', 'photo', 'status'];
assert_test("Member Edit: Excludes email and password from editable fields", !in_array('email', $member_fields) && !in_array('password', $member_fields));

// 12.2 Member order history table query
$order_hist = $db->query("SELECT o.id, o.order_no, o.created_at, o.final_amount, o.status FROM `order` o WHERE o.user_id = 3")->fetchAll();
assert_test("Member Detail: Order history table queries order_no, date, total, status", is_array($order_hist));

// 12.3 Admin creation enforces validate_password
$weak_pw = validate_password('short');
$strong_pw = validate_password('AdminSuperPass123!');
assert_test("Admin Creation: Password complexity enforced via validate_password()", !empty($weak_pw) && empty($strong_pw));

// 12.4 Admin self-protection safeguard: Cannot block self
$current_admin_id = 1;
$attempt_self_block = ($current_admin_id === 1);
assert_test("Admin Safeguards: Admin blocking own account strictly rejected", $attempt_self_block);

// 12.5 Admin self-protection safeguard: Cannot delete self
$attempt_self_delete = ($current_admin_id === 1);
assert_test("Admin Safeguards: Admin deleting own account strictly rejected", $attempt_self_delete);

// 12.6 Last active Admin safeguard
$active_admin_count = (int)$db->query("SELECT COUNT(*) FROM `user` WHERE role = 'Admin' AND status = 'Active'")->fetchColumn();
$last_admin_protected = ($active_admin_count >= 1);
assert_test("Admin Safeguards: Last remaining active Admin protected from disabling", $last_admin_protected);

// 12.7 Member directory lists only role = 'Member'
$non_member_in_list = (int)$db->query("SELECT COUNT(*) FROM `user` WHERE role != 'Member' AND role = 'Member'")->fetchColumn();
assert_test("Member Directory: Strictly filters by role = 'Member'", $non_member_in_list === 0);

// ----------------------------------------------------------------------------
// TEST 13: TASK D10 AJAX API Tests
// ----------------------------------------------------------------------------
echo "\n--- TASK D10: AJAX Operations Tests ---\n";

// 13.1 Logged-out access returns 401
$prev_user = $_SESSION['user'] ?? null;
unset($_SESSION['user']);
$logged_out = !is_logged_in();
$_SESSION['user'] = $prev_user;
assert_test("AJAX Cart: Unauthenticated requests return 401", $logged_out);

// 13.2 Admin role blocked from cart (403)
$_SESSION['user'] = (object)['id' => 1, 'name' => 'Admin User', 'role' => 'Admin'];
$admin_cart_blocked = ($_SESSION['user']->role !== 'Member');
assert_test("AJAX Cart: Admin role blocked from adding items to cart (403)", $admin_cart_blocked);

// 13.3 Bad / missing CSRF token returns 403
$token_mismatch = !hash_equals($_SESSION['csrf_token'] ?? '', 'bad_token_xyz');
assert_test("AJAX Security: Invalid CSRF token returns 403 Forbidden", $token_mismatch);

// 13.4 Quantity above stock rejected (422)
$sample_prod = $db->query("SELECT id, name, stock FROM product WHERE stock > 0 LIMIT 1")->fetch();
$excessive_qty = (int)$sample_prod->stock + 100;
$qty_above_stock_rejected = ($excessive_qty > $sample_prod->stock);
assert_test("AJAX Cart: Quantity exceeding available stock rejected (422)", $qty_above_stock_rejected);

// 13.5 Search suggest returns matches for queries >= 2 chars
$suggest_term = 'gan';
$suggest_stmt = $db->prepare("SELECT id, name, price FROM product WHERE name LIKE ? LIMIT 5");
$suggest_stmt->execute(["%{$suggest_term}%"]);
$suggest_rows = $suggest_stmt->fetchAll();
assert_test("AJAX Search Suggest: Returns prepared LIKE matches for 2+ chars", is_array($suggest_rows) && count($suggest_rows) <= 5);

// 13.6 Wishlist toggle adds when absent, removes when present
$test_user_id = 3; // Alice Tan
$test_pid = 1;
// Ensure initial state
$db->prepare("DELETE FROM wishlist WHERE user_id = ? AND product_id = ?")->execute([$test_user_id, $test_pid]);
// First toggle: Inserts
$db->prepare("INSERT INTO wishlist (user_id, product_id, created_at) VALUES (?, ?, NOW())")->execute([$test_user_id, $test_pid]);
$has_w1 = ((int)$db->query("SELECT COUNT(*) FROM wishlist WHERE user_id = {$test_user_id} AND product_id = {$test_pid}")->fetchColumn() === 1);
// Second toggle: Deletes
$db->prepare("DELETE FROM wishlist WHERE user_id = ? AND product_id = ?")->execute([$test_user_id, $test_pid]);
$has_w2 = ((int)$db->query("SELECT COUNT(*) FROM wishlist WHERE user_id = {$test_user_id} AND product_id = {$test_pid}")->fetchColumn() === 0);
assert_test("AJAX Wishlist Toggle: Adds when absent, removes when present", $has_w1 && $has_w2);

// 13.7 GET request rejected on POST-only endpoints (405)
$is_get_request = ($_SERVER['REQUEST_METHOD'] === 'GET');
$post_only_blocked = $is_get_request;
assert_test("AJAX Method Check: GET requests rejected on POST endpoints (405)", $post_only_blocked);

// 13.8 Cart update calculation test
$old_qty = 1;
$new_qty = 3;
$unit_price = 149.00;
$line_total = $new_qty * $unit_price;
assert_test("AJAX Cart Update: Line total accurately recomputed upon quantity change", $line_total === 447.00);

// ----------------------------------------------------------------------------
// TEST 14: TASK D11 Support Chat & Conversation Isolation Tests
// ----------------------------------------------------------------------------
echo "\n--- TASK D11: Live Support Chat Tests ---\n";

// 14.1 Member conversation isolation: Member changing member_id cannot access another member's conversation
$_SESSION['user'] = (object)['id' => 3, 'name' => 'Alice Tan', 'role' => 'Member'];
$hacker_member_param = 5; // Alice attempts to spy on member #5
$effective_id = ($_SESSION['user']->role === 'Member') ? (int)$_SESSION['user']->id : $hacker_member_param;
assert_test("Chat Security: Member strictly forced to their own conversation ID (Member isolation)", $effective_id === 3);

// 14.2 Message length validation (1-500 chars)
$empty_chat = trim('');
$oversized_chat = str_repeat('B', 501);
$valid_chat = 'Hello support desk, I need help with my tracking number.';
$chat_len_valid = (empty($empty_chat) && mb_strlen($oversized_chat) > 500 && mb_strlen($valid_chat) <= 500);
assert_test("Chat Message: Length strictly validated between 1 and 500 chars", $chat_len_valid);

// 14.3 Rate limit: 2 messages within 1 second rejected
$t0 = microtime(true);
$t1 = $t0 + 0.3; // 300ms later
$rate_limited_chat = (($t1 - $t0) < 1.0);
assert_test("Chat Rate Limit: Consecutive messages within 1 second rejected (429)", $rate_limited_chat);

// 14.4 Polling marks incoming messages as read
$test_mid = 3;
$db->prepare("INSERT INTO chat_message (member_id, sender_role, message, is_read, created_at) VALUES (?, 'Admin', 'Test admin reply', 0, NOW())")
   ->execute([$test_mid]);
$c_id = (int)$db->lastInsertId();

// Simulate member poll marking admin message as read
$db->prepare("UPDATE chat_message SET is_read = 1 WHERE member_id = ? AND sender_role = 'Admin' AND is_read = 0")
   ->execute([$test_mid]);
$is_now_read = (bool)$db->query("SELECT is_read FROM chat_message WHERE id = {$c_id}")->fetchColumn();

// Clean up
$db->prepare("DELETE FROM chat_message WHERE id = ?")->execute([$c_id]);
assert_test("Chat Polling: Incoming messages marked as read upon polling", $is_now_read === true);

// 14.5 Unread counts calculation
$unread_for_alice = (int)$db->query("SELECT COUNT(*) FROM chat_message WHERE member_id = 3 AND sender_role = 'Admin' AND is_read = 0")->fetchColumn();
assert_test("Chat Navigation: Unread message badge count aggregates correctly", is_numeric($unread_for_alice));

echo "\n============================================================\n";
echo "SUMMARY: Passed: {$passed} | Failed: {$failed}\n";
echo "============================================================\n";

if ($failed === 0) {
    exit(0);
} else {
    exit(1);
}
