<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\Ocr;

use ADCT\ParishIntake\Core\Directory\DirectorySnapshot;
use ADCT\ParishIntake\Core\Ingestion\MimeMessageParser;
use ADCT\ParishIntake\Core\Ocr\OcrEnrichmentResult;
use ADCT\ParishIntake\Core\Ocr\OcrExtractionResult;
use ADCT\ParishIntake\Core\Ocr\OcrTextEnrichmentService;
use ADCT\ParishIntake\Core\Ocr\StoredImageAttachment;
use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\ParseResult;
use ADCT\ParishIntake\Core\Parsing\PipelineFactory;
use ADCT\ParishIntake\Core\Ports\AiCallGateInterface;
use ADCT\ParishIntake\Core\Ports\DirectorySnapshotProviderInterface;
use ADCT\ParishIntake\Core\Ports\HttpClientInterface;
use ADCT\ParishIntake\Core\Ports\HttpResponse;
use ADCT\ParishIntake\Core\Ports\ImageExtractionStoreInterface;
use ADCT\ParishIntake\Core\Ports\InboundMailStorageReaderInterface;
use ADCT\ParishIntake\Tests\Support\EmailFixtureLoader;
use ADCT\ParishIntake\WordPress\Ocr\OcrSpaceProvider;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Issue #75: "an anonymised image-only poster fixture produces a candidate when
 * a recorded OCR response is replayed".
 *
 * This deliberately does not live in `tests/fixtures/emails/`, which is the
 * shared parser corpus and compares every `.eml` against a checked-in expected
 * file for the *no-OCR* path. Joining that corpus would only re-prove the
 * fallback. This test proves the other half: the whole chain, from the poster
 * fixture on disk, through the real `OcrSpaceProvider` adapter answering with a
 * recorded reply, through `OcrTextEnrichmentService`, into the parser, which has
 * to produce a candidate out of words that only ever existed as pixels.
 *
 * Nothing here reaches the network, needs an API key or needs Tesseract: the
 * adapter is handed a transport that returns the recorded reply, so the
 * provider's own request building and response handling is exercised without a
 * third-party service being involved.
 */
final class OcrPosterFixtureReplayTest extends TestCase
{
    private const MESSAGE_ID = 19;

    private const ATTACHMENT_ID = 7;

    private const POSTER_FILENAME = 'example-retreat-poster.png';

    /** The name the private mail directory gives the stored copy. */
    private const STORED_NAME = 'poster-attachment.png';

    private PosterImageExtractionStore $store;

    private PosterInboundStorage $storage;

    private RecordedReplyHttpClient $http;

    private CountingOcrGate $gate;

    private string $stagedPoster;

    protected function setUp(): void
    {
        parent::setUp();

        $posterBytes = (string) file_get_contents(self::posterPath());
        $this->stagedPoster = $this->stagePoster($posterBytes);

        $this->store = new PosterImageExtractionStore([
            new StoredImageAttachment(
                self::ATTACHMENT_ID,
                self::POSTER_FILENAME,
                self::STORED_NAME,
                'pending',
                OcrExtractionResult::METHOD_NONE,
                strlen($posterBytes),
                'image/png'
            ),
        ]);
        $this->storage = new PosterInboundStorage($this->stagedPoster);
        $this->http = new RecordedReplyHttpClient(self::recordedResponseBody());
        $this->gate = new CountingOcrGate();
    }

    protected function tearDown(): void
    {
        if (is_file($this->stagedPoster)) {
            unlink($this->stagedPoster);
        }

        $directory = dirname($this->stagedPoster);

        if (is_dir($directory)) {
            rmdir($directory);
        }

        parent::tearDown();
    }

    public function testTheCommittedPosterFixtureIsARealPngInsideTheFreeTierCeiling(): void
    {
        $bytes = (string) file_get_contents(self::posterPath());

        self::assertNotSame('', $bytes);
        self::assertSame("\x89PNG\r\n\x1a\n", substr($bytes, 0, 8), 'the fixture is a real PNG, not a stub');
        self::assertStringContainsString('IHDR', $bytes);
        self::assertStringContainsString('IEND', $bytes);
        self::assertLessThan(
            OcrSpaceProvider::DEFAULT_MAX_IMAGE_BYTES,
            strlen($bytes),
            'the fixture stays inside the size the free tier accepts, so it is not skipped before it is read'
        );
    }

    public function testTheRecordedReplyIsShapedLikeARealOcrSpaceReply(): void
    {
        $recorded = self::recordedResponse();

        self::assertSame(1, $recorded['OCRExitCode']);
        self::assertFalse($recorded['IsErroredOnProcessing']);
        self::assertSame('', $recorded['ErrorMessage']);
        self::assertNotSame('', $recorded['ParsedResults'][0]['ParsedText']);
    }

