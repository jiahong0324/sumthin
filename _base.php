<?php
// ============================================================================
// File: _base.php
// Project: sumthin (Tech Gadget Online Store)
// Course: BMIT2013 Web-Based Integrated Systems
// Description: Core base library providing database connection (PDO),
//              session lifecycle management, role-based authorization,
//              CSRF protection, HTML form helpers, and flash messaging.
// ============================================================================

// 1. Load centralized configuration
$config = require __DIR__ . '/_config.php';
if (file_exists(__DIR__ . '/_config.local.php')) {
    $local_config = require __DIR__ . '/_config.local.php';
    if (is_array($local_config)) {
        $config = array_replace_recursive($config, $local_config);
    }
}
require_once __DIR__ . '/_mail.php';

// 2. Start Secure Session with configuration
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0, // Session cookie lasts until browser closes
        'path'     => '/',
        'domain'   => '',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true, // Mitigate XSS cookie theft
        'samesite' => 'Lax' // Mitigate CSRF
    ]);
    session_start();
}

// 3. Enforce Session Inactivity Timeout (30 minutes default)
if (isset($_SESSION['last_activity'])) {
    $inactive_seconds = time() - $_SESSION['last_activity'];
    if ($inactive_seconds > $config['app']['session_timeout']) {
        // Session expired: clean up session data
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['flash'] = [
            'type'    => 'warning',
            'message' => 'Your session has timed out due to inactivity. Please log in again.'
        ];
        header('Location: ' . $config['app']['base_url'] . '/login.php');
        exit;
    }
}
$_SESSION['last_activity'] = time();

