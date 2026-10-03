<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Core\Ocr;

use ADCT\ParishIntake\Core\Jobs\JobRunner;
use ADCT\ParishIntake\Core\Ocr\OcrExtractionLimits;
use ADCT\ParishIntake\Core\Ocr\OcrExtractionResult;
use ADCT\ParishIntake\Core\Ocr\OcrTextEnrichmentService;
use ADCT\ParishIntake\Core\Ocr\StoredImageAttachment;
use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Ports\ImageExtractionStoreInterface;
use ADCT\ParishIntake\Core\Ports\InboundMailStorageReaderInterface;
use ADCT\ParishIntake\Core\Ports\OcrProviderInterface;
use ADCT\ParishIntake\Core\Ports\StopwatchInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * "Always falls back" is the whole point of optional OCR, so most of these
 * tests are about the ways it must NOT work: no opt-in, no API key, a size
 * limit, an unsupported type, a timeout, a rate limit, an unreadable
 * response or an outright exception. In every one of them the message must
 * still be parseable and the operator must still be told what happened.
 */
final class OcrTextEnrichmentServiceTest extends TestCase
{
    private const MESSAGE_ID = 7;

    public function testAppendsTheOcrTextToTheBody(): void
    {
        $store = new InMemoryImageExtractionStore([$this->storedImage(1, 'October poster.jpg')]);
        $provider = new StubOcrProvider([
            $this->storedName(1) => OcrExtractionResult::extracted(
                "Parish Retreat Day\nSaturday 17 October 2026, from 9am to 3pm"
            ),
        ]);

        $result = $this->service($store, $provider)->enrich(self::MESSAGE_ID, $this->message());

        self::assertTrue($result->enriched);
        self::assertStringContainsString('Please find the poster attached.', $result->message->getBody());
        self::assertStringContainsString(
            OcrTextEnrichmentService::SECTION_HEADING,
            $result->message->getBody()
        );
        self::assertStringContainsString('October poster.jpg', $result->message->getBody());
        self::assertStringContainsString('Saturday 17 October 2026, from 9am to 3pm', $result->message->getBody());
        self::assertSame([], $result->notices);
    }

    public function testKeepsTheEmailBodyAheadOfTheOcrSection(): void
    {
        $store = new InMemoryImageExtractionStore([$this->storedImage(1, 'Flyer.png')]);
        $provider = new StubOcrProvider([
            $this->storedName(1) => OcrExtractionResult::extracted('Parish Quiz Evening'),
        ]);

        $body = $this->service($store, $provider)->enrich(self::MESSAGE_ID, $this->message())->message->getBody();

        self::assertStringStartsWith('Please find the poster attached.', $body);
        self::assertStringContainsString('Parish Quiz Evening', $body);
    }

    public function testMarksTheOcrSectionSoItsOriginIsVisibleOnThePage(): void
    {
        $store = new InMemoryImageExtractionStore([$this->storedImage(1, 'Poster.jpg')]);
        $provider = new StubOcrProvider([
            $this->storedName(1) => OcrExtractionResult::extracted('Retreat Day'),
        ]);

        $body = $this->service($store, $provider)->enrich(self::MESSAGE_ID, $this->message())->message->getBody();

        // The literal marker is what the operator reads on the Manual parser
        // screen, so it is asserted on the text and not just the constant.
        self::assertStringContainsString('(OCR)', $body);
        self::assertStringNotContainsString('not OCR', $body);
    }

    public function testLeavesTheMessageAloneWhenThereAreNoImageAttachments(): void
    {
        $message = $this->message();
        $provider = new StubOcrProvider([]);

        $result = $this->service(new InMemoryImageExtractionStore([]), $provider)
            ->enrich(self::MESSAGE_ID, $message);

        self::assertFalse($result->enriched);
        self::assertSame($message, $result->message);
        self::assertSame([], $result->notices);
        self::assertSame([], $provider->requestedPaths, 'an email with no poster must not call OCR');
    }

