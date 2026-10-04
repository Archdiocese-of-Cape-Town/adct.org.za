<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Database;

use ADCT\ParishIntake\Core\Ingestion\AttachmentStoragePolicy;
use ADCT\ParishIntake\Core\Ocr\OcrExtractionResult;
use ADCT\ParishIntake\Core\Pdf\PdfExtractionResult;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\AttachmentRepository;
use PHPUnit\Framework\TestCase;

final class AttachmentRepositoryTest extends TestCase
{
    public function testUnreadablePdfsCoverEveryStatusThatLeavesAnOperatorWorkToDo(): void
    {
        $database = new RecordingAttachmentDatabase();
        $database->results = [
            ['filename' => 'October-poster.pdf', 'status' => 'no_text_layer', 'updated_at' => '2026-09-20 06:00:00'],
        ];

        $rows = (new AttachmentRepository($database))->findRecentUnreadablePdfs(5);

        $arguments = $database->preparedArguments[0]['arguments'] ?? [];
        self::assertSame(PdfExtractionResult::MIME_TYPE, $arguments[0] ?? null);
        self::assertSame([
            PdfExtractionResult::STATUS_NO_TEXT_LAYER,
            PdfExtractionResult::STATUS_SKIPPED_SIZE,
            PdfExtractionResult::STATUS_SKIPPED_PAGE_LIMIT,
            PdfExtractionResult::STATUS_SKIPPED_TIMEOUT,
            PdfExtractionResult::STATUS_FAILED,
        ], array_slice($arguments, 1, 5));
        self::assertNotContains(
            PdfExtractionResult::STATUS_EXTRACTED,
            $arguments,
            'A PDF that produced text is not operator work.'
        );
        self::assertNotContains(
            AttachmentStoragePolicy::STATUS_SKIPPED_TYPE,
            $arguments,
            'A PDF that is not really a PDF is reported by the storage policy, not here.'
        );
        self::assertSame('October-poster.pdf', $rows[0]['filename'] ?? null);
    }

    public function testTheLimitIsBoundedSoTheAdminScreenCannotBeFlooded(): void
    {
        $database = new RecordingAttachmentDatabase();

        (new AttachmentRepository($database))->findRecentUnreadablePdfs(5_000);

        self::assertStringEndsWith('LIMIT %d', $database->preparedArguments[0]['query'] ?? '');
        self::assertSame(50, $database->preparedArguments[0]['arguments'][6] ?? null);
    }

    public function testAMissingLimitStillAsksForOneRow(): void
    {
        $database = new RecordingAttachmentDatabase();

        (new AttachmentRepository($database))->findRecentUnreadablePdfs(0);

        self::assertSame(1, $database->preparedArguments[0]['arguments'][6] ?? null);
    }

    /**
     * #181 regression: the query declared six status placeholders for five
     * status arguments. Every test in this file still passed, because the
     * double recorded the call without ever checking the counts -- which is
     * exactly what real wpdb::prepare() refuses to do.
     */
    public function testEveryPlaceholderHasExactlyOneArgument(): void
    {
        $database = new RecordingAttachmentDatabase();

        try {
            (new AttachmentRepository($database))->findRecentUnreadablePdfs(5);
        } catch (\Throwable $thrown) {
            self::fail('prepare() was given a mismatched query: ' . $thrown->getMessage());
        }

        $prepared = $database->preparedArguments[0] ?? null;
        self::assertNotNull($prepared);
        self::assertSame(
            substr_count((string) $prepared['query'], '%s') + substr_count((string) $prepared['query'], '%d'),
            count($prepared['arguments']),
            'wpdb::prepare() refuses to parameterise a query whose placeholder count '
            . 'does not match its argument count, and returns it unprepared.'
        );
    }

        public function testUnreadableImagesCoverEveryStatusThatLeavesAnOperatorWorkToDo(): void
        {
            $database = new RecordingAttachmentDatabase();
            $database->results = [
                ['filename' => 'parish-retreat.jpg', 'status' => 'no_text', 'updated_at' => '2026-09-20 06:00:00'],
            ];

            $rows = (new AttachmentRepository($database))->findRecentUnreadableImages(5);

            $arguments = $database->preparedArguments[0]['arguments'] ?? [];
            self::assertSame(['image/jpeg', 'image/png', 'image/webp'], array_slice($arguments, 0, 3));
            self::assertSame([
                OcrExtractionResult::STATUS_NO_TEXT,
                OcrExtractionResult::STATUS_SKIPPED_SIZE,
                OcrExtractionResult::STATUS_SKIPPED_TIMEOUT,
                OcrExtractionResult::STATUS_SKIPPED_RATE_LIMIT,
                OcrExtractionResult::STATUS_NOT_CONFIGURED,
                OcrExtractionResult::STATUS_FAILED,
            ], array_slice($arguments, 3, 6));
            self::assertNotContains(
                OcrExtractionResult::STATUS_EXTRACTED,
                $arguments,
                'An image that produced text is not operator work.'
            );
            self::assertStringEndsWith('LIMIT %d', $database->preparedArguments[0]['query'] ?? '');
            self::assertSame(5, $database->preparedArguments[0]['arguments'][9] ?? null);
            self::assertSame('parish-retreat.jpg', $rows[0]['filename'] ?? null);
        }