// 4. PDO Database Connection with Prepared Statements
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $config['db']['host'],
        $config['db']['port'],
        $config['db']['dbname'],
        $config['db']['charset']
    );

    $db = new PDO($dsn, $config['db']['user'], $config['db']['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    // Graceful error display without revealing credentials
    $debug_info = '';
    if (!empty($config['app']['debug'])) {
        $debug_info = '<pre class="db-error-pre">' . htmlspecialchars($e->getMessage()) . '</pre>';
    }
    $css_link = (!empty($config['app']['base_url']) ? rtrim($config['app']['base_url'], '/') : '') . '/css/app.css';
    die('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Database Error</title><link rel="stylesheet" href="' . htmlspecialchars($css_link) . '"></head><body>'
        . '<div class="db-error-box">'
        . '<h2 class="db-error-title">Database Connection Error</h2>'
        . '<p class="db-error-msg">Could not connect to the database <strong>' . htmlspecialchars($config['db']['dbname']) . '</strong>.</p>'
        . '<p class="db-error-tips"><strong>Tips:</strong> Ensure Apache & MySQL are running in XAMPP, and that you have imported <code>database/sumthin_db.sql</code> via phpMyAdmin.</p>'
        . $debug_info
        . '</div></body></html>');
}

// 5. Global Error Collector for Server-Side Form Validation
$errors = [];

// 6. Automatic Authentication via "Remember Me" Cookie (SHA-256 token lookup & rotation)
if (empty($_SESSION['user']) && !empty($_COOKIE['sumthin_remember'])) {
    try {
        $raw_token  = (string)$_COOKIE['sumthin_remember'];
        $token_hash = hash('sha256', $raw_token);
        $rem_stmt   = $db->prepare('SELECT * FROM `user` WHERE `remember_token` = ? AND `status` = ? LIMIT 1');
        $rem_stmt->execute([$token_hash, 'Active']);
        $remembered_user = $rem_stmt->fetch();

        if ($remembered_user) {
            session_regenerate_id(true);
            unset($remembered_user->password_hash);
            $_SESSION['user'] = $remembered_user;
            $_SESSION['last_activity'] = time();

            // Rotate remember token to protect against token theft
            $new_raw_token  = bin2hex(random_bytes(32));
            $new_token_hash = hash('sha256', $new_raw_token);
            $rot_stmt = $db->prepare('UPDATE `user` SET `remember_token` = ? WHERE `id` = ?');
            $rot_stmt->execute([$new_token_hash, $remembered_user->id]);

            $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
                     || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

            setcookie('sumthin_remember', $new_raw_token, [
                'expires'  => time() + (30 * 86400),
                'path'     => '/',
                'httponly' => true,
                'secure'   => $is_https,
                'samesite' => 'Lax'
            ]);
        } else {
            setcookie('sumthin_remember', '', time() - 3600, '/');
        }
    } catch (Exception $e) {
        // Silently fallback if table query fails
    }
}

// ============================================================================
// HELPER FUNCTIONS: HTTP Request & Navigation
// ============================================================================

/**
 * Check if the current request is an HTTP GET
 */
function is_get(): bool {
    return $_SERVER['REQUEST_METHOD'] === 'GET';
}

/**
 * Check if the current request is an HTTP POST
 */
function is_post(): bool {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/**
 * Safely retrieve and sanitize request input (GET or POST)
 */
function req(string $key, $default = '') {
    $val = $_POST[$key] ?? $_GET[$key] ?? $default;
    if (is_string($val)) {
        return trim($val);
    }
    return $val;
}

/**
 * Build an absolute application URL
 */
function base_url(string $path = ''): string {
    global $config;
    $base = rtrim($config['app']['base_url'], '/');
    $path = ltrim($path, '/');
    return $path !== '' ? $base . '/' . $path : $base;
}

/**
 * Redirect browser to a given internal or external URL
 */
function redirect(string $url): void {
    header("Location: $url");
    exit;
}

// ============================================================================
// HELPER FUNCTIONS: Security & Output Escaping
// ============================================================================

/**
 * Escape HTML output to prevent Cross-Site Scripting (XSS)
 */
function encode(?string $str): string {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

/**
 * Alias for encode()
 */
function e(?string $str): string {
    return encode($str);
}

/**
 * Generate or retrieve CSRF token from session
 */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Output hidden CSRF form input field
 */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . encode(csrf_token()) . '">';
}

/**
 * Verify submitted CSRF token on POST requests (Supports POST body and X-CSRF-Token header)
 */
function verify_csrf(): void {
    if (is_post()) {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(403);
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' || str_starts_with($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'message' => '403 Forbidden: Invalid or expired CSRF token.']);
                exit;
            }
            global $config;
            $css_link = (!empty($config['app']['base_url']) ? rtrim($config['app']['base_url'], '/') : '') . '/css/app.css';
            die('<!DOCTYPE html><html><head><meta charset="utf-8"><title>403 Forbidden</title><link rel="stylesheet" href="' . htmlspecialchars($css_link) . '"></head><body>'
                . '<h1 class="csrf-error-title">403 Forbidden - Invalid or Expired CSRF Token</h1>'
                . '</body></html>');
        }
    }
}

/**
 * Send a structured JSON response with proper HTTP status code and terminate
 */
function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// ============================================================================
// HELPER FUNCTIONS: Cloudflare Turnstile CAPTCHA Protection
// ============================================================================

/**
 * Output Cloudflare Turnstile CAPTCHA widget div and provider script tag (rendered once per page)
 */
function captcha_widget(): string {
    global $config;
    if (empty($config['captcha']['enabled'])) {
        return '';
    }

    $site_key = (string)($config['captcha']['site_key'] ?? '');
    if ($site_key === '' || $site_key === 'PLACEHOLDER') {
        return '';
    }

    static $script_loaded = false;
    $script = '';
    if (!$script_loaded) {
        $script = '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
        $script_loaded = true;
    }

    return $script . '<div class="cf-turnstile my-14" data-sitekey="' . encode($site_key) . '"></div>';
}

/**
 * Verify Cloudflare Turnstile CAPTCHA response server-side via cURL
 *
 * @return bool True if verification passes or captcha is disabled; false on failure (fail closed)
 */