    public function testFallsBackWhenTheOperatorHasNotOptedIn(): void
    {
        $store = new InMemoryImageExtractionStore([$this->storedImage(1, 'Poster.jpg')]);
        $provider = new StubOcrProvider([
            $this->storedName(1) => OcrExtractionResult::extracted('Retreat Day'),
        ]);

        $result = $this->service($store, $provider, enabled: false)
            ->enrich(self::MESSAGE_ID, $this->message());

        self::assertFalse($result->enriched);
        self::assertSame('Please find the poster attached.', $result->message->getBody());
        self::assertSame([], $provider->requestedPaths, 'declined opt-in must not leave the device');
        self::assertSame([], $result->notices, 'a deliberate opt-out is not an error worth reporting');
        self::assertSame(
            [],
            $store->recorded,
            'an untouched poster must stay pending so it can be read after opt-in'
        );
    }

    public function testTheOptInIsAskedAfreshForEveryMessage(): void
    {
        $optIn = false;
        $store = new InMemoryImageExtractionStore([$this->storedImage(1, 'Poster.jpg')]);
        $provider = new StubOcrProvider([
            $this->storedName(1) => OcrExtractionResult::extracted('Retreat Day'),
        ]);
        $service = $this->service($store, $provider, static function () use (&$optIn): bool {
            return $optIn;
        });

        $declined = $service->enrich(self::MESSAGE_ID, $this->message());
                $askedWhileDeclined = $provider->requestedPaths;

                $optIn = true;
                $accepted = $service->enrich(self::MESSAGE_ID, $this->message());

                self::assertFalse($declined->enriched);
                self::assertSame([], $declined->notices);
                self::assertSame([], $askedWhileDeclined);

                self::assertTrue($accepted->enriched, 'opting in must take effect without re-wiring the job');
                self::assertCount(1, $provider->requestedPaths);
    }

    public function testFallsBackWhenNoApiKeyIsConfigured(): void
    {
        $store = new InMemoryImageExtractionStore([$this->storedImage(1, 'Poster.jpg')]);
        $provider = new StubOcrProvider(
            [$this->storedName(1) => OcrExtractionResult::extracted('Retreat Day')],
            available: false
        );

        $result = $this->service($store, $provider)->enrich(self::MESSAGE_ID, $this->message());

        self::assertFalse($result->enriched);
        self::assertSame('Please find the poster attached.', $result->message->getBody());
        self::assertSame([], $provider->requestedPaths);
        self::assertCount(1, $result->notices);
        self::assertSame('ocr_not_configured', $result->notices[0]->noticeKey);
    }

    public function testFallsBackOnAnOversizedPosterWithoutOpeningIt(): void
    {
        $store = new InMemoryImageExtractionStore([
            new StoredImageAttachment(
                1,
                'Huge poster.jpg',
                $this->storedName(1),
                'pending',
                'none',
                OcrExtractionLimits::DEFAULT_MAX_BYTES + 1,
                'image/jpeg'
            ),
        ]);
        $provider = new StubOcrProvider([]);

        $result = $this->service($store, $provider)->enrich(self::MESSAGE_ID, $this->message());

        self::assertFalse($result->enriched);
        self::assertSame([], $provider->requestedPaths, 'an over-size image must never be read or sent');
        self::assertSame('ocr_skipped_size', $result->notices[0]->noticeKey);
        self::assertSame(OcrExtractionResult::STATUS_SKIPPED_SIZE, $store->recorded[1]->status);
    }

    public function testFallsBackOnAnUnsupportedImageType(): void
    {
        $store = new InMemoryImageExtractionStore([
            new StoredImageAttachment(1, 'Scan.heic', $this->storedName(1), 'pending', 'none', 2048, 'image/heic'),
        ]);
        $provider = new StubOcrProvider([]);

        $result = $this->service($store, $provider)->enrich(self::MESSAGE_ID, $this->message());

        self::assertFalse($result->enriched);
        self::assertSame([], $provider->requestedPaths);
        self::assertSame('ocr_skipped_type', $result->notices[0]->noticeKey);
    }

