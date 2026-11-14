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
    die('<div style="font-family:sans-serif;padding:30px;max-width:600px;margin:50px auto;border:1px solid #ef4444;background:#fef2f2;border-radius:8px;">'
        . '<h2 style="color:#b91c1c;margin-top:0;">Database Connection Error</h2>'
        . '<p style="color:#4b5563;">Could not connect to the database <strong>' . htmlspecialchars($config['db']['dbname']) . '</strong>.</p>'
        . '<p style="color:#6b7280;font-size:14px;"><strong>Tips:</strong> Ensure Apache & MySQL are running in XAMPP, and that you have imported <code>database/sumthin_db.sql</code> via phpMyAdmin.</p>'
        . '<pre style="background:#fff;padding:10px;border-radius:4px;border:1px solid #fca5a5;font-size:12px;overflow:auto;">' . htmlspecialchars($e->getMessage()) . '</pre>'
        . '</div>');
}

// 5. Global Error Collector for Server-Side Form Validation
$errors = [];

// 6. Automatic Authentication via "Remember Me" Cookie
if (empty($_SESSION['user']) && !empty($_COOKIE['sumthin_remember'])) {
    try {
        $token = $_COOKIE['sumthin_remember'];
        $rem_stmt = $db->prepare('SELECT * FROM `user` WHERE `remember_token` = ? AND `status` = ? LIMIT 1');
        $rem_stmt->execute([$token, 'Active']);
        $remembered_user = $rem_stmt->fetch();
        if ($remembered_user) {
            session_regenerate_id(true);
            unset($remembered_user->password_hash);
            $_SESSION['user'] = $remembered_user;
            $_SESSION['last_activity'] = time();
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
 * Verify submitted CSRF token on POST requests
 */
function verify_csrf(): void {
    if (is_post()) {
        $token = $_POST['csrf_token'] ?? '';
        if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(403);
            die('<h1 style="font-family:sans-serif;color:#dc2626;text-align:center;margin-top:100px;">403 Forbidden - Invalid or Expired CSRF Token</h1>');
        }
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
 * Enforce role-based access control on protected pages
 * @param string|array $allowed_roles e.g. 'Admin' or ['Admin', 'Member']
 */
function auth($allowed_roles): void {
    $roles = (array)$allowed_roles;

    // 1. Not logged in -> Save target URL and redirect to login
    if (!is_logged_in()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        set_flash('warning', 'Please sign in to access this page.');
        redirect(base_url('login.php'));
    }

    $current_role = $_SESSION['user']->role;

    // 2. Check if user's account is blocked
    if ($_SESSION['user']->status === 'Blocked') {
        session_unset();
        session_destroy();
        session_start();
        set_flash('error', 'Your account has been suspended by the administrator.');
        redirect(base_url('login.php'));
    }

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
function log_email(string $recipient, string $subject, string $body, string $status = 'Sent'): void {
    global $db;
    try {
        $stmt = $db->prepare('INSERT INTO `email_log` (`recipient`, `subject`, `body`, `status`) VALUES (?, ?, ?, ?)');
        $stmt->execute([$recipient, $subject, $body, $status]);
    } catch (Exception $e) {
        // Silent catch so email logging never crashes core transactions
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
