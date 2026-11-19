<?php
// ============================================================================
// File: _config.php
// Project: sumthin (Tech Gadget Online Store)
// Description: Centralized environment & secrets configuration.
// Replace placeholder values with your real credentials when testing APIs.
// ============================================================================

return [
    // Database Configuration (Default XAMPP settings)
    'db' => [
        'host'     => 'localhost',
        'port'     => '3306',
        'dbname'   => 'sumthin_db',
        'user'     => 'root',
        'password' => '', // XAMPP default is empty
        'charset'  => 'utf8mb4',
    ],

    // Application Settings
    'app' => [
        'name'            => 'sumthin',
        'tagline'         => 'Curated Tech Gadgets & Daily Essentials',
        'url'             => 'http://localhost/sumthin',
        'base_url'        => '/sumthin', // Project folder inside htdocs
        'session_timeout' => 1800,       // 30 minutes in seconds
        'max_login_fails' => 3,          // Lock account after 3 failed attempts
        'lockout_time'    => 900,        // Lock duration: 15 minutes (in seconds)
        'debug'           => false,      // Development debug mode: false for production
    ],

    // Gmail SMTP (For PHPMailer: password reset, verification, order notices)
    'mail' => [
        'enabled'    => true,            // Toggle email dispatching
        'debug'      => false,           // SMTP debug output
        'smtp_host'  => 'smtp.gmail.com',
        'smtp_port'  => 587,
        'smtp_auth'  => true,
        'username'   => 'YOUR_GMAIL_ADDRESS@gmail.com',
        'password'   => 'YOUR_GMAIL_16_CHAR_APP_PASSWORD',
        'from_name'  => 'sumthin Tech Gadgets',
        'from_email' => 'no-reply@sumthin.com',
    ],

    // Stripe API (Test Mode - Plain cURL)
    'stripe' => [
        'publishable_key' => 'pk_test_PLACEHOLDER_KEY',
        'secret_key'      => 'sk_test_PLACEHOLDER_KEY',
        'currency'        => 'myr', // Malaysian Ringgit
    ],

    // Google Gemini AI (Backend plain cURL shopping assistant)
    'gemini' => [
        'api_key'  => 'AIzaSy_GEMINI_API_KEY_PLACEHOLDER',
        'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent',
    ],

    // Datadog Logging & Monitoring (Backend plain cURL)
    'datadog' => [
        'api_key' => 'DATADOG_API_KEY_PLACEHOLDER',
        'site'    => 'datadoghq.com',
    ],

    // Cloudflare Turnstile Captcha (Configured with production keys for localhost)
    'captcha' => [
        'enabled'    => true,
        'site_key'   => '0x4AAAAAAGIxyeZU0-mr1hqm',
        'secret_key' => '0x4AAAAAAGIxyXdvJAIvUlGjp7B4UCSTiUA',
    ],
];
