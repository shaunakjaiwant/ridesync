<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/view_helper.php';
require_once __DIR__ . '/../../includes/rider_experience_helper.php';

class AddressShortenerTest extends TestCase
{
    public function testShortensGeocodedAddressesAccurately(): void
    {
        $raw1 = 'SH37, Kanyadi, Beltangadi taluk, Dakshina Kannada, Karnataka, 574240, India';
        $short1 = ridesync_short_address($raw1);
        $this->assertSame('SH37, Kanyadi', $short1);

        $raw2 = 'NH73, Ujire, Beltangadi taluk, Dakshina Kannada, Karnataka, 574240, India';
        $short2 = ridesync_short_address($raw2);
        $this->assertSame('NH73, Ujire', $short2);

        $raw3 = 'Vajpayee circle, Sirsi, Shirasi Taluk, Uttara Kannada, Karnataka, 581401, India';
        $short3 = ridesync_short_address($raw3);
        $this->assertSame('Vajpayee circle, Sirsi', $short3);

        $raw4 = 'Alekha, Sirsi - Kumta Highway, K. S. Nissar Ahmed Nagara, Kovesara, Nilekani, Shirasi Taluk, Uttara Kannada, Karnataka, 581403, India';
        $short4 = ridesync_short_address($raw4);
        $this->assertSame('Alekha, Sirsi', $short4);
    }

    public function testPreservesCleanShortAddresses(): void
    {
        $simple = 'SDMIT Campus';
        $this->assertSame('SDMIT Campus', ridesync_short_address($simple));

        $empty = '';
        $this->assertSame('', ridesync_short_address($empty));

        $null = null;
        $this->assertSame('', ridesync_short_address($null));
    }

    public function testRendersAccessibleHtmlSpan(): void
    {
        $full = 'SH37, Kanyadi, Beltangadi taluk, Dakshina Kannada, Karnataka, 574240, India';
        $rendered = ridesync_render_short_address($full, 32, 'custom-badge');

        $this->assertStringContainsString('class="short-address custom-badge"', $rendered);
        $this->assertStringContainsString('title="SH37, Kanyadi, Beltangadi taluk, Dakshina Kannada, Karnataka, 574240, India"', $rendered);
        $this->assertStringContainsString('SH37, Kanyadi</span>', $rendered);
    }
}
