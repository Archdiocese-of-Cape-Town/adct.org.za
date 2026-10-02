<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Database;

use ADCT\ParishIntake\Core\Ingestion\AttachmentStoragePolicy;
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
