<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Attachments;

use ADCT\ParishIntake\Core\Attachments\SourceMaterialRole;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SourceMaterialRoleTest extends TestCase
{
    public function testTheRoleListIsClosedAndOrdered(): void
    {
        self::assertSame(
            ['poster', 'bulletin', 'document'],
            SourceMaterialRole::values()
        );
    }

    /**
     * HEIC/HEIF pass the intake storage allowlist but render in no browser, so
     * a promoted copy of one would be a dead link on the public page. The issue
     * excludes them from promotion and shows them as unavailable.
     */
    #[DataProvider('unrenderableTypes')]
    public function testHiecAndOtherUnrenderableTypesCannotBePromoted(string $mimeType): void
    {
        foreach (SourceMaterialRole::values() as $role) {
            self::assertFalse(
                SourceMaterialRole::allows($role, $mimeType),
                sprintf('%s must not be promotable as %s', $mimeType, $role)
            );
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unrenderableTypes(): array
    {
        return [
            'heic' => ['image/heic'],
            'heif' => ['image/heif'],
            'svg' => ['image/svg+xml'],
            'gzip' => ['application/gzip'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'octet stream' => ['application/octet-stream'],
        ];
    }

    public function testAPosterMustBeABrowserRenderableImage(): void
    {
        self::assertTrue(SourceMaterialRole::allows(SourceMaterialRole::POSTER, 'image/jpeg'));
        self::assertTrue(SourceMaterialRole::allows(SourceMaterialRole::POSTER, 'image/png'));
        self::assertTrue(SourceMaterialRole::allows(SourceMaterialRole::POSTER, 'image/webp'));
        self::assertFalse(SourceMaterialRole::allows(SourceMaterialRole::POSTER, 'application/pdf'));
    }

    public function testABulletinMustBeAPdf(): void
    {
        self::assertTrue(SourceMaterialRole::allows(SourceMaterialRole::BULLETIN, 'application/pdf'));
        self::assertFalse(SourceMaterialRole::allows(SourceMaterialRole::BULLETIN, 'image/jpeg'));
    }

    public function testADocumentMayBeEitherPromotableType(): void
    {
        self::assertTrue(SourceMaterialRole::allows(SourceMaterialRole::DOCUMENT, 'application/pdf'));
        self::assertTrue(SourceMaterialRole::allows(SourceMaterialRole::DOCUMENT, 'image/png'));
        self::assertFalse(SourceMaterialRole::allows(SourceMaterialRole::DOCUMENT, 'image/heic'));
    }

    public function testTheMimeComparisonIgnoresCaseAndSurroundingSpace(): void
    {
        self::assertTrue(SourceMaterialRole::allows(SourceMaterialRole::POSTER, ' IMAGE/JPEG '));
        self::assertTrue(SourceMaterialRole::allows(SourceMaterialRole::BULLETIN, "\tApplication/PDF\n"));
    }

    public function testAnUnknownRoleIsNeverAllowed(): void
    {
        self::assertFalse(SourceMaterialRole::allows('thumbnail', 'image/jpeg'));
        self::assertFalse(SourceMaterialRole::allows('', 'application/pdf'));
    }

    public function testFromInputNormalisesKnownRolesAndRefusesTheRest(): void
    {
        self::assertSame('poster', SourceMaterialRole::fromInput('poster'));
        self::assertSame('poster', SourceMaterialRole::fromInput('  POSTER  '));
        self::assertNull(SourceMaterialRole::fromInput('thumbnail'));
        self::assertNull(SourceMaterialRole::fromInput(''));
        self::assertNull(SourceMaterialRole::fromInput(null));
        self::assertNull(SourceMaterialRole::fromInput(['poster']));
        self::assertNull(SourceMaterialRole::fromInput(1));
    }

    public function testEveryRoleHasAVisibleLabel(): void
    {
        foreach (SourceMaterialRole::values() as $role) {
            self::assertNotSame('', SourceMaterialRole::label($role));
        }
    }

    public function testAnUnknownRoleHasNoLabel(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SourceMaterialRole::label('thumbnail');
    }
}