        public function testTheUnreadableImageLimitIsBoundedToo(): void
        {
            $database = new RecordingAttachmentDatabase();

            (new AttachmentRepository($database))->findRecentUnreadableImages(5_000);

            self::assertSame(50, $database->preparedArguments[0]['arguments'][9] ?? null);
        }

        public function testAMissingUnreadableImageLimitStillAsksForOneRow(): void
        {
            $database = new RecordingAttachmentDatabase();

            (new AttachmentRepository($database))->findRecentUnreadableImages(0);

            self::assertSame(1, $database->preparedArguments[0]['arguments'][9] ?? null);
        }

        public function testPendingImagesAreLimitedToOnesThatHaveNotBeenTried(): void
        {
            $database = new RecordingAttachmentDatabase();

            $rows = (new AttachmentRepository($database))->findPendingImagesForMessage(7);

            $query = $database->preparedArguments[0]['query'] ?? '';
            $arguments = $database->preparedArguments[0]['arguments'] ?? [];

            self::assertSame(7, $arguments[0] ?? null);
            self::assertSame(['image/jpeg', 'image/png', 'image/webp'], array_slice($arguments, 1, 3));
            self::assertSame(OcrExtractionResult::METHOD_NONE, $arguments[4] ?? null);
            self::assertSame('pending', $arguments[5] ?? null);
            self::assertStringContainsString('extraction_method = %s', $query);
            self::assertStringContainsString('status = %s', $query);
            self::assertStringContainsString("storage_path <> %s", $query);
            self::assertSame(
                substr_count($query, '%s') + substr_count($query, '%d'),
                count($arguments),
                'every placeholder needs exactly one argument'
            );
            self::assertSame([], $rows);
        }

        public function testPendingImagesRejectAnImpossibleMessageId(): void
        {
            $this->expectException(\InvalidArgumentException::class);

            (new AttachmentRepository(new RecordingAttachmentDatabase()))->findPendingImagesForMessage(0);
        }

        public function testThePendingImageQueryIsSafeOnBothMySqlAndMariaDb(): void
        {
            $database = new RecordingAttachmentDatabase();

            (new AttachmentRepository($database))->findPendingImagesForMessage(3);

            $query = (string) ($database->preparedArguments[0]['query'] ?? '');

            foreach (['REGEXP', 'JSON_', '->>', 'GROUP_CONCAT', 'ON DUPLICATE', 'WINDOW '] as $unsupported) {
                self::assertStringNotContainsString(
                    $unsupported,
                    $query,
                    sprintf('%s is not portable across MySQL 8 and MariaDB 10.11', $unsupported)
                );
            }

            self::assertStringEndsWith('ORDER BY id ASC', $query);
        }

                    /**
                     * Issue #172: the review queue's promote control needs the message's
                     * attachments that a person is allowed to publish as an event's source
                     * material.
                     */
                    public function testPromotableAttachmentsAreTheFourTypesAPublicPageCanServe(): void
                    {
                        $database = new RecordingAttachmentDatabase();

                        (new AttachmentRepository($database))->findPromotableForMessage(7);

                        $arguments = $database->preparedArguments[0]['arguments'] ?? [];

                        self::assertSame(7, $arguments[0] ?? null);
                        self::assertSame(
                            ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
                            array_slice($arguments, 1, 4)
                        );
                    }

                    /**
                     * HEIC and HEIF pass the intake storage allowlist but render in no
                     * browser, so promoting one would publish a dead link. The issue
                     * excludes them from promotion and shows them as unavailable.
                     */
                    public function testPromotableAttachmentsExcludeTheTypesNoBrowserRenders(): void
                    {
                        $database = new RecordingAttachmentDatabase();

                        (new AttachmentRepository($database))->findPromotableForMessage(7);

                        $arguments = $database->preparedArguments[0]['arguments'] ?? [];

                        self::assertNotContains('image/heic', $arguments);
                        self::assertNotContains('image/heif', $arguments);
                        self::assertNotContains('application/gzip', $arguments);
                    }