    public function testFallsBackWhenTheProviderTimesOut(): void
    {
        $store = new InMemoryImageExtractionStore([$this->storedImage(1, 'Poster.jpg')]);
        $provider = new StubOcrProvider([
            $this->storedName(1) => OcrExtractionResult::failed('The OCR service did not answer in time.'),
        ]);

        $result = $this->service($store, $provider)->enrich(self::MESSAGE_ID, $this->message());

        self::assertFalse($result->enriched);
        self::assertSame('Please find the poster attached.', $result->message->getBody());
        self::assertSame('ocr_failed', $result->notices[0]->noticeKey);
        self::assertStringContainsString('did not answer in time', $result->notices[0]->reason);
    }

    public function testFallsBackWhenTheProviderIsRateLimited(): void
    {
        $store = new InMemoryImageExtractionStore([$this->storedImage(1, 'Poster.jpg')]);
        $provider = new StubOcrProvider([
            $this->storedName(1) => OcrExtractionResult::rateLimited('The OCR service asked us to wait a while.'),
        ]);

        $result = $this->service($store, $provider)->enrich(self::MESSAGE_ID, $this->message());

        self::assertFalse($result->enriched);
        self::assertSame('ocr_rate_limited', $result->notices[0]->noticeKey);
        self::assertSame(OcrExtractionResult::STATUS_SKIPPED_RATE_LIMIT, $store->recorded[1]->status);
    }

    public function testFallsBackWhenTheProviderReturnsAnError(): void
    {
        $store = new InMemoryImageExtractionStore([$this->storedImage(1, 'Poster.jpg')]);
        $provider = new StubOcrProvider([
            $this->storedName(1) => OcrExtractionResult::failed('The OCR service returned an error.'),
        ]);

        $result = $this->service($store, $provider)->enrich(self::MESSAGE_ID, $this->message());

        self::assertFalse($result->enriched);
        self::assertSame('ocr_failed', $result->notices[0]->noticeKey);
    }

    public function testFallsBackWhenTheProviderFindsNoText(): void
    {
        $store = new InMemoryImageExtractionStore([$this->storedImage(1, 'Blank.png')]);
        $provider = new StubOcrProvider([
            $this->storedName(1) => OcrExtractionResult::noTextFound(),
        ]);

        $result = $this->service($store, $provider)->enrich(self::MESSAGE_ID, $this->message());

        self::assertFalse($result->enriched);
        self::assertSame('Please find the poster attached.', $result->message->getBody());
        self::assertSame('ocr_no_text', $result->notices[0]->noticeKey);
        self::assertStringNotContainsString(
            OcrTextEnrichmentService::SECTION_HEADING,
            $result->message->getBody(),
            'an empty OCR result must not append an empty section'
        );
    }

    public function testSurvivesAProviderThatThrows(): void
    {
        $store = new InMemoryImageExtractionStore([$this->storedImage(1, 'Poster.jpg')]);

        $result = $this->service($store, new ThrowingOcrProvider())->enrich(self::MESSAGE_ID, $this->message());

        self::assertFalse($result->enriched);
        self::assertSame('Please find the poster attached.', $result->message->getBody());
        self::assertCount(1, $result->notices);
        self::assertSame('ocr_failed', $result->notices[0]->noticeKey);
        self::assertStringNotContainsString('provider blew up', $result->notices[0]->reason);
    }

    public function testSurvivesAStoreThatCannotBeRead(): void
    {
        $provider = new StubOcrProvider([]);

        $result = $this->service(new ThrowingImageStore(), $provider)->enrich(self::MESSAGE_ID, $this->message());

        self::assertFalse($result->enriched);
        self::assertSame([], $result->notices);
        self::assertSame([], $provider->requestedPaths);
    }

