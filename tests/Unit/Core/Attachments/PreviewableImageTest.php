<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Attachments;

use ADCT\ParishIntake\Core\Attachments\PreviewableImage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PreviewableImageTest extends TestCase
{
    private const STORAGE_NAME = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90.jpg';

    public function testItAcceptsABrowserReadableImageAndNormalisesItsType(): void
    {
        $image = new PreviewableImage(7, 3, 'poster.jpg', self::STORAGE_NAME, 'image/jpeg', 2048);

        self::assertSame(7, $image->attachmentId);
        self::assertSame(3, $image->messageId);
        self::assertSame('poster.jpg', $image->filename);
        self::assertSame('image/jpeg', $image->mimeType);
        self::assertSame(2048, $image->sizeBytes);
    }

    public function testItAcceptsPngAndWebp(): void
    {
        $base = substr(self::STORAGE_NAME, 0, -4);

        $png = new PreviewableImage(1, 1, 'a.png', $base . '.png', 'image/png', 10);
        $webp = new PreviewableImage(1, 1, 'a.webp', $base . '.webp', 'image/webp', 10);

        self::assertSame('image/png', $png->mimeType);
        self::assertSame('image/webp', $webp->mimeType);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejectedMismatchedTypes(): iterable
    {
        $base = substr(self::STORAGE_NAME, 0, -4);

        yield 'a jpg extension declared as png' => [$base . '.jpg', 'image/png'];
        yield 'a png extension declared as a pdf' => [$base . '.png', 'application/pdf'];
        yield 'a declared type that is not an image at all' => [$base . '.jpg', 'text/html'];
    }

    #[DataProvider('rejectedMismatchedTypes')]
    public function testItRejectsATypeThatDoesNotMatchTheStoredFile(string $storageName, string $mimeType): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PreviewableImage(1, 1, 'a.jpg', $storageName, $mimeType, 10);
    }

    public function testItRejectsPdfBecauseTheServerSideExtractionHandlesIt(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PreviewableImage(
            1,
            1,
            'a.pdf',
            substr(self::STORAGE_NAME, 0, -4) . '.pdf',
            'application/pdf',
            10
        );
    }

    public function testItRejectsHeicBecauseBrowsersCannotRenderIt(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PreviewableImage(
            1,
            1,
            'a.heic',
            substr(self::STORAGE_NAME, 0, -4) . '.heic',
            'image/heic',
            10
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedStorageNames(): iterable
    {
        yield 'a path traversal' => ['../../../../wp-config.php'];
        yield 'an absolute path' => ['/etc/passwd'];
        yield 'a name that is not 64 hex characters' => ['poster.jpg'];
        yield 'uppercase hex' => [strtoupper(substr(self::STORAGE_NAME, 0, -4)) . '.jpg'];
        yield 'a second extension' => [self::STORAGE_NAME . '.php'];
        yield 'an unexpected extension' => [substr(self::STORAGE_NAME, 0, -4) . '.svg'];
    }

    #[DataProvider('rejectedStorageNames')]
    public function testItRejectsAStorageNameThisPluginDidNotWrite(string $storageName): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PreviewableImage(1, 1, 'a.jpg', $storageName, 'image/jpeg', 10);
    }

    public function testItRejectsIdentifiersThatAreNotPositive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PreviewableImage(0, 1, 'a.jpg', self::STORAGE_NAME, 'image/jpeg', 10);
    }

    public function testItRejectsASizeOfZeroOrLess(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PreviewableImage(1, 1, 'a.jpg', self::STORAGE_NAME, 'image/jpeg', 0);
    }

    public function testItAcceptsExactlyTheStorageCap(): void
    {
        $image = new PreviewableImage(
            1,
            1,
            'a.jpg',
            self::STORAGE_NAME,
            'image/jpeg',
            PreviewableImage::MAX_SIZE_BYTES
        );

        self::assertSame(15 * 1024 * 1024, $image->sizeBytes);
    }

    public function testItRejectsAnImageLargerThanTheStorageCap(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PreviewableImage(
            1,
            1,
            'a.jpg',
            self::STORAGE_NAME,
            'image/jpeg',
            PreviewableImage::MAX_SIZE_BYTES + 1
        );
    }
}
