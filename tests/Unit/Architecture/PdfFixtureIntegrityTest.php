<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Architecture;

use ADCT\ParishIntake\Tests\Support\PdfFixtureIntegrity;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PdfFixtureIntegrityTest extends TestCase
{
    public function testCommittedFixturesMatchTheirManifest(): void
    {
        PdfFixtureIntegrity::verify(dirname(__DIR__, 2) . '/fixtures/pdfs');
        self::assertTrue(true);
    }

    public function testDetectsCorruptionWithoutGitOrComposer(): void
    {
        $directory = sys_get_temp_dir() . '/adct-pdf-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $valid = "%PDF-1.4\nsynthetic\n";
        file_put_contents($directory . '/poster.pdf', str_replace("\n", "\r\n", $valid));
        file_put_contents($directory . '/sha256.json', json_encode(['poster.pdf' => hash('sha256', $valid)]));

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('PDF fixture integrity failed: poster.pdf');
            PdfFixtureIntegrity::verify($directory);
        } finally {
            unlink($directory . '/poster.pdf');
            unlink($directory . '/sha256.json');
            rmdir($directory);
        }
    }

    public function testMissingAndUnlistedFixturesAreRejected(): void
    {
        $directory = sys_get_temp_dir() . '/adct-pdf-' . bin2hex(random_bytes(8));
        mkdir($directory);
        file_put_contents($directory . '/extra.pdf', 'synthetic');
        file_put_contents($directory . '/sha256.json', json_encode(['missing.pdf' => hash('sha256', 'synthetic')]));

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('PDF fixture list differs');
            PdfFixtureIntegrity::verify($directory);
        } finally {
            unlink($directory . '/extra.pdf');
            unlink($directory . '/sha256.json');
            rmdir($directory);
        }
    }
}
