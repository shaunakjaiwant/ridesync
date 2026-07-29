<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/admin_helper.php';

class AdminOperationsTest extends TestCase {
    public function testRidesyncAdminActionCapability(): void {
        $this->assertSame('review_students', ridesync_admin_action_capability('user_verification_decision'));
        $this->assertSame('review_students', ridesync_admin_action_capability('user_account_status'));
        $this->assertSame('review_drivers', ridesync_admin_action_capability('driver_profile_decision'));
        $this->assertSame('manage_driver_accounts', ridesync_admin_action_capability('driver_account_status'));
        $this->assertSame('review_reports', ridesync_admin_action_capability('admin_force_cancel_ride'));
        $this->assertNull(ridesync_admin_action_capability('non_existent_action'));
    }

    public function testRidesyncAdminStatusLabel(): void {
        $this->assertSame('Active', ridesync_admin_status_label('active'));
        $this->assertSame('Pending', ridesync_admin_status_label('pending'));
        $this->assertSame('User Account Status', ridesync_admin_status_label('user_account_status'));
    }

    public function testRidesyncAdminBadgeClass(): void {
        $this->assertSame('accepted', ridesync_admin_badge_class('active'));
        $this->assertSame('accepted', ridesync_admin_badge_class('verified'));
        $this->assertSame('rejected', ridesync_admin_badge_class('suspended'));
        $this->assertSame('rejected', ridesync_admin_badge_class('rejected'));
        $this->assertSame('pending', ridesync_admin_badge_class('unverified'));
    }
}
