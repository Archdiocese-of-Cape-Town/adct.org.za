<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Publishing;

use ADCT\ParishIntake\Core\Publishing\SourceAttachment;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The value object behind issue #172's promotion decision.
 *
 * Two properties matter more than the shape. First, a role is a closed set, so a
 * posted role string cannot become an arbitrary meta value that the front end
 * then has to interpret. Second, and this is the trap: nothing here derives
 * anything from a parish's filename. The stored name is decided elsewhere, and
 * the original filename is carried purely so it can be escaped on render.
 */
final class SourceAttachmentTest extends TestCase
{
    public function testItCarriesTheIdentityRoleAndTheOriginalName(): void
    {
        $source = new SourceAttachment(41, 12, 'poster', 'Parish Notice Oct.pdf');

        self::assertSame(41, $source->attachmentId);
        self::assertSame(12, $source->mediaId);
        self::assertSame('poster', $source->role);
        self::assertSame('Parish Notice Oct.pdf', $source->originalFilename);
    }

    #[DataProvider('roles')]
    public function testTheThreeRolesAreAccepted(string $role): void
    {
        $source = new SourceAttachment(1, 2, $role, 'a.jpg');

        self::assertSame($role, $source->role);
    }

    public static function roles(): iterable
    {
        yield 'poster' => ['poster'];
        yield 'bulletin' => ['bulletin'];
        yield 'document' => ['document'];
    }

    #[DataProvider('rejectedRoles')]
    public function testAnUnknownRoleIsRefusedRatherThanStored(string $role): void
    {
        // Without this the role travels as an arbitrary string into post meta and
        // every reader downstream has to decide what an unknown role means.
        $this->expectException(DomainException::class);

        new SourceAttachment(1, 2, $role, 'a.jpg');
    }

    public static function rejectedRoles(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['POSTER'];
        yield 'padded' => [' poster '];
        yield 'sql-ish' => ['poster"; drop'];
        yield 'unknown word' => ['thumbnail'];
    }

    #[DataProvider('rejectedIds')]
    public function testANonPositiveIdIsRefused(int $attachmentId, int $mediaId): void
    {
        $this->expectException(DomainException::class);

        new SourceAttachment($attachmentId, $mediaId, 'poster', 'a.jpg');
    }

    public static function rejectedIds(): iterable
    {
        yield 'zero attachment id' => [0, 2];
        yield 'negative attachment id' => [-1, 2];
        yield 'zero media id' => [1, 0];
        yield 'negative media id' => [1, -5];
    }

    /**
     * The original filename is parish-supplied text. It is kept, because a
     * reviewer needs to recognise the file they sent, but it is kept as data
     * only: this object exposes no path, and nothing may be built from it. The
     * assertion is on the type rather than on a regex, so a later change that
     * quietly starts deriving a directory name from it fails here rather than on
     * a live upload directory.
     */
    public function testTheOriginalFilenameIsCarriedAsTextAndNeverAsAPath(): void
    {
        $source = new SourceAttachment(1, 2, 'poster', '../../wp-config.php');

        self::assertSame('../../wp-config.php', $source->originalFilename);
        self::assertFalse(method_exists($source, 'path'));
        self::assertFalse(method_exists($source, 'storedName'));
    }

    public function testThePromotionShapeCanBeReadBackForTheOrderedMeta(): void
    {
        // The exact array the ordered post meta stores. Keeping it on the value
        // object means the writer and the reader cannot disagree about the shape.
        $source = new SourceAttachment(41, 12, 'poster', 'a.jpg');

        self::assertSame(
            ['media_id' => 12, 'role' => 'poster', 'original_filename' => 'a.jpg', 'attachment_id' => 41],
            $source->toMetaEntry()
        );
    }

    /**
     * The promotable set is deliberately narrower than the storage allowlist.
     * HEIC and HEIF are storable because an operator may need to open the file,
     * but promoting one would publish a link that no browser can render.
     */
    #[DataProvider('promotableMimeTypes')]
    public function testABrowserReadableTypeCanBePromoted(string $mimeType, string $expectedRole): void
    {
        self::assertTrue(SourceAttachment::isPromotableMimeType($mimeType));
        self::assertSame($expectedRole, SourceAttachment::defaultRoleFor($mimeType));
    }

    public static function promotableMimeTypes(): iterable
    {
        yield 'jpeg is a poster' => ['image/jpeg', 'poster'];
        yield 'png is a poster' => ['image/png', 'poster'];
        yield 'webp is a poster' => ['image/webp', 'poster'];
        yield 'pdf is a bulletin' => ['application/pdf', 'bulletin'];
        yield 'case is ignored' => ['IMAGE/JPEG', 'poster'];
        yield 'padded case is ignored' => [' image/png ', 'poster'];
    }

    #[DataProvider('unpromotableMimeTypes')]
    public function testAStorableButUnrenderableTypeIsNotPromotable(string $mimeType): void
    {
        // image/heic and image/heif pass AttachmentStoragePolicy. Promotion does
        // not widen to them, so the refusal has one tested home.
        self::assertFalse(SourceAttachment::isPromotableMimeType($mimeType));
    }

    public static function unpromotableMimeTypes(): iterable
    {
        yield 'heic' => ['image/heic'];
        yield 'heif' => ['image/heif'];
        yield 'gif' => ['image/gif'];
        yield 'svg' => ['image/svg+xml'];
        yield 'tiff' => ['image/tiff'];
        yield 'anything else' => ['application/zip'];
        yield 'empty' => [''];
    }

    public function testAnUnknownTypeFallsBackToDocumentRatherThanBeingAGuess(): void
    {
        // The fallback is only reachable after isPromotableMimeType() has
        // already refused, so it names a role without implying permission.
        self::assertSame('document', SourceAttachment::defaultRoleFor('image/heic'));
    }
}