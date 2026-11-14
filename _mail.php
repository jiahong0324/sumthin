<?php
// ============================================================================
// File: _mail.php
// Project: sumthin (Tech Gadget Online Store)
// Module: Notification & Communication
// Description: Centralized transactional email dispatcher using PHPMailer & Gmail SMTP.
//              Always records an audit log in database `email_log` for tracking.
// ============================================================================

require_once __DIR__ . '/lib/phpmailer/Exception.php';
require_once __DIR__ . '/lib/phpmailer/PHPMailer.php';
require_once __DIR__ . '/lib/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Send an email using PHPMailer with Gmail SMTP and audit log in database
 *
 * @param string $to Recipient email address
 * @param string $to_name Recipient display name
 * @param string $subject Subject line
 * @param string $html_body Inner HTML content
 * @param string $type Classification (e.g. 'system', 'auth', 'order', 'support')
 * @param int|null $sent_by Admin user ID initiating the email, or null for system
 * @return bool True if sent successfully via SMTP, false otherwise
 */
function send_mail(
    string $to,
    string $to_name,
    string $subject,
    string $html_body,
    string $type = 'system',
    ?int $sent_by = null
): bool {
    global $config;

    $mail_cfg = $config['mail'] ?? [];
    $enabled  = (bool)($mail_cfg['enabled'] ?? true);
    $debug    = (bool)($mail_cfg['debug'] ?? false);
    $store_name = $config['app']['name'] ?? 'sumthin';

    // 1. Wrap message inside responsive branded HTML template
    $full_html = "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>" . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . "</title>
</head>
<body style='margin:0; padding:0; background-color:#f8fafc; font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif; color:#1e293b;'>
    <table width='100%' cellpadding='0' cellspacing='0' style='background-color:#f8fafc; padding:28px 0;'>
        <tr>
            <td align='center'>
                <table width='100%' cellpadding='0' cellspacing='0' style='max-width:600px; background-color:#ffffff; border:1px solid #e2e8f0; border-radius:10px; overflow:hidden;'>
                    <!-- Brand Header -->
                    <tr>
                        <td style='background-color:#0f172a; padding:22px 30px;'>
                            <table width='100%' cellpadding='0' cellspacing='0'>
                                <tr>
                                    <td>
                                        <span style='background-color:#2563eb; color:#ffffff; font-weight:800; font-size:15px; padding:5px 11px; border-radius:6px; display:inline-block;'>S</span>
                                        <span style='color:#ffffff; font-weight:800; font-size:20px; margin-left:8px; vertical-align:middle;'>{$store_name}<span style='color:#3b82f6;'>.</span></span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <!-- Main Body -->
                    <tr>
                        <td style='padding:30px; font-size:14px; line-height:1.6; color:#334155;'>
                            {$html_body}
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style='background-color:#f1f5f9; padding:18px 30px; font-size:12px; color:#64748b; border-top:1px solid #e2e8f0;'>
                            &copy; " . date('Y') . " {$store_name}. All rights reserved.<br>
                            Curated Tech Gadgets & Daily Essentials
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>";

    // Plain text version for fallback & logging
    $plain_text = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], "\n", $html_body)));

    // 2. If mail dispatching is disabled, record simulated email to database and return false
    if (!$enabled) {
        log_email($to, $subject, $plain_text, 'Sent', $type, $sent_by);
        return false;
    }

    // 3. Dispatch email via PHPMailer
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $mail_cfg['smtp_host'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth   = (bool)($mail_cfg['smtp_auth'] ?? true);
        $mail->Username   = $mail_cfg['username'] ?? '';
        $mail->Password   = $mail_cfg['password'] ?? '';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)($mail_cfg['smtp_port'] ?? 587);
        $mail->CharSet    = 'UTF-8';

        if ($debug) {
            $mail->SMTPDebug = 2;
        }

        $from_email = $mail_cfg['from_email'] ?? 'no-reply@sumthin.com';
        $from_name  = $mail_cfg['from_name'] ?? 'sumthin Tech Gadgets';
        $mail->setFrom($from_email, $from_name);
        $mail->addAddress($to, $to_name);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $full_html;
        $mail->AltBody = $plain_text;

        $mail->send();
        log_email($to, $subject, $plain_text, 'Sent', $type, $sent_by);
        return true;
    } catch (PHPMailerException $e) {
        error_log("PHPMailer error sending to {$to}: " . $e->getMessage());
        log_email($to, $subject, $plain_text, 'Failed', $type, $sent_by);
        return false;
    } catch (\Throwable $e) {
        error_log("General mail error sending to {$to}: " . $e->getMessage());
        log_email($to, $subject, $plain_text, 'Failed', $type, $sent_by);
        return false;
    }
}
