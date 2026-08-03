<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/emergency_contact_helper.php';

class UserExperienceGapsTest extends TestCase
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

    public function testEmergencyContactCreationAndDeletion(): void
    {
        $testUserId = 999999;
        $name = 'Test Guardian';
        $relation = 'Parent';
        $phone = '+919876543210';

        // Clean up before test
        ridesync_delete_emergency_contact($this->conn, 'rider', $testUserId, 0);

        $addRes = ridesync_add_emergency_contact($this->conn, 'rider', $testUserId, $name, $relation, $phone, true);
        $this->assertTrue($addRes['ok']);
        $contactId = $addRes['contact_id'];

        $contacts = ridesync_get_user_emergency_contacts($this->conn, 'rider', $testUserId);
        $this->assertCount(1, $contacts);
        $this->assertSame($name, $contacts[0]['name']);
        $this->assertSame($phone, $contacts[0]['phone_number']);

        $delRes = ridesync_delete_emergency_contact($this->conn, 'rider', $testUserId, $contactId);
        $this->assertTrue($delRes['ok']);

        $remaining = ridesync_get_user_emergency_contacts($this->conn, 'rider', $testUserId);
        $this->assertCount(0, $remaining);
    }
}