    public function testTheEmailFixtureCarriesTheRealPosterBytesAndNoEvent(): void
    {
        $message = (new MimeMessageParser())->parse(
            (string) file_get_contents(self::emailPath()),
            'image-only-poster-without-text-layer'
        );

        $attachments = $message->getAttachments();

        self::assertCount(1, $attachments);
                self::assertSame(self::POSTER_FILENAME, $attachments[0]->getName());
                self::assertSame('image/png', $attachments[0]->getMimeType());
        self::assertStringNotContainsString(
            'PARISH RETREAT DAY',
            $message->getBody(),
            'the email body carries no event, which is the whole point of the fixture'
        );
    }

    public function testWithoutOcrTheOnlyCandidateIsTheImageOnlyPlaceholder(): void
    {
            // This is the pre-existing fallback, the same one
            // tests/fixtures/emails/image-only-poster.eml already pins: a candidate an
            // operator has to recognise as "there is a poster here, a human needed".
            $candidates = (new PipelineFactory(null, self::snapshots()))
                ->create()
                ->parseAll((new EmailFixtureLoader())->load(self::emailPath()))
                ->getCandidates();

            self::assertCount(1, $candidates);
            self::assertSame('Image-only poster', $candidates[0]->fields()['title'] ?? null);
            self::assertNull($candidates[0]->fields()['event_date'] ?? null, 'no date: it only exists as pixels');
            self::assertSame([self::POSTER_FILENAME], $candidates[0]->fields()['attachment_names'] ?? null);
        }

        public function testAReplayedOcrReplyProducesACandidateFromAnImageOnlyPoster(): void
        {
            $message = (new EmailFixtureLoader())->load(self::emailPath());

            $enriched = $this->enrich($message);

            self::assertTrue($enriched->enriched, 'the recorded reply was accepted as text');
            self::assertSame([], $enriched->notices, 'a successful read raises no notice');

            $recorded = $this->store->recorded[self::ATTACHMENT_ID] ?? null;

            self::assertNotNull($recorded);
            self::assertSame(OcrExtractionResult::STATUS_EXTRACTED, $recorded->status);
            self::assertSame(
                OcrExtractionResult::METHOD_OCR_EXTERNAL,
                $recorded->method,
                'the attachment row says the words came from a third party'
            );

            $candidates = (new PipelineFactory(null, self::snapshots()))
                ->create()
                ->parseAll($enriched->message)
                ->getCandidates();

            self::assertNotSame([], $candidates, 'the poster alone is now enough to propose something');

            // A poster is read line by line, and the bulletin splitter treats each
            // visual line as its own block, so one poster can surface as more than
            // one candidate. What matters is that the words a human would read off
            // the poster reach the reviewer as parsed fields, and that the
            // best-scoring candidate is a real event rather than the placeholder.
            $byField = array_map(
                static fn (ParseResult $candidate): array => $candidate->fields(),
                $candidates
            );

            $dated = array_values(array_filter(
                $byField,
                static fn (array $fields): bool => ($fields['event_date'] ?? null) === '2026-10-12'
            ));

            self::assertNotSame(
                [],
                $dated,
                'a day-first date on the poster is read as 12 October 2026, not 12 December'
            );
            self::assertStringContainsStringIgnoringCase(
                'Parish Retreat Day',
                (string) ($dated[0]['title'] ?? ''),
                'the event title came out of the image'
            );

            $timed = array_values(array_filter(
                $byField,
                static fn (array $fields): bool => isset($fields['venue'])
            ));

            self::assertNotSame([], $timed, 'the venue came out of the image');
            self::assertStringContainsStringIgnoringCase(
            'Example Parish Hall',
            (string) $timed[0]['venue'],
            'a poster is set in capitals, so the venue comes back capitalised too'
        );
            self::assertSame('09:00', $timed[0]['event_time'] ?? null, 'the poster time is read as 9am');

            usort(
                $candidates,
                static fn (ParseResult $a, ParseResult $b): int => $b->getConfidence() <=> $a->getConfidence()
            );
            $best = $candidates[0];

            self::assertSame(
                '2026-10-12',
                $best->fields()['event_date'] ?? null,
                'the candidate an operator meets first is the real event, not the image-only placeholder'
            );
        }

    public function testTheReplayedTextIsMarkedSoAnOperatorKnowsItCameFromAPhotograph(): void
    {
        $body = $this->enrich((new EmailFixtureLoader())->load(self::emailPath()))->message->getBody();

        self::assertStringContainsString(OcrTextEnrichmentService::SECTION_HEADING, $body);
        self::assertStringContainsString(self::POSTER_FILENAME, $body);
    }

    public function testThePosterBytesAreUploadedAndTheKeyStaysOutOfTheBody(): void
    {
        $this->enrich((new EmailFixtureLoader())->load(self::emailPath()));

        self::assertSame(OcrSpaceProvider::DEFAULT_ENDPOINT, $this->http->url);
        self::assertNotNull($this->http->body);
        self::assertStringContainsString(
            'filename="' . self::STORED_NAME . '"',
            $this->http->body,
            'the poster is uploaded as a real multipart file part'
        );
        self::assertStringContainsString(
            base64_encode((string) file_get_contents(self::posterPath())),
            $this->http->body,
            'the exact committed fixture bytes are what is sent, so this proves the real file is uploaded'
        );
        self::assertArrayHasKey('apikey', $this->http->headers);
        self::assertStringNotContainsString(
            'fixture-key-not-a-secret',
            $this->http->body,
            'the key travels in a header, never in the captured request body'
        );
        self::assertSame(1, $this->gate->calls, 'one poster costs exactly one unit of the daily cap');
    }

