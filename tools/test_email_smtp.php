<?php
/**
 * Test SMTP and Email Delivery
 * Usage: php tools/test_email_smtp.php your-email@domain.com
 */
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../includes/email_helper.php';

$toEmail = $argv[1] ?? '';

if (empty($toEmail) || !ridesync_is_valid_email($toEmail)) {
    echo "Usage: php tools/test_email_smtp.php your_email@domain.com\n";
    exit(1);
}

echo "=== RideSync Email Delivery Tester ===\n";
echo "Recipient: {$toEmail}\n";
echo "SMTP Host: " . (ridesync_env('RIDESYNC_SMTP_HOST', 'NOT SET (using local log fallback)')) . "\n";
echo "SMTP User: " . (ridesync_env('RIDESYNC_SMTP_USER', 'NOT SET')) . "\n";
echo "SMTP Port: " . (ridesync_env('RIDESYNC_SMTP_PORT', '587')) . "\n\n";

$testCode = random_int(100000, 999999);
$subject = "RideSync OTP Email Test";
$body = "Hello!\n\nThis is a test email from RideSync.\nYour test OTP code is: {$testCode}\n\nIf you received this email, real email delivery is working!";

echo "Sending email...\n";
$result = ridesync_send_email($toEmail, $subject, $body, "RideSync Test");

if ($result) {
    echo "[SUCCESS] Email function completed.\n";
    echo "If SMTP is configured in .env, check your inbox/spam folder for {$toEmail}.\n";
    echo "If running offline, check storage/logs/email.log\n";
} else {
    echo "[FAIL] Email function failed.\n";
}
