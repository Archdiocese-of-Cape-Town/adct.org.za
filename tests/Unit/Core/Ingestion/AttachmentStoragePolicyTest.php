<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Ingestion;

use ADCT\ParishIntake\Core\Ingestion\AttachmentStoragePolicy;
use ADCT\ParishIntake\Core\Ingestion\RawEmailAttachment;
use PHPUnit\Framework\TestCase;

final class AttachmentStoragePolicyTest extends TestCase
{
    /**
     * @dataProvider allowedAttachmentProvider
     */
    public function testAllowsConfiguredMimeTypesWhenTheirSignaturesMatch(
        string $mimeType,
        string $bytes,
        string $extension
    ): void {
        $prepared = (new AttachmentStoragePolicy())->prepare(
            new RawEmailAttachment('../Example poster.' . $extension, $mimeType, $bytes)
        );

        self::assertSame(AttachmentStoragePolicy::STATUS_PENDING, $prepared->status);
        self::assertSame($extension, $prepared->extension);
        self::assertSame('Example poster.' . $extension, $prepared->filename);
        self::assertSame(hash('sha256', $bytes), $prepared->contentHash);
    }

    public static function allowedAttachmentProvider(): array
    {
        return [
            'PDF' => ['application/pdf', "%PDF-1.7\nexample", 'pdf'],
            'JPEG' => ['image/jpeg', "\xFF\xD8\xFFexample", 'jpg'],
            'PNG' => ['image/png', "\x89PNG\r\n\x1A\nexample", 'png'],
            'WebP' => ['image/webp', "RIFF\x00\x00\x00\x00WEBPexample", 'webp'],
            'HEIC' => ['image/heic', "\x00\x00\x00\x18ftypheicexample", 'heic'],
            'HEIF' => ['image/heif', "\x00\x00\x00\x18ftypmif1example", 'heif'],
        ];
    }

    public function testSkipsAnAttachmentAboveThePerFileLimit(): void
    {
        $prepared = (new AttachmentStoragePolicy())->prepare(
            new RawEmailAttachment(
                'large.pdf',
                'application/pdf',
                "%PDF-" . str_repeat('x', AttachmentStoragePolicy::MAX_ATTACHMENT_SIZE_BYTES)
            )
        );

        self::assertSame(AttachmentStoragePolicy::STATUS_SKIPPED_SIZE, $prepared->status);
        self::assertNull($prepared->extension);
        self::assertSame(AttachmentStoragePolicy::MAX_ATTACHMENT_SIZE_BYTES + 5, $prepared->sizeBytes);
    }

    public function testSkipsUnsupportedMimeTypesAndMismatchedSignatures(): void
    {
        $policy = new AttachmentStoragePolicy();

        $unsupported = $policy->prepare(
            new RawEmailAttachment('poster.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'content')
        );
        $mismatched = $policy->prepare(
            new RawEmailAttachment('poster.pdf', 'application/pdf', 'not a PDF')
        );

        self::assertSame(AttachmentStoragePolicy::STATUS_SKIPPED_TYPE, $unsupported->status);
        self::assertSame(AttachmentStoragePolicy::STATUS_SKIPPED_SIGNATURE, $mismatched->status);
        self::assertSame(hash('sha256', 'content'), $unsupported->contentHash);
    }

    public function testSanitisesAttachmentNamesAndNormalisesMimeTypes(): void
    {
        $prepared = (new AttachmentStoragePolicy())->prepare(
            new RawEmailAttachment("..\\private\\poster.pdf\r\n", 'Application/PDF; name=poster.pdf', "%PDF-1.4")
        );

        self::assertSame('poster.pdf', $prepared->filename);
        self::assertSame('application/pdf', $prepared->mimeType);
    }
}
