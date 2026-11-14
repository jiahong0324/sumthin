<?php
// ============================================================================
// File: logout.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Security (Logout)
// Description: Secure session destruction, cookie clearance, and safe redirect.
// ============================================================================
require_once __DIR__ . '/_base.php';

// 1. Clear database remember token if user is logged in
if (is_logged_in()) {
    $stmt = $db->prepare('UPDATE `user` SET `remember_token` = NULL WHERE `id` = ?');
    $stmt->execute([$_SESSION['user']->id]);
}

// 2. Unset all session variables
$_SESSION = [];

// 3. Clear session cookie from client
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// 4. Clear remember me cookie
if (isset($_COOKIE['sumthin_remember'])) {
    setcookie('sumthin_remember', '', time() - 3600, '/');
}

// 5. Destroy the server-side session
session_destroy();

// 6. Start new clean session for flash notification
session_start();
set_flash('info', 'You have been successfully signed out. Have a great day!');
redirect(base_url('login.php'));
