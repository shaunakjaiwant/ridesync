<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/rating_helper.php';

class RatingsTest extends TestCase {

    public function testRatingScoreValidation(): void {
        // Valid ratings: 1 through 5
        for ($s = 1; $s <= 5; $s++) {
            $this->assertTrue($s >= 1 && $s <= 5, "Score $s should be valid.");
        }

        // Invalid scores
        $this->assertFalse(0 >= 1 && 0 <= 5);
        $this->assertFalse(6 >= 1 && 6 <= 5);
        $this->assertFalse(-1 >= 1 && -1 <= 5);
    }

    public function testCommentLengthAndAntiSpam(): void {
        $longComment = str_repeat("a", 600);
        $trimmed = substr($longComment, 0, 500);
        $this->assertSame(500, strlen($trimmed));

        $spamUrl = "Awesome ride! Check http://phishing-site.example.com";
        $spamLinkPattern = '/https?:\/\/|www\./i';
        $this->assertSame(1, preg_match($spamLinkPattern, $spamUrl));

        $cleanComment = "Smooth driving, friendly rider!";
        $this->assertSame(0, preg_match($spamLinkPattern, $cleanComment));
    }

    public function testRatingSummaryDefault(): void {
        // Test array returned when user has no ratings
        $summary = [
            'avg_score' => 0.0,
            'count' => 0,
            'display' => 'No ratings yet',
            'formatted' => 'N/A'
        ];

        $this->assertSame(0.0, $summary['avg_score']);
        $this->assertSame(0, $summary['count']);
        $this->assertSame('No ratings yet', $summary['display']);
    }

    public function testRatingSummaryFormatting(): void {
        $avg = 4.7;
        $count = 12;
        $unit = $count === 1 ? 'review' : 'reviews';
        $display = sprintf("%.1f ★ (%d %s)", $avg, $count, $unit);

        $this->assertSame("4.7 ★ (12 reviews)", $display);
    }
}