function captcha_verify(): bool {
    global $config;
    if (empty($config['captcha']['enabled'])) {
        return true;
    }

    $secret = (string)($config['captcha']['secret_key'] ?? '');
    $response = (string)($_POST['cf-turnstile-response'] ?? '');
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

    // Any missing secret or response fails closed
    if ($secret === '' || $secret === 'PLACEHOLDER' || $response === '') {
        return false;
    }

    try {
        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        if (!$ch) {
            return false;
        }

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'secret'   => $secret,
            'response' => $response,
            'remoteip' => $ip,
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        $result_body = curl_exec($ch);
        $http_code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err    = curl_error($ch);
        curl_close($ch);

        if ($result_body === false || $http_code !== 200) {
            if ($curl_err) {
                error_log('Turnstile siteverify cURL error: ' . $curl_err);
            }
            return false;
        }

        $data = json_decode($result_body, true);
        return is_array($data) && !empty($data['success']);
    } catch (\Throwable $e) {
        error_log('Turnstile siteverify exception: ' . $e->getMessage());
        return false;
    }
}

// ============================================================================
// HELPER FUNCTIONS: Role-Based Authorization
// ============================================================================

/**
 * Retrieve currently authenticated user object from session, or null
 */
function current_user() {
    return $_SESSION['user'] ?? null;
}

/**
 * Check if a user is logged in
 */
function is_logged_in(): bool {
    return isset($_SESSION['user']) && !empty($_SESSION['user']->id);
}

/**
 * Check if the logged-in user is an Admin
 */
function is_admin(): bool {
    return is_logged_in() && $_SESSION['user']->role === 'Admin';
}

/**
 * Check if the logged-in user is a Member
 */
function is_member(): bool {
    return is_logged_in() && $_SESSION['user']->role === 'Member';
}

/**
 * Log technical exception and return a clean, friendly user message (appends detail only in debug mode)
 */
function app_error_message(string $friendly_message, Throwable $e): string {
    global $config;
    error_log($friendly_message . ': ' . $e->getMessage());
    if (!empty($config['app']['debug'])) {
        return $friendly_message . ' (' . $e->getMessage() . ')';
    }
    return $friendly_message;
}

/**
 * Refresh logged-in user from the database to instantly detect blocked or removed accounts
 */
function refresh_session_user(): void {
    global $db;
    if (!is_logged_in()) {
        return;
    }

    $user_id = (int)$_SESSION['user']->id;
    try {
        $stmt = $db->prepare('SELECT `id`, `email`, `name`, `phone`, `role`, `photo`, `status` FROM `user` WHERE `id` = ?');
        $stmt->execute([$user_id]);
        $fresh_user = $stmt->fetch();

        if (!$fresh_user || $fresh_user->status === 'Blocked') {
            // Destroy session and invalidate remember-me cookie immediately
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params['path'], $params['domain'],
                    $params['secure'], $params['httponly']
                );
            }
            session_destroy();
            setcookie('sumthin_remember', '', time() - 3600, '/');

            session_start();
            set_flash('error', 'Your account has been suspended by the administrator.');
            redirect(base_url('login.php'));
        }

        // Store clean user object without password_hash
        $_SESSION['user'] = $fresh_user;
    } catch (Exception $e) {
        error_log('refresh_session_user error: ' . $e->getMessage());
    }
}

/**
 * Enforce role-based access control on protected pages
 * @param string|array $allowed_roles e.g. 'Admin' or ['Admin', 'Member']
 */
function auth($allowed_roles): void {
    // 1. Always verify fresh account status from database
    if (is_logged_in()) {
        refresh_session_user();
    }

    $roles = (array)$allowed_roles;

    // 2. Not logged in -> Save target URL and redirect to login
    if (!is_logged_in()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? base_url('index.php');
        set_flash('warning', 'Please sign in to access this page.');
        redirect(base_url('login.php'));
    }

    $current_role = $_SESSION['user']->role;

    // 3. User does not possess the required role
    if (!in_array($current_role, $roles, true)) {
        http_response_code(403);
        redirect(base_url('403.php'));
    }
}

