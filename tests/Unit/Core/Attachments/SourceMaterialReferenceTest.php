<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Attachments;

use ADCT\ParishIntake\Core\Attachments\SourceMaterialReference;
use ADCT\ParishIntake\Core\Attachments\SourceMaterialRole;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SourceMaterialReferenceTest extends TestCase
{
    public function testItKeepsTheAttachmentIdRoleAndParishFilename(): void
    {
        $reference = new SourceMaterialReference(42, SourceMaterialRole::BULLETIN, 'Maart bulletyn.pdf');

        self::assertSame(42, $reference->attachmentId);
        self::assertSame('bulletin', $reference->role);
        self::assertSame('Maart bulletyn.pdf', $reference->originalName);
        self::assertFalse($reference->isPoster());
    }

    public function testAPosterReferenceKnowsItIsThePoster(): void
    {
        $reference = new SourceMaterialReference(7, SourceMaterialRole::POSTER);

        self::assertTrue($reference->isPoster());
        self::assertSame('', $reference->originalName);
    }

    public function testANonPositiveAttachmentIdIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SourceMaterialReference(0, SourceMaterialRole::POSTER);
    }

    public function testAnUnknownRoleIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SourceMaterialReference(1, 'thumbnail');
    }

    public function testTheStoredShapeCarriesIdRoleAndName(): void
    {
        $reference = new SourceMaterialReference(9, SourceMaterialRole::DOCUMENT, 'Tydskrif.pdf');

        self::assertSame(
            ['attachment_id' => 9, 'role' => 'document', 'name' => 'Tydskrif.pdf'],
            $reference->toArray()
        );
    }

    public function testStoredPromotionMetaDecodesIntoOrderedReferences(): void
    {
        $stored = [
            ['attachment_id' => 30, 'role' => 'poster', 'name' => 'Poster.jpg'],
            ['attachment_id' => 31, 'role' => 'bulletin', 'name' => 'Bulletin.pdf'],
        ];

        $references = SourceMaterialReference::listFromStored($stored);

        self::assertCount(2, $references);
        self::assertSame(30, $references[0]->attachmentId);
        self::assertSame('poster', $references[0]->role);
        self::assertSame(31, $references[1]->attachmentId);
        self::assertSame('bulletin', $references[1]->role);
    }

    /**
     * The meta may reach the renderer as a JSON string when it was written by
     * `update_post_meta()` on an older path, and a hand-edited row may be
     * nonsense. Neither may hide the files that *are* valid.
     */
    public function testStoredPromotionMetaIsAlsoReadFromItsJsonStringForm(): void
    {
        $stored = json_encode([
            ['attachment_id' => 30, 'role' => 'poster', 'name' => 'Poster.jpg'],
        ]);

        $references = SourceMaterialReference::listFromStored($stored);

        self::assertCount(1, $references);
        self::assertSame(30, $references[0]->attachmentId);
    }

    public function testAnEventWithNoStoredPromotionMetaHasNoSourceMaterial(): void
    {
        self::assertSame([], SourceMaterialReference::listFromStored(null));
        self::assertSame([], SourceMaterialReference::listFromStored(''));
        self::assertSame([], SourceMaterialReference::listFromStored('not json'));
        self::assertSame([], SourceMaterialReference::listFromStored(false));
    }

    public function testAStoredMapRatherThanAListIsNotAValidPromotionList(): void
    {
        self::assertSame(
            [],
            SourceMaterialReference::listFromStored(['poster' => 30, 'bulletin' => 31])
        );
    }

    public function testACorruptEntryIsSkippedAndTheRestSurvive(): void
    {
        $stored = [
            ['attachment_id' => 30, 'role' => 'poster', 'name' => 'Poster.jpg'],
            'nonsense',
            ['attachment_id' => 0, 'role' => 'bulletin'],
            ['attachment_id' => 32, 'role' => 'thumbnail'],
            ['role' => 'bulletin'],
            ['attachment_id' => 33, 'role' => 'bulletin', 'name' => 'Bulletin.pdf'],
        ];

        $references = SourceMaterialReference::listFromStored($stored);

        self::assertCount(2, $references);
        self::assertSame(30, $references[0]->attachmentId);
        self::assertSame(33, $references[1]->attachmentId);
    }

    public function testANumericIdEntryIsReadAsADocument(): void
    {
        $references = SourceMaterialReference::listFromStored([44]);

        self::assertCount(1, $references);
        self::assertSame(44, $references[0]->attachmentId);
        self::assertSame('document', $references[0]->role);
    }

    public function testTheIdKeyMayBeSpelledAttachmentId(): void
    {
        $references = SourceMaterialReference::listFromStored([
            ['attachment_id' => 51, 'role' => 'poster'],
        ]);

        self::assertSame(51, $references[0]->attachmentId);
    }

    public function testANumericStringIdIsAccepted(): void
    {
        $references = SourceMaterialReference::listFromStored([
            ['attachment_id' => '52', 'role' => 'bulletin'],
        ]);

        self::assertSame(52, $references[0]->attachmentId);
    }

    public function testADuplicateIdCollapsesToItsFirstOccurrence(): void
    {
        $stored = [
            ['attachment_id' => 30, 'role' => 'poster', 'name' => 'Poster.jpg'],
            ['attachment_id' => 30, 'role' => 'bulletin', 'name' => 'Bulletin.pdf'],
        ];

        $references = SourceMaterialReference::listFromStored($stored);

        self::assertCount(1, $references);
        self::assertSame('poster', $references[0]->role);
    }

    public function testANonScalarNameIsReducedToAnEmptyString(): void
    {
        $references = SourceMaterialReference::listFromStored([
            ['attachment_id' => 30, 'role' => 'poster', 'name' => ['nested']],
        ]);

        self::assertSame('', $references[0]->originalName);
    }

    public function testReferencesEncodeBackIntoTheStoredShapeInOrder(): void
    {
        $stored = SourceMaterialReference::encodeStored([
            new SourceMaterialReference(30, SourceMaterialRole::POSTER, 'Poster.jpg'),
            new SourceMaterialReference(31, SourceMaterialRole::BULLETIN),
        ]);

        self::assertSame([
            ['attachment_id' => 30, 'role' => 'poster', 'name' => 'Poster.jpg'],
            ['attachment_id' => 31, 'role' => 'bulletin', 'name' => ''],
        ], $stored);
    }

    public function testReferencesSurviveAStoreRoundTrip(): void
    {
        $original = [
            new SourceMaterialReference(30, SourceMaterialRole::POSTER, 'Poster.jpg'),
            new SourceMaterialReference(31, SourceMaterialRole::BULLETIN, 'Bulletin.pdf'),
        ];

        $read = SourceMaterialReference::listFromStored(
            json_encode(SourceMaterialReference::encodeStored($original))
        );

        self::assertSame(
            array_map(static fn (SourceMaterialReference $r): array => $r->toArray(), $original),
            array_map(static fn (SourceMaterialReference $r): array => $r->toArray(), $read)
        );
    }
}