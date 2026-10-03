<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Ocr;

use ADCT\ParishIntake\Core\Ocr\OcrExtractionResult;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\WordPress\Database\DatabaseConnectionInterface;
use ADCT\ParishIntake\WordPress\Database\Repository\AttachmentRepository;
use ADCT\ParishIntake\WordPress\Ocr\WordPressImageOcrExtractionStore;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WordPressImageOcrExtractionStoreTest extends TestCase
{
    public function testAnImageThatHasNotBeenTriedIsOffered(): void
    {
        $database = new OcrStoreRecordingDatabase();
        $database->results = [
            [
                'id' => 11,
                'filename' => 'parish-retreat.jpg',
                'mime_type' => 'image/jpeg',
                'size_bytes' => 48213,
                'storage_path' => 'parish-retreat.jpg',
                'extraction_method' => 'none',
                'status' => 'pending',
            ],
        ];

        $pending = $this->store($database)->findPendingImagesForMessage(3);

        self::assertCount(1, $pending);
        self::assertSame(11, $pending[0]->id);
        self::assertSame('parish-retreat.jpg', $pending[0]->filename);
        self::assertSame(48213, $pending[0]->sizeBytes);
        self::assertSame('image/jpeg', $pending[0]->mimeType);
        self::assertTrue($pending[0]->needsExtraction());
    }

    /**
     * A row the query already filtered out can still reach here if a future
     * caller asks for a wider set, so the store does not rely on the SQL alone.
     */
    #[DataProvider('rowsThatMustNotBeOffered')]
    public function testARowThatHasAlreadyBeenDealtWithIsNotOfferedAgain(array $row): void
    {
        $database = new OcrStoreRecordingDatabase();
        $database->results = [$row];

        self::assertSame([], $this->store($database)->findPendingImagesForMessage(3));
    }

    public static function rowsThatMustNotBeOffered(): iterable
    {
        $base = [
            'id' => 11,
            'filename' => 'parish-retreat.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 48213,
            'storage_path' => 'parish-retreat.jpg',
        ];

        yield 'already read by OCR' => [$base + ['extraction_method' => 'ocr_external', 'status' => 'extracted']];
        yield 'deliberately skipped last time' => [$base + ['extraction_method' => 'none', 'status' => 'skipped_size']];
        yield 'stored but not yet available' => [$base + ['extraction_method' => 'none', 'status' => 'archived']];
    }

    public function testAnImpossibleMessageIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store(new OcrStoreRecordingDatabase())->findPendingImagesForMessage(0);
    }

    public function testReadTextIsStoredWithTheMethodThatProducedIt(): void
    {
        $database = new OcrStoreRecordingDatabase();

        $this->store($database)->recordResult(
            11,
            OcrExtractionResult::extracted("Retreat Day\n12 October 2026", OcrExtractionResult::METHOD_OCR_EXTERNAL)
        );

        $written = $database->writtenValues();

        self::assertSame([
            'extracted_text' => "Retreat Day\n12 October 2026",
            'extraction_method' => 'ocr_external',
            'status' => 'extracted',
            'updated_at' => '2026-10-03 22:05:00',
        ], $written);
        self::assertSame(11, $database->writtenId, 'the write is aimed at the attachment that was read');
            }

            /**
     * A skip has to be stored as deliberately as a success. Left as
     * `pending`/`none`, the next cron tick would send the same poster to the
     * paid third party again, every two hours, for as long as the message
     * stayed unread.
     */
    #[DataProvider('outcomesThatMustBeStored')]
    public function testASkipIsStoredSoTheSamePosterIsNotSentAgain(OcrExtractionResult $result): void
    {
        $database = new OcrStoreRecordingDatabase();

        $this->store($database)->recordResult(11, $result);

        $written = $database->writtenValues();

        self::assertSame($result->status, $written['status'] ?? null);
        self::assertNotSame('pending', $written['status'] ?? null, 'the row must leave the pending state');
        self::assertArrayHasKey(
            'extracted_text',
            $written,
            'a skip clears the column rather than leaving stale text'
        );
        self::assertNull($written['extracted_text'], 'a skip stores no text');
    }

    public static function outcomesThatMustBeStored(): iterable
    {
        yield 'over size' => [OcrExtractionResult::skippedSize(9_000_000, 15_000_000)];
        yield 'timed out' => [OcrExtractionResult::skippedTimeout(20)];
        yield 'daily cap spent' => [OcrExtractionResult::rateLimited('The daily OCR limit was reached.')];
        yield 'service failed' => [OcrExtractionResult::failed('The OCR service returned an error.')];
        yield 'nothing readable' => [OcrExtractionResult::noTextFound()];
    }

    public function testAnImpossibleAttachmentIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->store(new OcrStoreRecordingDatabase())->recordResult(0, OcrExtractionResult::noTextFound());
    }

    private function store(
        OcrStoreRecordingDatabase $database,
        ?DateTimeImmutable $now = null
    ): WordPressImageOcrExtractionStore {
        return new WordPressImageOcrExtractionStore(
            new AttachmentRepository($database),
            new OcrStoreFixedClock($now ?? new DateTimeImmutable('2026-10-03T22:05:00', new DateTimeZone('Africa/Johannesburg')))
        );
    }
}

final class OcrStoreFixedClock implements ClockInterface
{
    public function __construct(private readonly DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

final class OcrStoreRecordingDatabase implements DatabaseConnectionInterface
{
    /**
     * @var list<string>
     */
    public array $queries = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $results = [];

        /**
         * The values the repository handed to prepare(), in order.
         *
         * @var list<array<string, mixed>>
         */
        public array $prepared = [];

        /**
         * The row ID the repository wrote to, taken from the `WHERE id =` value.
         */
        public int $writtenId = 0;

        private string $error = '';

        public function prefix(): string
        {
            return 'wp_';
        }

        public function prepare(string $query, mixed ...$arguments): string
        {
            $expected = substr_count($query, '%s') + substr_count($query, '%d');

            if ($expected !== count($arguments)) {
                throw new \InvalidArgumentException(sprintf(
                    'wpdb::prepare() was called incorrectly: %d placeholders for %d arguments',
                    $expected,
                    count($arguments)
                ));
            }

            $this->prepared[] = ['query' => $query, 'arguments' => $arguments];

            return $query;
        }

        public function query(string $query): int|false
        {
            $this->queries[] = $query;

            if (preg_match('/\AUPDATE\b/', (string) $query) === 1) {
                $arguments = $this->prepared[count($this->prepared) - 1]['arguments'] ?? [];
                $last = $arguments[count($arguments) - 1] ?? 0;
                $this->writtenId = is_scalar($last) ? (int) $last : 0;
            }

            return 1;
        }

        /**
         * The values an UPDATE actually wrote, read back out of the recorded
         * arguments rather than out of a re-implementation of the query builder.
         *
         * @return array<string, mixed>
         */
        public function writtenValues(): array
        {
            $prepared = $this->prepared[count($this->prepared) - 1] ?? ['query' => '', 'arguments' => []];
            preg_match_all('/`([a-z_]+)` = (NULL|%s|%d)/', (string) $prepared['query'], $assignments, PREG_SET_ORDER);

            $values = [];
            $index = 0;

            foreach ($assignments as $assignment) {
                if ($assignment[2] === 'NULL') {
                    $values[$assignment[1]] = null;
                    continue;
                }

                $values[$assignment[1]] = $prepared['arguments'][$index] ?? null;
                $index++;
            }

            return $values;
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