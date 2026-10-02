<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Pdf;

use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Pdf\PdfExtractionLimits;
use ADCT\ParishIntake\Core\Pdf\PdfExtractionResult;
use ADCT\ParishIntake\Core\Pdf\PdfTextEnrichmentService;
use ADCT\ParishIntake\Core\Pdf\StoredPdfAttachment;
use ADCT\ParishIntake\Core\Ports\AttachmentExtractionStoreInterface;
use ADCT\ParishIntake\Core\Jobs\JobRunner;
use ADCT\ParishIntake\Core\Ports\InboundMailStorageReaderInterface;
use ADCT\ParishIntake\Core\Ports\PdfTextExtractorInterface;
use ADCT\ParishIntake\Core\Ports\StopwatchInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PdfTextEnrichmentServiceTest extends TestCase
{
    private const MESSAGE_ID = 7;

    public function testAppendsThePdfTextToTheBody(): void
    {
        $store = new InMemoryExtractionStore([$this->storedPdf(1, 'October bulletin.pdf')]);
        $extractor = new StubExtractor([
            $this->storedName(1) => PdfExtractionResult::extracted(
                "Parish Retreat Day\nSaturday 17 October 2026, from 9am to 3pm",
                1
            ),
        ]);

        $result = $this->service($store, $extractor)->enrich(self::MESSAGE_ID, $this->message());

        self::assertTrue($result->enriched);
        self::assertStringContainsString('Please find the programme attached.', $result->message->getBody());
        self::assertStringContainsString(
            PdfTextEnrichmentService::SECTION_HEADING,
            $result->message->getBody()
        );
        self::assertStringContainsString('October bulletin.pdf', $result->message->getBody());
        self::assertStringContainsString('Saturday 17 October 2026, from 9am to 3pm', $result->message->getBody());
    }

    public function testKeepsTheEmailBodyAheadOfThePdfSection(): void
    {
        $store = new InMemoryExtractionStore([$this->storedPdf(1, 'Flyer.pdf')]);
        $extractor = new StubExtractor([
            $this->storedName(1) => PdfExtractionResult::extracted('Parish Quiz Evening', 1),
        ]);

        $body = $this->service($store, $extractor)->enrich(self::MESSAGE_ID, $this->message())->message->getBody();

        self::assertStringStartsWith('Please find the programme attached.', $body);
        self::assertStringContainsString('Parish Quiz Evening', $body);
    }

    public function testLeavesTheMessageAloneWhenThereAreNoPdfAttachments(): void
    {
        $message = $this->message();
        $result = $this->service(new InMemoryExtractionStore([]), new StubExtractor([]))
            ->enrich(self::MESSAGE_ID, $message);

        self::assertFalse($result->enriched);
        self::assertSame($message, $result->message);
        self::assertSame([], $result->notices);
    }

    public function testLeavesTheBodyAloneButStillReportsAnUnreadablePdf(): void
    {
        $store = new InMemoryExtractionStore([$this->storedPdf(1, 'Scanned poster.pdf')]);
        $extractor = new StubExtractor([
            $this->storedName(1) => PdfExtractionResult::noTextLayer(1),
        ]);

        $result = $this->service($store, $extractor)->enrich(self::MESSAGE_ID, $this->message());

        self::assertFalse($result->enriched);
        self::assertSame('Please find the programme attached.', $result->message->getBody());
        self::assertCount(1, $result->notices);
        self::assertSame('pdf_no_text_layer', $result->notices[0]->noticeKey);
        self::assertSame('Scanned poster.pdf', $result->notices[0]->filename);
    }

    public function testReportsAnOversizedPdfInsteadOfSilentlyIgnoringIt(): void
    {
        $store = new InMemoryExtractionStore([$this->storedPdf(1, 'Huge newsletter.pdf')]);
        $extractor = new StubExtractor([
            $this->storedName(1) => PdfExtractionResult::skippedSize(40 * 1024 * 1024, 15 * 1024 * 1024),
        ]);

        $result = $this->service($store, $extractor)->enrich(self::MESSAGE_ID, $this->message());

        self::assertFalse($result->enriched);
        self::assertSame('pdf_skipped_size', $result->notices[0]->noticeKey);
    }

    public function testRecordsTheOutcomeOnTheAttachmentRowForEveryFile(): void
    {
        $store = new InMemoryExtractionStore([
            $this->storedPdf(1, 'Readable.pdf'),
            $this->storedPdf(2, 'Scanned.pdf'),
        ]);
        $extractor = new StubExtractor([
            $this->storedName(1) => PdfExtractionResult::extracted('Confirmation Day', 1),
            $this->storedName(2) => PdfExtractionResult::noTextLayer(1),
        ]);

        $this->service($store, $extractor)->enrich(self::MESSAGE_ID, $this->message());

        self::assertSame(PdfExtractionResult::STATUS_EXTRACTED, $store->recorded[1]->status);
        self::assertSame('Confirmation Day', $store->recorded[1]->text);
        self::assertSame(PdfExtractionResult::STATUS_NO_TEXT_LAYER, $store->recorded[2]->status);
    }

    public function testSurvivesAnExtractorThatThrows(): void
    {
        $store = new InMemoryExtractionStore([$this->storedPdf(1, 'Broken.pdf')]);

        $result = $this->service($store, new ThrowingExtractor())->enrich(self::MESSAGE_ID, $this->message());

        self::assertFalse($result->enriched);
        self::assertSame('Please find the programme attached.', $result->message->getBody());
        self::assertCount(1, $result->notices);
    }

    public function testSurvivesAStoreThatCannotBeRead(): void
    {
        $result = $this->service(new ThrowingStore(), new StubExtractor([]))
            ->enrich(self::MESSAGE_ID, $this->message());

        self::assertFalse($result->enriched);
        self::assertSame([], $result->notices);
    }

    public function testReportsAPdfWhoseStoredFileHasGoneMissing(): void
    {
        $store = new InMemoryExtractionStore([$this->storedPdf(1, 'Flyer.pdf')]);
        $extractor = new StubExtractor([]);

        $result = (new PdfTextEnrichmentService(
            $store,
            $extractor,
            new StubInboundStorage(resolves: false),
            PdfExtractionLimits::defaults()
        ))->enrich(self::MESSAGE_ID, $this->message());

        self::assertFalse($result->enriched);
        self::assertSame('pdf_extraction_failed', $result->notices[0]->noticeKey);
        self::assertSame([], $extractor->requestedPaths, 'the extractor must not be given an unresolved path');
    }

    public function testHandsTheExtractorAnAbsolutePathItCanOpen(): void
    {
        $store = new InMemoryExtractionStore([$this->storedPdf(1, 'Flyer.pdf')]);
        $extractor = new StubExtractor([]);

        $this->service($store, $extractor)->enrich(self::MESSAGE_ID, $this->message());

        self::assertCount(1, $extractor->requestedPaths);
        self::assertStringStartsWith('/var/private/', $extractor->requestedPaths[0]);
        self::assertStringEndsWith('.pdf', $extractor->requestedPaths[0]);
    }

    public function testStillProducesTextWhenTheOutcomeCannotBeStored(): void
    {
        $store = new InMemoryExtractionStore([$this->storedPdf(1, 'Readable.pdf')], recordThrows: true);
        $extractor = new StubExtractor([
            $this->storedName(1) => PdfExtractionResult::extracted('Confirmation Day', 1),
        ]);

        $result = $this->service($store, $extractor)->enrich(self::MESSAGE_ID, $this->message());

        self::assertTrue($result->enriched);
        self::assertStringContainsString('Confirmation Day', $result->message->getBody());
    }

    public function testReadsFivePdfsFromOneMessage(): void
    {
        $attachments = [];
        $results = [];

        for ($id = 1; $id <= 5; ++$id) {
            $attachments[] = $this->storedPdf($id, "Flyer {$id}.pdf");
            $results[$this->storedName($id)] = PdfExtractionResult::extracted("Event number {$id}", 1);
        }

        $store = new InMemoryExtractionStore($attachments);
        $extractor = new StubExtractor($results);

        $body = $this->service($store, $extractor)->enrich(self::MESSAGE_ID, $this->message())
            ->message->getBody();

        for ($id = 1; $id <= 5; ++$id) {
            self::assertStringContainsString("Event number {$id}", $body);
        }
    }

    public function testStopsOpeningFilesOnceTheMessageTimeBudgetIsSpent(): void
    {
        $store = new InMemoryExtractionStore([
            $this->storedPdf(1, 'First.pdf'),
            $this->storedPdf(2, 'Second.pdf'),
        ]);
        $extractor = new StubExtractor([]);

        // The whole message budget is already gone before the first file opens.
        $result = $this->budgetedService($store, $extractor, new GrowingStopwatch(30.0, 0.0))
            ->enrich(self::MESSAGE_ID, $this->message());

        self::assertSame([], $extractor->requestedPaths, 'no file may be opened once the budget is spent');
        self::assertFalse($result->enriched);
        self::assertCount(2, $result->notices, 'each unread file must still reach the operator');
        self::assertSame('pdf_skipped_timeout', $result->notices[0]->noticeKey);
        self::assertSame('pdf_skipped_timeout', $result->notices[1]->noticeKey);
        self::assertSame(
            PdfExtractionResult::STATUS_SKIPPED_TIMEOUT,
            $store->recorded[1]->status,
            'the outcome must be stored, or a later run re-reads the file'
        );
    }

    public function testNarrowsThePerFileBudgetToWhatIsLeftOfTheMessageBudget(): void
    {
        $store = new InMemoryExtractionStore([
            $this->storedPdf(1, 'First.pdf'),
            $this->storedPdf(2, 'Second.pdf'),
        ]);
        $extractor = new StubExtractor([]);

        // 25 of the 30 message seconds are already spent. The first file is
        // left 5 seconds of its own 10, the second 4. Neither may be handed
        // the full per-file ceiling.
        $this->budgetedService($store, $extractor, new GrowingStopwatch(25.0, 1.0))
            ->enrich(self::MESSAGE_ID, $this->message());

        self::assertCount(2, $extractor->requestedPaths);
        self::assertSame(5, $extractor->requestedLimits[0]->timeBudgetSeconds);
        self::assertSame(4, $extractor->requestedLimits[1]->timeBudgetSeconds);
    }

    public function testKeepsTheFullPerFileCeilingForTheFileThatOpensEarly(): void
    {
        $store = new InMemoryExtractionStore([$this->storedPdf(1, 'Only.pdf')]);
        $extractor = new StubExtractor([]);

        $this->budgetedService($store, $extractor, new GrowingStopwatch(1.0, 1.0))
            ->enrich(self::MESSAGE_ID, $this->message());

        self::assertSame(
            PdfExtractionLimits::DEFAULT_TIME_BUDGET_SECONDS,
            $extractor->requestedLimits[0]->timeBudgetSeconds
        );
    }

    public function testKeepsFivePathologicalPdfsInsideTheProcessingJobBudget(): void
    {
        // Why the message budget exists. With a fresh budget per file, five PDFs
        // could each run to the 10 second ceiling and spend half of the job's
        // 60 seconds on one email.
        $limits = PdfExtractionLimits::defaults();

        self::assertSame(
            30,
            $limits->messageTimeBudgetSeconds,
            'the message budget is part of the documented job envelope'
        );
        self::assertLessThanOrEqual(
            JobRunner::DEFAULT_TIME_BUDGET_SECONDS,
            $limits->messageTimeBudgetSeconds + $limits->timeBudgetSeconds,
            'PDF enrichment must stay well inside the job budget'
        );
    }

    public function testReadsNoMoreAttachmentsThanThePerMessageCap(): void
    {
        $attachments = [];

        for ($id = 1; $id <= 6; ++$id) {
            $attachments[] = $this->storedPdf($id, "Flyer {$id}.pdf");
        }

        $store = new InMemoryExtractionStore($attachments);
        $extractor = new StubExtractor([]);

        $this->service($store, $extractor)->enrich(self::MESSAGE_ID, $this->message());

        // Pinned as a literal, not as the constant: asserting against the
        // symbol makes this test pass for any cap, so raising it has to be a
        // deliberate edit to this number.
        self::assertSame(5, PdfTextEnrichmentService::MAX_ATTACHMENTS_PER_MESSAGE);
        self::assertCount(5, $extractor->requestedPaths);
    }

    public function testJoinsSeveralExtractedPdfsWithABlankLineBetweenThem(): void
    {
        $store = new InMemoryExtractionStore([
            $this->storedPdf(1, 'First.pdf'),
            $this->storedPdf(2, 'Second.pdf'),
        ]);
        $extractor = new StubExtractor([
            $this->storedName(1) => PdfExtractionResult::extracted('Confirmation Day', 1),
            $this->storedName(2) => PdfExtractionResult::extracted('Choir Rehearsal', 1),
        ]);

        $body = $this->service($store, $extractor)->enrich(self::MESSAGE_ID, $this->message())
            ->message->getBody();

        self::assertStringContainsString("First.pdf\nConfirmation Day\n\nSecond.pdf\nChoir Rehearsal", $body);
    }

    private function service(
        AttachmentExtractionStoreInterface $store,
        PdfTextExtractorInterface $extractor
    ): PdfTextEnrichmentService {
        return new PdfTextEnrichmentService(
            $store,
            $extractor,
            new StubInboundStorage(),
            PdfExtractionLimits::defaults()
        );
    }

    private function budgetedService(
        AttachmentExtractionStoreInterface $store,
        PdfTextExtractorInterface $extractor,
        StopwatchInterface $stopwatch
    ): PdfTextEnrichmentService {
        return new PdfTextEnrichmentService(
            $store,
            $extractor,
            new StubInboundStorage(),
            PdfExtractionLimits::defaults(),
            $stopwatch
        );
    }

    private function storedPdf(int $id, string $filename): StoredPdfAttachment
    {
        return new StoredPdfAttachment($id, $filename, $this->storedName($id), 'pending', 'none');
    }

    /**
     * The storage adapter names stored files by content hash, so the tests
     * cannot invent their own paths here.
     */
    private function storedName(int $id): string
    {
        return str_pad(dechex($id), 64, 'a', STR_PAD_LEFT) . '.pdf';
    }

    private function message(): Message
    {
        return new Message('email', 'abc-123', 'office@example.org', 'Parish Office', 'October programme', 'Please find the programme attached.');
    }
}