    public function testReportsAPosterWhoseStoredFileHasGoneMissing(): void
    {
        $store = new InMemoryImageExtractionStore([$this->storedImage(1, 'Flyer.jpg')]);
        $provider = new StubOcrProvider([]);

        $result = (new OcrTextEnrichmentService(
            $store,
            $provider,
            new StubOcrInboundStorage(resolves: false),
            OcrExtractionLimits::defaults(),
            null,
            true
        ))->enrich(self::MESSAGE_ID, $this->message());

        self::assertFalse($result->enriched);
        self::assertSame('ocr_failed', $result->notices[0]->noticeKey);
        self::assertSame([], $provider->requestedPaths, 'the provider must not be given an unresolved path');
    }

    public function testHandsTheProviderAnAbsolutePathItCanOpen(): void
    {
        $store = new InMemoryImageExtractionStore([$this->storedImage(1, 'Flyer.jpg')]);
        $provider = new StubOcrProvider([]);

        $this->service($store, $provider)->enrich(self::MESSAGE_ID, $this->message());

        self::assertCount(1, $provider->requestedPaths);
        self::assertStringStartsWith(StubOcrInboundStorage::PRIVATE_DIRECTORY, $provider->requestedPaths[0]);
        self::assertStringEndsWith('.jpg', $provider->requestedPaths[0]);
    }

    public function testStillProducesTextWhenTheOutcomeCannotBeStored(): void
    {
        $store = new InMemoryImageExtractionStore(
            [$this->storedImage(1, 'Poster.jpg')],
            recordThrows: true
        );
        $provider = new StubOcrProvider([
            $this->storedName(1) => OcrExtractionResult::extracted('Retreat Day'),
        ]);

        $result = $this->service($store, $provider)->enrich(self::MESSAGE_ID, $this->message());

        self::assertTrue($result->enriched);
        self::assertStringContainsString('Retreat Day', $result->message->getBody());
    }

    public function testStopsReadingOnceTheMessageTimeBudgetIsSpent(): void
    {
        $store = new InMemoryImageExtractionStore([
            $this->storedImage(1, 'First.jpg'),
            $this->storedImage(2, 'Second.jpg'),
        ]);
        $provider = new StubOcrProvider([]);

        $result = $this->budgetedService($store, $provider, new GrowingOcrStopwatch(30.0, 0.0))
            ->enrich(self::MESSAGE_ID, $this->message());

        self::assertSame([], $provider->requestedPaths, 'no image may be sent once the budget is spent');
        self::assertFalse($result->enriched);
        self::assertCount(2, $result->notices, 'each unread poster must still reach the operator');
        self::assertSame('ocr_skipped_timeout', $result->notices[0]->noticeKey);
        self::assertSame(
            OcrExtractionResult::STATUS_SKIPPED_TIMEOUT,
            $store->recorded[1]->status,
            'the outcome must be stored, or a later run re-sends the poster'
        );
    }

    public function testNarrowsThePerImageTimeoutToWhatIsLeftOfTheMessageBudget(): void
    {
        $store = new InMemoryImageExtractionStore([
            $this->storedImage(1, 'First.jpg'),
            $this->storedImage(2, 'Second.jpg'),
        ]);
        $provider = new StubOcrProvider([]);

        $this->budgetedService($store, $provider, new GrowingOcrStopwatch(25.0, 1.0))
            ->enrich(self::MESSAGE_ID, $this->message());

        self::assertCount(2, $provider->requestedPaths);
        self::assertSame(5, $provider->requestedTimeouts[0]);
        self::assertSame(4, $provider->requestedTimeouts[1]);
    }

