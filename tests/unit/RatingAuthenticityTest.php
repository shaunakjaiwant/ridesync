<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/matching_helper.php';
require_once __DIR__ . '/../../includes/rider_experience_helper.php';

class RatingAuthenticityTest extends TestCase {
    public function testSpamLinkPatternDetection(): void {
        $spam1 = "Great ride! Check out https://spam-site.com";
        $spam2 = "Visit www.fake-reviews.com for free money";
        $clean = "Great driver, arrived on time and smooth driving!";

        $this->assertSame(1, preg_match('/https?:\/\/|www\./i', $spam1));
        $this->assertSame(1, preg_match('/https?:\/\/|www\./i', $spam2));
        $this->assertSame(0, preg_match('/https?:\/\/|www\./i', $clean));
    }

    public function testRepetitiveCharacterSpamPatternDetection(): void {
        $repetitive1 = "aaaaaaaaaaaaaaaa";
        $repetitive2 = "Awesome ride!!!!!!!!";
        $clean = "Nice experience, clean vehicle.";

        $this->assertSame(1, preg_match('/(.)\1{7,}/i', $repetitive1));
        $this->assertSame(1, preg_match('/(.)\1{7,}/i', $repetitive2));
        $this->assertSame(0, preg_match('/(.)\1{7,}/i', $clean));
    }

    public function testRatingSummaryDefaultValues(): void {
        $summary = ridesync_default_user_trust_summary();
        $this->assertNull($summary['rating_average']);
        $this->assertSame(0, $summary['rating_count']);
        $this->assertFalse($summary['verified']);
    }
}
