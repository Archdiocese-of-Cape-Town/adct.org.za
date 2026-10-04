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

    /**
     * rolesFor() drives the reviewer's role select, so it has to be exactly the
     * roles allows() would accept -- not a parallel list that can drift.
     */
    #[DataProvider('promotableTypes')]
    public function testTheRolesOfferedForATypeAreExactlyTheRolesItAllows(string $mimeType): void
    {
        $expected = array_values(array_filter(
            SourceMaterialRole::values(),
            static fn (string $role): bool => SourceMaterialRole::allows($role, $mimeType)
        ));

        self::assertSame($expected, SourceMaterialRole::rolesFor($mimeType));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function promotableTypes(): array
    {
        return [
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'webp' => ['image/webp'],
            'pdf' => ['application/pdf'],
            'jpeg written in caps' => ['IMAGE/JPEG'],
            'jpeg with padding' => ['  image/jpeg  '],
            'heic' => ['image/heic'],
            'unknown' => ['application/octet-stream'],
            'empty' => [''],
        ];
    }

    public function testAnImageIsOfferedThePosterRoleAndNeverTheBulletinRole(): void
    {
        self::assertContains(
            SourceMaterialRole::POSTER,
            SourceMaterialRole::rolesFor('image/jpeg'),
            'A JPEG is a poster; without this the main job of the feature is unreachable.'
        );
        self::assertNotContains(
            SourceMaterialRole::BULLETIN,
            SourceMaterialRole::rolesFor('image/jpeg'),
            'A JPEG is not a parish bulletin, and offering it teaches the reviewer nothing.'
        );
    }

    public function testAPdfIsOfferedTheBulletinRoleAndNeverThePosterRole(): void
    {
        self::assertContains(
            SourceMaterialRole::BULLETIN,
            SourceMaterialRole::rolesFor('application/pdf'),
            'The parish bulletin is the PDF case; it must be reachable.'
        );
        self::assertNotContains(
            SourceMaterialRole::POSTER,
            SourceMaterialRole::rolesFor('application/pdf'),
            'A PDF embedded as an image renders as a broken page.'
        );
    }

    public function testAPromotableTypeIsAlwaysOfferedTheDocumentRole(): void
    {
        foreach (['image/jpeg', 'image/png', 'image/webp', 'application/pdf'] as $mimeType) {
            self::assertContains(
                SourceMaterialRole::DOCUMENT,
                SourceMaterialRole::rolesFor($mimeType),
                sprintf('%s must be offerable as a plain document', $mimeType)
            );
        }
    }

    public function testAnUnpromotableTypeIsOfferedNothingAtAll(): void
    {
        foreach (['image/heic', 'image/heif', 'application/zip', '', 'nonsense'] as $mimeType) {
            self::assertSame(
                [],
                SourceMaterialRole::rolesFor($mimeType),
                sprintf('"%s" must be offered no role, or the form builds a refused request', $mimeType)
            );
        }
    }
}