    public function testKeepsTheFullPerImageCeilingForThePosterThatIsReadFirst(): void
    {
        $store = new InMemoryImageExtractionStore([$this->storedImage(1, 'Only.jpg')]);
        $provider = new StubOcrProvider([]);

        $this->budgetedService($store, $provider, new GrowingOcrStopwatch(1.0, 1.0))
            ->enrich(self::MESSAGE_ID, $this->message());

        self::assertSame(
            OcrExtractionLimits::DEFAULT_TIMEOUT_SECONDS,
            $provider->requestedTimeouts[0]
        );
    }

    public function testKeepsTheOcrWorkInsideTheProcessingJobBudget(): void
    {
        // Why the message budget exists. The shared host allows 90 seconds and
        // the job itself allows ~60, so OCR must not be able to spend it.
        $limits = OcrExtractionLimits::defaults();

        self::assertSame(
            30,
            $limits->messageTimeBudgetSeconds,
            'the message budget is part of the documented job envelope'
        );
        self::assertLessThanOrEqual(
            JobRunner::DEFAULT_TIME_BUDGET_SECONDS,
            $limits->messageTimeBudgetSeconds + $limits->timeoutSeconds,
            'OCR must stay well inside the job budget'
        );
    }

    public function testThePerImageCeilingIsSmallEnoughToFinishInsideOneRequest(): void
    {
        self::assertLessThanOrEqual(
            20,
            OcrExtractionLimits::DEFAULT_TIMEOUT_SECONDS,
            'the network call must never be able to run into the host time limit'
        );
    }

    public function testReadsNoMoreAttachmentsThanThePerMessageCap(): void
    {
        $attachments = [];

        for ($id = 1; $id <= 6; ++$id) {
            $attachments[] = $this->storedImage($id, "Poster {$id}.jpg");
        }

        $store = new InMemoryImageExtractionStore($attachments);
        $provider = new StubOcrProvider([]);

        $this->service($store, $provider)->enrich(self::MESSAGE_ID, $this->message());

        // Pinned as a literal, not as the constant: asserting against the
        // symbol makes this test pass for any cap, so raising it has to be a
        // deliberate edit to this number.
        self::assertSame(3, OcrTextEnrichmentService::MAX_IMAGES_PER_MESSAGE);
        self::assertCount(3, $provider->requestedPaths);
    }

    public function testJoinsSeveralOcrPostersWithABlankLineBetweenThem(): void
    {
        $store = new InMemoryImageExtractionStore([
            $this->storedImage(1, 'First.jpg'),
            $this->storedImage(2, 'Second.jpg'),
        ]);
        $provider = new StubOcrProvider([
            $this->storedName(1) => OcrExtractionResult::extracted('Confirmation Day'),
            $this->storedName(2) => OcrExtractionResult::extracted('Choir Rehearsal'),
        ]);

        $body = $this->service($store, $provider)->enrich(self::MESSAGE_ID, $this->message())
            ->message->getBody();

        self::assertStringContainsString("First.jpg\nConfirmation Day\n\nSecond.jpg\nChoir Rehearsal", $body);
    }

    public function testRecordsTheExternalOcrMethodOnTheAttachmentRow(): void
    {
        $store = new InMemoryImageExtractionStore([$this->storedImage(1, 'Poster.jpg')]);
        $provider = new StubOcrProvider([
            $this->storedName(1) => OcrExtractionResult::extracted('Retreat Day'),
        ]);

        $this->service($store, $provider)->enrich(self::MESSAGE_ID, $this->message());

        self::assertSame(OcrExtractionResult::METHOD_OCR_EXTERNAL, $store->recorded[1]->method);
        self::assertSame(OcrExtractionResult::STATUS_EXTRACTED, $store->recorded[1]->status);
    }

    public function testTheRecordedMethodFitsTheColumnItIsStoredIn(): void
    {
        // `extraction_method` is varchar(32) and `status` is varchar(20), so a
        // longer method name would be silently truncated on a strict build.
        self::assertLessThanOrEqual(32, strlen(OcrExtractionResult::METHOD_OCR_EXTERNAL));
        self::assertLessThanOrEqual(20, strlen(OcrExtractionResult::STATUS_SKIPPED_RATE_LIMIT));
    }