/**
 * Prevent already logged-in users from viewing Guest-only pages (login, register)
 */
function guest_only(): void {
    if (is_logged_in()) {
        refresh_session_user();
        if (is_admin()) {
            redirect(base_url('admin/index.php'));
        } else {
            redirect(base_url('index.php'));
        }
    }
}

// ============================================================================
// HELPER FUNCTIONS: Flash Messages (Toasts / Notification banners)
// ============================================================================

/**
 * Set a one-time flash notification message
 * @param string $type 'success' | 'error' | 'warning' | 'info'
 * @param string $message
 */
function set_flash(string $type, string $message): void {
    $_SESSION['flash'] = [
        'type'    => $type,
        'message' => $message
    ];
}

/**
 * Retrieve and clear flash message
 */
function get_flash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// ============================================================================
// HELPER FUNCTIONS: HTML Form Controls & Validation Output
// ============================================================================

/**
 * Display inline validation error message for a given field
 */
function err(string $field): string {
    global $errors;
    if (isset($errors[$field])) {
        return '<span class="form-error">' . encode($errors[$field]) . '</span>';
    }
    return '';
}

/**
 * Generate standard HTML text input
 */
function html_text(string $name, $value = '', array $attrs = []): string {
    $attr_str = '';
    foreach ($attrs as $k => $v) {
        $attr_str .= ' ' . encode($k) . '="' . encode($v) . '"';
    }
    return '<input type="text" name="' . encode($name) . '" id="' . encode($name) . '" value="' . encode((string)$value) . '" class="form-control"' . $attr_str . '>';
}

/**
 * Generate standard HTML password input
 */
function html_password(string $name, array $attrs = []): string {
    $attr_str = '';
    foreach ($attrs as $k => $v) {
        $attr_str .= ' ' . encode($k) . '="' . encode($v) . '"';
    }
    return '<input type="password" name="' . encode($name) . '" id="' . encode($name) . '" class="form-control"' . $attr_str . '>';
}

/**
 * Generate standard HTML textarea
 */
function html_textarea(string $name, $value = '', array $attrs = []): string {
    $rows = $attrs['rows'] ?? 4;
    unset($attrs['rows']);
    $attr_str = '';
    foreach ($attrs as $k => $v) {
        $attr_str .= ' ' . encode($k) . '="' . encode($v) . '"';
    }
    return '<textarea name="' . encode($name) . '" id="' . encode($name) . '" rows="' . (int)$rows . '" class="form-control"' . $attr_str . '>' . encode((string)$value) . '</textarea>';
}

/**
 * Generate standard HTML select dropdown
 */
function html_select(string $name, array $options, $selected = '', string $default_label = '-- Select --', array $attrs = []): string {
    $attr_str = '';
    foreach ($attrs as $k => $v) {
        $attr_str .= ' ' . encode($k) . '="' . encode($v) . '"';
    }
    $html = '<select name="' . encode($name) . '" id="' . encode($name) . '" class="form-select"' . $attr_str . '>';
    if ($default_label !== '') {
        $html .= '<option value="">' . encode($default_label) . '</option>';
    }
    foreach ($options as $key => $label) {
        $is_selected = ((string)$key === (string)$selected) ? ' selected' : '';
        $html .= '<option value="' . encode((string)$key) . '"' . $is_selected . '>' . encode((string)$label) . '</option>';
    }
    $html .= '</select>';
    return $html;
}

/**
 * Generate radio buttons group
 */