/**
 * @param list<StoredPdfAttachment> $attachments
 */
final class InMemoryExtractionStore implements AttachmentExtractionStoreInterface
{
    /** @var array<int, PdfExtractionResult> */
    public array $recorded = [];

    /**
     * @param list<StoredPdfAttachment> $attachments
     */
    public function __construct(
        private readonly array $attachments,
        private readonly bool $recordThrows = false,
    ) {
    }

    public function findPendingPdfsForMessage(int $messageId): array
    {
        return $this->attachments;
    }

    public function recordResult(int $attachmentId, PdfExtractionResult $result): void
    {
        if ($this->recordThrows) {
            throw new \RuntimeException('The database write failed.');
        }

        $this->recorded[$attachmentId] = $result;
    }
}

final class ThrowingStore implements AttachmentExtractionStoreInterface
{
    public function findPendingPdfsForMessage(int $messageId): array
    {
        throw new \RuntimeException('The database is unreachable.');
    }

    public function recordResult(int $attachmentId, PdfExtractionResult $result): void
    {
        throw new \RuntimeException('The database is unreachable.');
    }
}

final class StubInboundStorage implements InboundMailStorageReaderInterface
{
    public const PRIVATE_DIRECTORY = '/var/private/';

    public function __construct(private readonly bool $resolves = true)
    {
    }