    private function service(
        ImageExtractionStoreInterface $store,
        OcrProviderInterface $provider,
            bool|\Closure $enabled = true
    ): OcrTextEnrichmentService {
        return new OcrTextEnrichmentService(
            $store,
            $provider,
            new StubOcrInboundStorage(),
            OcrExtractionLimits::defaults(),
            null,
            $enabled
        );
    }

    private function budgetedService(
        ImageExtractionStoreInterface $store,
        OcrProviderInterface $provider,
        StopwatchInterface $stopwatch
    ): OcrTextEnrichmentService {
        return new OcrTextEnrichmentService(
            $store,
            $provider,
            new StubOcrInboundStorage(),
            OcrExtractionLimits::defaults(),
            $stopwatch,
            true
        );
    }

    private function storedImage(int $id, string $filename): StoredImageAttachment
    {
        return new StoredImageAttachment(
            $id,
            $filename,
            $this->storedName($id),
            'pending',
            'none',
            2048,
            'image/jpeg'
        );
    }

    /**
     * The storage adapter names stored files by content hash, so the tests
     * cannot invent their own paths here.
     */
    private function storedName(int $id): string
    {
        return str_pad(dechex($id), 64, 'a', STR_PAD_LEFT) . '.jpg';
    }

    private function message(): Message
    {
        return new Message(
            'email',
            'abc-123',
            'office@example.org',
            'Parish Office',
            'October poster',
            'Please find the poster attached.'
        );
    }
}

/**
 * @param list<StoredImageAttachment> $attachments
 */
final class InMemoryImageExtractionStore implements ImageExtractionStoreInterface
{
    /** @var array<int, OcrExtractionResult> */
    public array $recorded = [];

    /**
     * @param list<StoredImageAttachment> $attachments
     */
    public function __construct(
        private readonly array $attachments,
        private readonly bool $recordThrows = false,
    ) {
    }

    public function findPendingImagesForMessage(int $messageId): array
    {
        return $this->attachments;
    }

    public function recordResult(int $attachmentId, OcrExtractionResult $result): void
    {
        if ($this->recordThrows) {
            throw new RuntimeException('The database write failed.');
        }

        $this->recorded[$attachmentId] = $result;
    }
}

final class ThrowingImageStore implements ImageExtractionStoreInterface
{
    public function findPendingImagesForMessage(int $messageId): array
    {
        throw new RuntimeException('The database is unreachable.');
    }

    public function recordResult(int $attachmentId, OcrExtractionResult $result): void
    {
        throw new RuntimeException('The database is unreachable.');
    }
}

final class StubOcrInboundStorage implements InboundMailStorageReaderInterface
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
 * @param array<string, OcrExtractionResult> $results keyed by stored file name
 */
final class StubOcrProvider implements OcrProviderInterface
{
    /** @var list<string> */
    public array $requestedPaths = [];

    /** @var list<int> */
    public array $requestedTimeouts = [];

    /**
     * @param array<string, OcrExtractionResult> $results
     */
    public function __construct(
        private readonly array $results,
        private readonly bool $available = true,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function extractText(string $filePath, int $timeoutSeconds): OcrExtractionResult
    {
        $this->requestedPaths[] = $filePath;
        $this->requestedTimeouts[] = $timeoutSeconds;

        return $this->results[basename($filePath)]
            ?? OcrExtractionResult::failed('The OCR provider had no fixture for this poster.');
    }
}

final class ThrowingOcrProvider implements OcrProviderInterface
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function extractText(string $filePath, int $timeoutSeconds): OcrExtractionResult
    {
        throw new RuntimeException('The provider blew up.');
    }
}

/**
 * Advances by a fixed step on every reading, so a test can drive a time budget
 * outwards without sleeping.
 */
final class GrowingOcrStopwatch implements StopwatchInterface
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