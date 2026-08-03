<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/db_helper.php';
require_once __DIR__ . '/../../includes/otp_helper.php';
require_once __DIR__ . '/../../includes/matching_helper.php';

class SecurityAuditFixesTest extends TestCase
{
    private ?mysqli $conn = null;

    protected function setUp(): void
    {
        global $conn;
        if (!$conn instanceof mysqli) {
            $this->markTestSkipped('Database connection unavailable for unit test execution.');
        }
        $this->conn = $conn;
    }

    public function testRidesyncIsUserSuspendedReturnsFalseForInvalidUser(): void
    {
        $result = ridesync_is_user_suspended($this->conn, 0);
        $this->assertFalse($result);
    }

    public function testRidesyncIsUserSuspendedWithValidUser(): void
    {
        // Fetch active or suspended user id from users table
        $row = ridesync_db_fetch_one($this->conn, "SELECT id, status FROM users LIMIT 1");
        if (!$row) {
            $this->markTestSkipped('No user row present in test database.');
        }

        $isSuspended = ridesync_is_user_suspended($this->conn, (int) $row['id']);
        $expected = ($row['status'] ?? 'active') === 'suspended';
        $this->assertSame($expected, $isSuspended);
    }

    public function testCompletePasswordResetRejectsInvalidResetId(): void
    {
        $res = ridesync_complete_password_reset(
            $this->conn,
            'rider',
            'nonexistent_audit_test@ridesync.test',
            'Password123!',
            99999999
        );

        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('OTP verification session expired or not found', $res['error']);
    }

    public function testColumnExistsCaching(): void
    {
        $exists1 = ridesync_column_exists($this->conn, 'users', 'email');
        $exists2 = ridesync_column_exists($this->conn, 'users', 'email');
        $this->assertTrue($exists1);
        $this->assertTrue($exists2);
    }
}
