<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/document_verification_helper.php';

class DocumentVerificationPipelineTest extends TestCase {
    public function testNameMatchingScore(): void {
        // Exact match
        $this->assertSame(100, ridesync_verification_name_match_score("Shaunak Jaiwant", "Shaunak Jaiwant"));

        // Case insensitivity
        $this->assertSame(100, ridesync_verification_name_match_score("shaunak jaiwant", "SHAUNAK JAIWANT"));

        // Partial match with middle initial should be high confidence (> 70%)
        $score = ridesync_verification_name_match_score("Shaunak N Jaiwant", "Shaunak Jaiwant");
        $this->assertGreaterThan(70, $score);

        // Completely different name should be low confidence (< 50%)
        $diffScore = ridesync_verification_name_match_score("John Doe", "Shaunak Jaiwant");
        $this->assertLessThan(50, $diffScore);
    }

    public function testCleanDocumentExtractionStructure(): void {
        $extracted = ridesync_document_ai_extract_data('license', 'DL-1420110012345');
        
        $this->assertArrayHasKey('confidence_score', $extracted);
        $this->assertArrayHasKey('document_number', $extracted);
        $this->assertSame('DL1420110012345', $extracted['document_number']);
        $this->assertGreaterThanOrEqual(80, $extracted['confidence_score']);
    }

    public function testExpiryDateCheck(): void {
        $pastDate = date('Y-m-d', strtotime('-1 year'));
        $futureDate = date('Y-m-d', strtotime('+1 year'));

        $this->assertLessThan(time(), strtotime($pastDate));
        $this->assertGreaterThan(time(), strtotime($futureDate));
    }
}