                    /**
                     * A row with no stored file has nothing to copy, so offering it would
                     * produce a promote button that can only fail.
                     */
                    public function testPromotableAttachmentsRequireAStoredFile(): void
                    {
                        $database = new RecordingAttachmentDatabase();

                        (new AttachmentRepository($database))->findPromotableForMessage(7);

                        $query = (string) ($database->preparedArguments[0]['query'] ?? '');

                        self::assertStringContainsString('storage_path IS NOT NULL', $query);
                        self::assertStringContainsString('storage_path <> %s', $query);
                    }

                    /**
                     * The promote control needs the intake row id and the parish's own
                     * filename; it has no use for a whole document's extracted text, which
                     * can be the entire bulletin.
                     */
                    public function testPromotableAttachmentsDoNotCarryTheExtractedText(): void
                    {
                        $database = new RecordingAttachmentDatabase();

                        (new AttachmentRepository($database))->findPromotableForMessage(7);

                        self::assertStringNotContainsString(
                            'extracted_text',
                            (string) ($database->preparedArguments[0]['query'] ?? '')
                        );
                    }

                    public function testPromotableAttachmentsAreOrderedAsTheyArrived(): void
                    {
                        $database = new RecordingAttachmentDatabase();

                        (new AttachmentRepository($database))->findPromotableForMessage(7);

                        self::assertStringEndsWith('ORDER BY id ASC', (string) ($database->preparedArguments[0]['query'] ?? ''));
                    }

                    public function testThePromotableQueryHasOneArgumentPerPlaceholder(): void
                    {
                        $database = new RecordingAttachmentDatabase();

                        try {
                            (new AttachmentRepository($database))->findPromotableForMessage(7);
                        } catch (\Throwable $thrown) {
                            self::fail('prepare() was given a mismatched query: ' . $thrown->getMessage());
                        }

                        $prepared = $database->preparedArguments[0] ?? null;

                        self::assertSame(
                            substr_count((string) $prepared['query'], '%s') + substr_count((string) $prepared['query'], '%d'),
                            count($prepared['arguments'])
                        );
                    }

                    public function testPromotableAttachmentsRejectAnImpossibleMessageId(): void
                    {
                        $this->expectException(\InvalidArgumentException::class);

                        (new AttachmentRepository(new RecordingAttachmentDatabase()))->findPromotableForMessage(0);
                    }

                    public function testThePromotableQueryIsSafeOnBothMySqlAndMariaDb(): void
                    {
                        $database = new RecordingAttachmentDatabase();

                        (new AttachmentRepository($database))->findPromotableForMessage(3);

                        $query = (string) ($database->preparedArguments[0]['query'] ?? '');

                        foreach (['REGEXP', 'JSON_', '->>', 'GROUP_CONCAT', 'ON DUPLICATE', 'WINDOW '] as $unsupported) {
                            self::assertStringNotContainsString(
                                $unsupported,
                                $query,
                                sprintf('%s is not portable across MySQL 8 and MariaDB 10.11', $unsupported)
                            );
                        }
                    }
                }

final class RecordingAttachmentDatabase implements DatabaseConnectionInterface
{
    /**
     * @var list<string>
     */
    public array $queries = [];

    /**
     * @var list<array{query: string, arguments: array<int, mixed>}>
     */
    public array $preparedArguments = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $results = [];

    private string $error = '';

    public function prefix(): string
    {
        return 'wp_';
    }

    public function prepare(string $query, mixed ...$arguments): string
    {
        $this->preparedArguments[] = ['query' => $query, 'arguments' => $arguments];

        $expected = substr_count($query, '%s') + substr_count($query, '%d');

        if ($expected !== count($arguments)) {
            throw new \InvalidArgumentException(sprintf(
                'wpdb::prepare() was called incorrectly: %d placeholders for %d arguments',
                $expected,
                count($arguments)
            ));
        }

        return $query;
    }

    public function query(string $query): int|false
    {
        $this->queries[] = $query;

        return 1;
    }

    public function getRow(string $query): ?array
    {
        return $this->results[0] ?? null;
    }

    public function getResults(string $query): array
    {
        $this->queries[] = $query;

        return $this->results;
    }

    public function escapeLike(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function insertId(): int
    {
        return 1;
    }

    public function charsetCollate(): string
    {
        return '';
    }

    public function clearLastError(): void
    {
        $this->error = '';
    }

    public function lastError(): string
    {
        return $this->error;
    }
}
