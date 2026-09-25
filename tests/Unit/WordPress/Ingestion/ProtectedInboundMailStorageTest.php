<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Ingestion;

use ADCT\ParishIntake\WordPress\Ingestion\ProtectedInboundMailStorage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProtectedInboundMailStorageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'adct-pi-private-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (! is_dir($this->directory)) {
            return;
        }

        foreach (scandir($this->directory) ?: [] as $filename) {
            if ($filename === '.' || $filename === '..') {
                continue;
            }

            $path = $this->directory . DIRECTORY_SEPARATOR . $filename;

            if (is_file($path)) {
                unlink($path);
            }
        }

        rmdir($this->directory);
    }

    public function testWritesProtectedFilesWithUnguessableNames(): void
    {
        $storage = new ProtectedInboundMailStorage($this->directory);
        $rawPath = $storage->storeRawMessage("From: notices@example.test\r\n\r\nExample message.");
        $attachmentPath = $storage->storeAttachment("%PDF-1.7\nExample poster", 'pdf');

        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\.eml\z/', $rawPath);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\.pdf\z/', $attachmentPath);
        self::assertNotSame($rawPath, $attachmentPath);
        self::assertSame(
            "From: notices@example.test\r\n\r\nExample message.",
            file_get_contents($this->directory . DIRECTORY_SEPARATOR . $rawPath)
        );
        self::assertSame(
            "%PDF-1.7\nExample poster",
            file_get_contents($this->directory . DIRECTORY_SEPARATOR . $attachmentPath)
        );
        self::assertStringContainsString(
            'Require all denied',
            (string) file_get_contents($this->directory . DIRECTORY_SEPARATOR . '.htaccess')
        );
        self::assertFileExists($this->directory . DIRECTORY_SEPARATOR . 'index.php');

        $storage->delete($rawPath);
        self::assertFileDoesNotExist($this->directory . DIRECTORY_SEPARATOR . $rawPath);
    }

    public function testRejectsUnapprovedExtensionsAndArbitraryDeletePaths(): void
    {
        $storage = new ProtectedInboundMailStorage($this->directory);

        try {
            $storage->storeAttachment('content', 'php');
            self::fail('An unapproved attachment extension was accepted.');
        } catch (InvalidArgumentException $failure) {
            self::assertSame('The attachment extension is not permitted.', $failure->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $storage->delete('../private/example.eml');
    }

    public function testReadsOnlyExistingProtectedRawEmailFiles(): void
    {
        $storage = new ProtectedInboundMailStorage($this->directory);
        $rawMessage = "From: notices@example.test\r\n\r\nExample message.";
        $relativePath = $storage->storeRawMessage($rawMessage);

        self::assertSame($rawMessage, $storage->readRawMessage($relativePath));

        try {
            $storage->readRawMessage('../private/example.eml');
            self::fail('An arbitrary file path was accepted for reading.');
        } catch (InvalidArgumentException $failure) {
            self::assertSame('The private inbound email path is invalid.', $failure->getMessage());
        }

        $storage->delete($relativePath);

        $this->expectException(RuntimeException::class);
        $storage->readRawMessage($relativePath);
    }

    public function testCommentedDenyRuleDoesNotCountAsDirectoryProtection(): void
    {
        self::assertTrue(mkdir($this->directory, 0700, true));
        self::assertNotFalse(file_put_contents(
            $this->directory . DIRECTORY_SEPARATOR . '.htaccess',
            "# Require all denied\n"
        ));

        $storage = new ProtectedInboundMailStorage($this->directory);
        $storage->storeRawMessage("From: notices@example.test\r\n\r\nExample message.");
        $rules = (string) file_get_contents($this->directory . DIRECTORY_SEPARATOR . '.htaccess');

        self::assertMatchesRegularExpression('/^[\t ]*Require all denied[\t ]*$/mi', $rules);
    }
}