function html_radios(string $name, array $options, $checked = ''): string {
    $html = '<div class="radio-group">';
    foreach ($options as $key => $label) {
        $id = $name . '_' . preg_replace('/[^a-zA-Z0-9_]/', '', (string)$key);
        $is_checked = ((string)$key === (string)$checked) ? ' checked' : '';
        $html .= '<label for="' . encode($id) . '" class="radio-label">';
        $html .= '<input type="radio" name="' . encode($name) . '" id="' . encode($id) . '" value="' . encode((string)$key) . '"' . $is_checked . '> ';
        $html .= '<span>' . encode((string)$label) . '</span>';
        $html .= '</label>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * Generate single checkbox
 */
function html_checkbox(string $name, $value = '1', bool $checked = false, string $label = '', array $attrs = []): string {
    $attr_str = '';
    foreach ($attrs as $k => $v) {
        $attr_str .= ' ' . encode($k) . '="' . encode($v) . '"';
    }
    $is_checked = $checked ? ' checked' : '';
    $id = $attrs['id'] ?? $name;
    $html = '<label for="' . encode($id) . '" class="checkbox-label">';
    $html .= '<input type="checkbox" name="' . encode($name) . '" id="' . encode($id) . '" value="' . encode((string)$value) . '"' . $is_checked . $attr_str . '> ';
    $html .= '<span>' . encode($label) . '</span>';
    $html .= '</label>';
    return $html;
}

/**
 * Generate file upload input
 */
function html_file(string $name, string $accept = '', array $attrs = []): string {
    $attr_str = '';
    if ($accept !== '') {
        $attr_str .= ' accept="' . encode($accept) . '"';
    }
    foreach ($attrs as $k => $v) {
        $attr_str .= ' ' . encode($k) . '="' . encode($v) . '"';
    }
    return '<input type="file" name="' . encode($name) . '" id="' . encode($name) . '" class="form-file"' . $attr_str . '>';
}

// ============================================================================
// HELPER FUNCTIONS: Security Logging & Brute-Force Monitoring
// ============================================================================

/**
 * Record a failed login attempt for an email
 */
function record_login_attempt(string $email): void {
    global $db, $config;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // 1. Log attempt into login_attempt table
    $stmt = $db->prepare('INSERT INTO `login_attempt` (`email`, `ip_address`) VALUES (?, ?)');
    $stmt->execute([$email, $ip]);

    // 2. Fetch user to update failed attempts
    $stmt = $db->prepare('SELECT `id`, `failed_attempts` FROM `user` WHERE `email` = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        $new_fails = $user->failed_attempts + 1;
        $locked_until = null;

        $max_fails    = (int)setting('max_login_fails', (string)$config['app']['max_login_fails']);
        $lock_minutes = (int)setting('lockout_minutes', (string)($config['app']['lockout_time'] / 60));

        if ($new_fails >= $max_fails) {
            // Lock user account for configured lockout duration
            $lock_seconds = $lock_minutes * 60;
            $locked_until = date('Y-m-d H:i:s', time() + $lock_seconds);
        }

        $update = $db->prepare('UPDATE `user` SET `failed_attempts` = ?, `locked_until` = ? WHERE `id` = ?');
        $update->execute([$new_fails, $locked_until, $user->id]);
    }
}

/**
 * Reset failed login attempts on successful login
 */
function reset_login_attempts(int $user_id): void {
    global $db;
    $stmt = $db->prepare('UPDATE `user` SET `failed_attempts` = 0, `locked_until` = NULL WHERE `id` = ?');
    $stmt->execute([$user_id]);
}

/**
 * Log outgoing email into email_log table
 */
function log_email(string $recipient, string $subject, string $body, string $status = 'Sent', string $type = 'system', ?int $sent_by = null): void {
    global $db;
    try {
        if ($type === 'auth') {
            $body = '[redacted - contains secure link]';
        }
        $stmt = $db->prepare('INSERT INTO `email_log` (`recipient`, `subject`, `body`, `status`, `type`, `sent_by`) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$recipient, $subject, $body, $status, $type, $sent_by]);
    } catch (Exception $e) {
        error_log('log_email error: ' . $e->getMessage());
    }
}

/**
 * Check if a member has a Delivered order containing a product that has not been reviewed yet
 *
 * @param int $user_id
 * @param int $product_id
 * @return int|null Order ID if reviewable, or null
 */
function find_reviewable_order(int $user_id, int $product_id): ?int {
    global $db;
    try {
        $stmt = $db->prepare('
            SELECT o.id 
            FROM `order` o
            JOIN `order_item` oi ON o.id = oi.order_id
            LEFT JOIN `review` r ON r.user_id = o.user_id AND r.product_id = oi.product_id AND r.order_id = o.id
            WHERE o.user_id = ? 
              AND oi.product_id = ? 
              AND o.status = "Delivered"
              AND r.id IS NULL
            ORDER BY o.id DESC
            LIMIT 1
        ');
        $stmt->execute([$user_id, $product_id]);
        $order_id = $stmt->fetchColumn();
        return $order_id ? (int)$order_id : null;
    } catch (Exception $e) {
        error_log('find_reviewable_order error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Reusable atomic order cancellation helper with inventory restoration
 *
 * @param int $order_id
 * @param int|null $user_id If provided, verifies the order belongs to this customer
 * @param string $actor 'Member' or 'Admin'
 * @return array ['ok' => bool, 'message' => string]
 */
function cancel_order(int $order_id, ?int $user_id = null, string $actor = 'Member'): array {
    global $db, $config;

    if ($order_id <= 0) {
        return ['ok' => false, 'message' => 'Invalid order ID.'];
    }

    try {
        $db->beginTransaction();

        // 1. Lock the order row to prevent race conditions
        $stmt = $db->prepare('SELECT * FROM `order` WHERE `id` = ? FOR UPDATE');
        $stmt->execute([$order_id]);
        $order = $stmt->fetch();

        if (!$order) {
            $db->rollBack();
            return ['ok' => false, 'message' => 'Order not found.'];
        }

        // 2. Ownership verification
        if ($user_id !== null && (int)$order->user_id !== $user_id) {
            $db->rollBack();
            return ['ok' => false, 'message' => 'You do not have permission to cancel this order.'];
        }

        // 3. Status validation: Only Pending or Processing can be cancelled
        if (!in_array($order->status, ['Pending', 'Processing'], true)) {
            $db->rollBack();
            return [
                'ok' => false,
                'message' => "Order #{$order->order_no} cannot be cancelled because its status is {$order->status}."
            ];
        }

        // 4. Determine new payment_status: 'Refunded' only if 'Paid', otherwise keep 'Unpaid'
        $new_payment_status = ($order->payment_status === 'Paid') ? 'Refunded' : $order->payment_status;

        // 5. Update master order record
        $up = $db->prepare('UPDATE `order` SET `status` = "Cancelled", `payment_status` = ?, `tracking_no` = NULL WHERE `id` = ?');
        $up->execute([$new_payment_status, $order_id]);

        // 5b. Record payment status as Refunded when a Paid order is cancelled
        if ($order->payment_status === 'Paid') {
            $up_pay = $db->prepare('UPDATE `payment` SET `status` = "Refunded" WHERE `order_id` = ?');
            $up_pay->execute([$order_id]);
        }

        // 6. Restore product stock quantities
        $items_stmt = $db->prepare('SELECT `product_id`, `quantity` FROM `order_item` WHERE `order_id` = ?');
        $items_stmt->execute([$order_id]);
        $order_items = $items_stmt->fetchAll();

        $restore_stock = $db->prepare('UPDATE `product` SET `stock` = `stock` + ? WHERE `id` = ?');
        foreach ($order_items as $item) {
            $restore_stock->execute([$item->quantity, $item->product_id]);
        }

        // 7. Retrieve customer info for email dispatch
        $u_stmt = $db->prepare('SELECT `name`, `email` FROM `user` WHERE `id` = ?');
        $u_stmt->execute([$order->user_id]);
        $customer = $u_stmt->fetch();

        $db->commit();

        // 8. Dispatch cancellation notice
        if ($customer && function_exists('send_mail')) {
            $refund_info = ($new_payment_status === 'Refunded')
                ? 'Your payment of RM ' . number_format((float)$order->final_amount, 2) . ' will be refunded to your original payment method.'
                : 'No payment was captured for this order.';

            $mail_body = "<p>Dear " . encode($customer->name) . ",</p>"
                . "<p>Your order <strong>#" . encode($order->order_no) . "</strong> has been cancelled by " . encode($actor) . ".</p>"
                . "<p>{$refund_info}</p>"
                . "<p>Thank you for shopping with sumthin.</p>";

            send_mail(
                $customer->email,
                $customer->name,
                "Order Cancelled - {$order->order_no}",
                $mail_body,
                'order',
                ($actor === 'Admin' && isset($_SESSION['user']) ? (int)$_SESSION['user']->id : null)
            );
        }

        $success_msg = ($new_payment_status === 'Refunded')
            ? "Order {$order->order_no} has been cancelled successfully. Your payment will be refunded."
            : "Order {$order->order_no} has been cancelled successfully.";

        return ['ok' => true, 'message' => $success_msg];
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $msg = app_error_message('Order cancellation failed. Please try again', $e);
        return ['ok' => false, 'message' => $msg];
    }
}

/**
 * Get total quantity of items in current user's shopping cart
 */
function cart_count(): int {
    global $db;
    if (!is_logged_in()) return 0;
    try {
        $stmt = $db->prepare('SELECT COALESCE(SUM(`quantity`), 0) FROM `cart_item` WHERE `user_id` = ?');
        $stmt->execute([$_SESSION['user']->id]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Retrieve system setting value from database with request-level caching
 */
function setting(string $key, string $default = ''): string {
    global $db;
    static $settings_cache = null;

    if ($settings_cache === null) {
        $settings_cache = [];
        try {
            $rows = $db->query('SELECT `key`, `value` FROM `setting`')->fetchAll();
            foreach ($rows as $r) {
                $settings_cache[$r->key] = $r->value;
            }
        } catch (Exception $e) {
            // Fallback gracefully
        }
    }

    return $settings_cache[$key] ?? $default;
}

// ============================================================================
// HELPER FUNCTIONS: Validation & Secure Media Uploads (TASK C1)
// ============================================================================

/**
 * Validate full name length
 */
function validate_name(string $name, int $min = 3, int $max = 100): string {
    $trimmed = trim($name);
    if ($trimmed === '') {
        return 'Full name is required.';
    }
    $len = mb_strlen($trimmed);
    if ($len < $min) {
        return "Full name must be at least {$min} characters.";
    }
    if ($len > $max) {
        return "Full name cannot exceed {$max} characters.";
    }
    return '';
}

/**
 * Validate Malaysian mobile phone format
 * Format: 01X-XXXXXXX or 01XXXXXXXX or +601XXXXXXXX
 */
function validate_phone(string $phone): string {
    $trimmed = trim($phone);
    if ($trimmed === '') {
        return 'Phone number is required.';
    }
    if (!preg_match('/^(\+?6?01)[0-46-9]-*[0-9]{7,8}$/', $trimmed)) {
        return 'Please enter a valid Malaysian mobile number (e.g. 012-3456789).';
    }
    return '';
}

/**
 * Validate password complexity: min 8 characters, at least 1 uppercase, 1 lowercase, 1 digit
 */
function validate_password(string $password, int $min = 8): string {
    if ($password === '') {
        return 'Password is required.';
    }
    if (strlen($password) < $min) {
        return "Password must be at least {$min} characters long.";
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must contain at least one uppercase letter (A-Z).';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Password must contain at least one lowercase letter (a-z).';
    }
    if (!preg_match('/[0-9]/', $password)) {
        return 'Password must contain at least one number (0-9).';
    }
    return '';
}

/**
 * Standard Malaysian states list
 */
function get_malaysian_states(): array {
    return [
        'Wilayah Persekutuan' => 'Wilayah Persekutuan (KL / Putrajaya / Labuan)',
        'Selangor'            => 'Selangor',
        'Penang'              => 'Penang',
        'Johor'               => 'Johor',
        'Perak'               => 'Perak',
        'Melaka'              => 'Melaka',
        'Negeri Sembilan'     => 'Negeri Sembilan',
        'Kedah'               => 'Kedah',
        'Pahang'              => 'Pahang',
        'Terengganu'          => 'Terengganu',
        'Kelantan'            => 'Kelantan',
        'Sabah'               => 'Sabah',
        'Sarawak'             => 'Sarawak',
    ];
}

/**
 * Handle uploaded image with security validation (error codes, size limit, extension whitelist, finfo MIME check)
 *
 * @param string|array $field The key in $_FILES, or a single normalized file array
 * @param string $folder Relative folder name under uploads/ (e.g. 'members' or 'products') or full path
 * @param string $prefix Prefix for generated filename (e.g. 'user' or 'sumthin')
 * @param string|null &$error Reference to error string output
 * @param int $max_mb Maximum allowed size in megabytes (default 2)
 * @param int|null $index Optional index if $field is an array in $_FILES (e.g. photos[])
 * @return string|null Generated filename on success, or null on error or when no file uploaded
 */
function save_uploaded_image($field, string $folder, string $prefix, ?string &$error = null, int $max_mb = 2, ?int $index = null): ?string {
    if (is_array($field) && isset($field['tmp_name'])) {
        $file = $field;
    } elseif (is_string($field)) {
        if (!isset($_FILES[$field])) {
            return null;
        }
        if ($index !== null) {
            if (!isset($_FILES[$field]['name'][$index])) {
                return null;
            }
            $file = [
                'name'     => $_FILES[$field]['name'][$index],
                'type'     => $_FILES[$field]['type'][$index] ?? '',
                'tmp_name' => $_FILES[$field]['tmp_name'][$index] ?? '',
                'error'    => $_FILES[$field]['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $_FILES[$field]['size'][$index] ?? 0,
            ];
        } else {
            $file = $_FILES[$field];
        }
    } else {
        return null;
    }

    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $clean_original_name = basename($file['name']);

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = "File '{$clean_original_name}' upload error (Code: " . (int)$file['error'] . ").";
        return null;
    }

    $max_bytes = $max_mb * 1024 * 1024;
    if ($file['size'] > $max_bytes) {
        $error = "File '{$clean_original_name}' size cannot exceed {$max_mb} MB.";
        return null;
    }

    $allowed_exts  = ['jpg', 'jpeg', 'png', 'webp'];
    $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp'];

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_exts, true)) {
        $error = "File '{$clean_original_name}': Allowed file formats: JPG, JPEG, PNG, WEBP.";
        return null;
    }

    // Verify actual MIME type using finfo
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowed_mimes, true)) {
        $error = "File '{$clean_original_name}': Invalid image content detected. Upload rejected.";
        return null;
    }

    // Determine target upload directory
    $upload_dir = str_contains($folder, DIRECTORY_SEPARATOR) || str_contains($folder, '/') 
        ? rtrim($folder, '/\\') 
        : __DIR__ . '/uploads/' . trim($folder, '/\\');

    if (!is_dir($upload_dir)) {
        @mkdir($upload_dir, 0755, true);
    }

    $filename = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $destination = $upload_dir . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        $error = "Failed to save uploaded file '{$clean_original_name}' to disk.";
        return null;
    }

    return $filename;
}

/**
 * Safely delete an uploaded image file, preserving defaults
 *
 * @param string $folder Subfolder under uploads/ or full path
 * @param string|null $filename File basename
 */
function delete_uploaded_image(string $folder, ?string $filename): void {
    if (!$filename) return;
    $clean_name = basename($filename);
    if ($clean_name === '' || str_starts_with($clean_name, 'default')) {
        return;
    }

    $target_dir = str_contains($folder, DIRECTORY_SEPARATOR) || str_contains($folder, '/')
        ? rtrim($folder, '/\\')
        : __DIR__ . '/uploads/' . trim($folder, '/\\');

    $full_path = $target_dir . DIRECTORY_SEPARATOR . $clean_name;
    if (file_exists($full_path) && is_file($full_path)) {
        @unlink($full_path);
    }
}
