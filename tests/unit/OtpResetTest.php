<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/otp_helper.php';

class OtpResetTest extends TestCase {

    private function getDbConn() {
        $host = ridesync_env('RIDESYNC_DB_HOST', '127.0.0.1');
        $user = ridesync_env('RIDESYNC_DB_USER', 'ridesync_app');
        $pass = ridesync_env('RIDESYNC_DB_PASSWORD', 'SiIoETwfWYRKceUzkA3p8V9gMdyjhN0C');
        $db   = ridesync_env('RIDESYNC_DB_NAME', 'ridesync_db');
        $port = (int) ridesync_env('RIDESYNC_DB_PORT', 3306);

        $conn = @mysqli_connect($host, $user, $pass, $db, $port);
        return $conn ?: null;
    }

    public function testOtpFormatAndRandomness(): void {
        $otp1 = ridesync_generate_otp();
        $otp2 = ridesync_generate_otp();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $otp1);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $otp2);
        $this->assertGreaterThanOrEqual(100000, (int) $otp1);
        $this->assertLessThanOrEqual(999999, (int) $otp1);
    }

    public function testInvalidOtpInputsRejection(): void {
        $dummyConn = $this->createMock(mysqli::class);

        // Invalid email or user identity
        $res1 = ridesync_create_password_reset_otp($dummyConn, 'rider', 0, 'invalid-email');
        $this->assertFalse($res1['ok']);

        // Non-numeric or wrong length OTP
        $res2 = ridesync_verify_password_reset_otp($dummyConn, 'rider', 'test@example.com', '12345');
        $this->assertFalse($res2['ok']);
        $this->assertStringContainsString('6-digit', $res2['error']);

        $res3 = ridesync_verify_password_reset_otp($dummyConn, 'rider', 'test@example.com', 'abcdef');
        $this->assertFalse($res3['ok']);

        // Password completion with short password
        $res4 = ridesync_complete_password_reset($dummyConn, 'rider', 'test@example.com', 'short');
        $this->assertFalse($res4['ok']);
        $this->assertStringContainsString('at least 8 characters', $res4['error']);
    }

    public function testDatabaseOtpFlowAndCooldown(): void {
        $conn = $this->getDbConn();
        if (!$conn) {
            $this->markTestSkipped('Database connection unavailable for live OTP integration test.');
            return;
        }

        $testEmail = 'unit.otp.test@ridesync.test';
        $testUserId = 88888;

        // Clean up previous test entries
        $stmt = mysqli_prepare($conn, "DELETE FROM password_resets WHERE email = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $testEmail);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }

        // 1. Create OTP
        $createRes = ridesync_create_password_reset_otp($conn, 'rider', $testUserId, $testEmail);
        $this->assertTrue($createRes['ok']);
        $this->assertNotEmpty($createRes['raw_otp']);

        // 2. Cooldown check (immediate second call should hit 60-second restriction)
        $coolRes = ridesync_create_password_reset_otp($conn, 'rider', $testUserId, $testEmail);
        $this->assertFalse($coolRes['ok']);
        $this->assertTrue($coolRes['cooldown'] ?? false);

        // 3. Verification with valid OTP
        $rawOtp = $createRes['raw_otp'];
        $verifyRes = ridesync_verify_password_reset_otp($conn, 'rider', $testEmail, $rawOtp);
        $this->assertTrue($verifyRes['ok']);
        $this->assertGreaterThan(0, $verifyRes['reset_id']);

        // Cleanup
        $stmt = mysqli_prepare($conn, "DELETE FROM password_resets WHERE email = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "s", $testEmail);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }

        mysqli_close($conn);
    }
}