    private function enrich(Message $message): OcrEnrichmentResult
    {
        $service = new OcrTextEnrichmentService(
            $this->store,
            new OcrSpaceProvider('fixture-key-not-a-secret', $this->http, $this->gate),
            $this->storage
        );

        return $service->enrich(self::MESSAGE_ID, $message);
    }

    /**
     * Stages the poster where the private mail directory would put it.
     *
     * The real storage adapter keeps those files outside the web root; a test
     * temporary directory is the closest honest equivalent that writes nothing
     * into the repository.
     */
    private function stagePoster(string $bytes): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'adct-ocr-poster-' . bin2hex(random_bytes(6));

        if ((! mkdir($directory, 0700, true) && ! is_dir($directory)) || ! is_writable($directory)) {
            self::fail('Unable to stage the poster fixture.');
        }

        $path = $directory . DIRECTORY_SEPARATOR . self::STORED_NAME;
        file_put_contents($path, $bytes);

        return $path;
    }

    private static function fixtureDirectory(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'posters';
    }

    private static function posterPath(): string
    {
        return self::fixtureDirectory() . DIRECTORY_SEPARATOR . 'example-retreat-poster.png';
    }

    private static function emailPath(): string
    {
        return self::fixtureDirectory() . DIRECTORY_SEPARATOR . 'image-only-poster-without-text-layer.eml';
    }

    private static function recordedResponseBody(): string
    {
        $recorded = self::recordedResponse();
        unset($recorded['_comment']);

        return (string) json_encode($recorded, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private static function recordedResponse(): array
    {
        $json = file_get_contents(self::fixtureDirectory() . DIRECTORY_SEPARATOR . 'recorded-ocr-response.json');

        if (! is_string($json)) {
            self::fail('The recorded OCR response fixture is missing.');
        }

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            self::fail('The recorded OCR response fixture must be an object.');
        }

        return $decoded;
    }

    private static function snapshots(): DirectorySnapshotProviderInterface
    {
            return new SingleParishSnapshotProvider();
        }
    }

    /**
     * One fictional parish, so the directory stage runs without inventing a second
     * fixture file.
     */
    final class SingleParishSnapshotProvider implements DirectorySnapshotProviderInterface
    {
        public function getSnapshot(): DirectorySnapshot
        {
            return new DirectorySnapshot(
                [['id' => 1, 'name' => 'Example Parish', 'slug' => 'example-parish']],
                [],
                []
            );
        }
    }

/**
 * @param list<StoredImageAttachment> $attachments
 */
final class PosterImageExtractionStore implements ImageExtractionStoreInterface
{
    /** @var array<int, OcrExtractionResult> */
    public array $recorded = [];

    /**
     * @param list<StoredImageAttachment> $attachments
     */
    public function __construct(private readonly array $attachments)
    {
    }

    public function findPendingImagesForMessage(int $messageId): array
    {
        return $this->attachments;
    }

    public function recordResult(int $attachmentId, OcrExtractionResult $result): void
    {
        $this->recorded[$attachmentId] = $result;
    }
}

/**
 * Resolves the staged poster, and only by the stored name.
 *
 * Refusing anything else means a bug that passed a path instead of a stored name
 * fails loudly rather than reading an arbitrary file off the host.
 */
final class PosterInboundStorage implements InboundMailStorageReaderInterface
{
    public function __construct(private readonly string $stagedPath)
    {
    }

    public function resolveAttachmentPath(string $relativePath): string
    {
        if ($relativePath !== 'poster-attachment.png') {
            throw new InvalidArgumentException('Not a stored attachment name: ' . $relativePath);
        }

        return $this->stagedPath;
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
 * Returns the recorded reply, so the real adapter does the parsing.
 */
final class RecordedReplyHttpClient implements HttpClientInterface
{
    public ?string $url = null;

    public ?string $body = null;

    public int $timeout = 0;

    /** @var array<string, string> */
    public array $headers = [];

    public function __construct(private readonly string $recordedBody)
    {
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function post(string $url, array $headers, string $body, int $timeout): HttpResponse
    {
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;
        $this->timeout = $timeout;

        return new HttpResponse(200, $this->recordedBody);
    }
}

final class CountingOcrGate implements AiCallGateInterface
{
    public int $calls = 0;

    /** @var list<int> */
    public array $backoffs = [];

    public function reserve(): bool
    {
        ++$this->calls;

        return true;
    }

    public function backOff(int $seconds): void
    {
        $this->backoffs[] = $seconds;
    }
}