    public function resolveAttachmentPath(string $relativePath): string
    {
        if (! $this->resolves) {
            throw new RuntimeException('The stored inbound attachment is missing.');
        }

        return self::PRIVATE_DIRECTORY . $relativePath;
    }

    public function readRawMessage(string $relativePath): string
    {
        return '';
    }

    public function storeRawMessage(string $rawMessage): string
    {
        return '';
    }

    public function storeAttachment(string $content, string $extension): string
    {
        return '';
    }

    public function delete(string $relativePath): void
    {
    }
}

/**
 * @param array<string, PdfExtractionResult> $results keyed by stored file name
 */
final class StubExtractor implements PdfTextExtractorInterface
{
    /** @var list<string> */
    public array $requestedPaths = [];

    /** @var list<PdfExtractionLimits> */
    public array $requestedLimits = [];

    /**
     * @param array<string, PdfExtractionResult> $results
     */
    public function __construct(private readonly array $results)
    {
    }

    public function extract(string $absolutePath, PdfExtractionLimits $limits): PdfExtractionResult
    {
        $this->requestedPaths[] = $absolutePath;
        $this->requestedLimits[] = $limits;

        return $this->results[basename($absolutePath)] ?? PdfExtractionResult::noTextLayer(1);
    }
}

final class ThrowingExtractor implements PdfTextExtractorInterface
{
    public function extract(string $absolutePath, PdfExtractionLimits $limits): PdfExtractionResult
    {
        throw new \RuntimeException('The parser blew up.');
    }
}

/**
 * Advances by a fixed step on every reading, so a test can drive a time budget
 * outwards without sleeping.
 */
final class GrowingStopwatch implements StopwatchInterface
{
    private float $elapsed;

    public function __construct(float $elapsed, private readonly float $step)
    {
        $this->elapsed = $elapsed;
    }

    public function elapsedSeconds(): float
    {
        $elapsed = $this->elapsed;
        $this->elapsed += $this->step;

        return $elapsed;
    }
}
