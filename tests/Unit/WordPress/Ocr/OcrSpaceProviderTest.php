<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Tests\Unit\WordPress\Ocr;

use ADCT\ParishIntake\Core\Ocr\OcrExtractionResult;
use ADCT\ParishIntake\Core\Ports\AiCallGateInterface;
use ADCT\ParishIntake\Core\Ports\HttpClientInterface;
use ADCT\ParishIntake\Core\Ports\HttpResponse;
use ADCT\ParishIntake\WordPress\Ocr\OcrSpaceProvider;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The adapter is the only place a parish poster leaves the site, so most of
 * these tests are about what must NOT happen: no call without a key, no call
 * once the daily cap is spent, no call at all for a file that cannot be read or
 * is too large, a hard timeout on every request, and an API key that never
 * reaches an exception message, a debug view or a var_dump.
 */
final class OcrSpaceProviderTest extends TestCase
{
    private string $posterPath = '';

    private string $missingPath = '';

    protected function setUp(): void
    {
        $directory = sys_get_temp_dir() . '/adct-ocr-provider-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700, true);

        // A real file on disk, because the adapter refuses to send anything it
        // cannot read or measure.
        $this->posterPath = $directory . '/poster.jpg';
        file_put_contents($this->posterPath, str_repeat("\xFF\xD8\xFF", 64));

        $this->missingPath = $directory . '/not-here.jpg';
    }

    protected function tearDown(): void
    {
        if ($this->posterPath !== '' && is_file($this->posterPath)) {
            unlink($this->posterPath);
        }

        if ($this->missingPath !== '' && is_dir(dirname($this->missingPath))) {
            rmdir(dirname($this->missingPath));
        }
    }

    public function testExtractsTheTextFromASuccessfulResponse(): void
    {
        $http = new FakeOcrHttp(new HttpResponse(200, $this->successBody('Parish Retreat Day')));

        $result = $this->provider($http)->extractText($this->posterPath, 10);

        self::assertTrue($result->isExtracted());
        self::assertSame('Parish Retreat Day', $result->text);
        self::assertSame(OcrExtractionResult::METHOD_OCR_EXTERNAL, $result->method);
    }

    public function testPostsThePosterToTheDocumentedEndpoint(): void
    {
        $http = new FakeOcrHttp(new HttpResponse(200, $this->successBody('Retreat Day')));

        $this->provider($http)->extractText($this->posterPath, 10);

        self::assertSame('https://api.ocr.space/parse/image', $http->url);
        self::assertMultipartPart($http->body ?? '', 'language', 'eng');
        self::assertMultipartPart($http->body ?? '', 'isOverlayRequired', 'false');
        self::assertMultipartPart($http->body ?? '', 'OCREngine', '2');
        self::assertMultipartPart($http->body ?? '', 'scale', 'true');
    }

    /**
     * Asserts one named multipart part carries the given value.
     *
     * The request is real multipart because OCR.space reads the poster from a
     * `file` part, which a URL-encoded body cannot express.
     */
    private static function assertMultipartPart(string $body, string $name, string $value): void
    {
        self::assertStringContainsString(
            'name="' . $name . '"' . "\r\n\r\n" . $value . "\r\n",
            $body,
            sprintf('the request is missing the %s=%s part', $name, $value)
        );
    }

    public function testSendsTheApiKeyInTheHeaderWithoutEverExposingIt(): void
    {
        $http = new FakeOcrHttp(new HttpResponse(200, $this->successBody('Retreat Day')));
        $provider = $this->provider($http, 'secret-key-value');

        $result = $provider->extractText($this->posterPath, 10);

        self::assertTrue($result->isExtracted());
        self::assertSame('secret-key-value', $http->headers['apikey']);

        // The key must not survive into the operator-facing outcome. (The
        // adapter's own debug surface is checked in
        // testTheAdapterObjectItselfNeverExposesTheApiKey.)
        self::assertStringNotContainsString('secret-key-value', (string) $result->reason);
        self::assertStringNotContainsString('secret-key-value', (string) $result->text);
        self::assertStringNotContainsString(
            'secret-key-value',
            (string) $http->body,
            'the key belongs in a header, not in a body a captured request would carry'
        );
    }

    /**
     * The key must not appear in any of the dumps that a real failure produces:
     * an uncaught-error log, a WordPress debug bar entry, a support ticket.
     *
     * Deliberate `ReflectionProperty::getValue()` is a privileged read that can
     * recover any secret a running process holds, so that is not asserted
     * against; what is asserted is that the key is held behind a resolver
     * rather than in a scalar property that the dump paths walk.
     */
    public function testTheAdapterObjectItselfNeverExposesTheApiKey(): void
    {
        $provider = new OcrSpaceProvider(
            'secret-key-value',
            new NonRecordingOcrHttp(new HttpResponse(200, $this->successBody('Retreat Day'))),
            new FakeOcrGate()
        );

        self::assertStringNotContainsString('secret-key-value', print_r($provider, true));
        self::assertStringNotContainsString('secret-key-value', var_export($provider, true));
        self::assertStringNotContainsString('secret-key-value', (string) print_r($provider->__debugInfo(), true));

        ob_start();
        var_dump($provider);
        self::assertStringNotContainsString('secret-key-value', (string) ob_get_clean());

        $property = (new \ReflectionClass($provider))->getProperty('apiKey');
        self::assertInstanceOf(
            \Closure::class,
            $property->getValue($provider),
            'the key must be held behind a resolver, not as a readable scalar'
        );
    }

    public function testResolvesTheConfiguredApiKeyForTheOutgoingHeader(): void
    {
        $http = new FakeOcrHttp(new HttpResponse(200, $this->successBody('Retreat Day')));
        $provider = $this->provider($http, 'secret-key-value');

        $provider->extractText($this->posterPath, 10);

        self::assertSame('secret-key-value', $http->headers['apikey']);
    }

    public function testKeepsTheApiKeyOutOfTheUploadedPosterPart(): void
    {
        $http = new FakeOcrHttp(new HttpResponse(200, $this->successBody('Retreat Day')));

        $this->provider($http, 'secret-key-value')->extractText($this->posterPath, 10);

        self::assertStringNotContainsString(
            'secret-key-value',
            (string) $http->body,
            'the key belongs in a header, not in a body a captured request would carry'
        );
    }

    public function testIsUnavailableWithoutAnApiKeySoNothingIsEverSent(): void
    {
        $http = new FakeOcrHttp(new HttpResponse(200, $this->successBody('Retreat Day')));
        $gate = new FakeOcrGate();

        $provider = new OcrSpaceProvider('', $http, $gate);
        $result = $provider->extractText($this->posterPath, 10);

        self::assertFalse($provider->isAvailable());
        self::assertFalse($result->isExtracted());
        self::assertNull($http->url, 'no request may be made without an API key');
        self::assertSame(0, $gate->calls, 'the daily cap must not be spent on a request that was never sent');
        self::assertSame(OcrExtractionResult::STATUS_NOT_CONFIGURED, $result->status);
    }

    public function testIsUnavailableWhenTheTransportIsMissing(): void
    {
        $http = new FakeOcrHttp(new HttpResponse(200, $this->successBody('Retreat Day')));
        $http->available = false;

        self::assertFalse($this->provider($http)->isAvailable());
    }

    public function testGivesUpWhenTheDailyCapIsSpent(): void
    {
        $http = new FakeOcrHttp(new HttpResponse(200, $this->successBody('Retreat Day')));
        $gate = new FakeOcrGate();
        $gate->available = false;

        $result = $this->provider($http, gate: $gate)->extractText($this->posterPath, 10);

        self::assertFalse($result->isExtracted());
        self::assertNull($http->url, 'a capped request must not be sent');
        self::assertSame(OcrExtractionResult::STATUS_SKIPPED_RATE_LIMIT, $result->status);
        self::assertStringContainsString('daily', (string) $result->reason);
    }

    public function testSpendsTheDailyCapExactlyOncePerPoster(): void
    {
        $http = new FakeOcrHttp(new HttpResponse(200, $this->successBody('Retreat Day')));
        $gate = new FakeOcrGate();

        $this->provider($http, gate: $gate)->extractText($this->posterPath, 10);

        self::assertSame(1, $gate->calls);
    }

    public function testBacksOffForTenMinutesWhenTheServiceAnswers429(): void
    {
        $http = new FakeOcrHttp(new HttpResponse(429, '{"ErrorMessage":"Daily limit reached"}'));
        $gate = new FakeOcrGate();

        $result = $this->provider($http, gate: $gate)->extractText($this->posterPath, 10);

        self::assertFalse($result->isExtracted());
        self::assertSame(OcrExtractionResult::STATUS_SKIPPED_RATE_LIMIT, $result->status);
        self::assertSame([600], $gate->backoffs);
    }

    public function testBacksOffForFiveMinutesOnAServerError(): void
    {
        $http = new FakeOcrHttp(new HttpResponse(503, 'Service Unavailable'));
        $gate = new FakeOcrGate();

        $result = $this->provider($http, gate: $gate)->extractText($this->posterPath, 10);

        self::assertFalse($result->isExtracted());
        self::assertSame(OcrExtractionResult::STATUS_FAILED, $result->status);
        self::assertSame([300], $gate->backoffs);
    }

    public function testDoesNotBackOffOnAnOrdinaryClientError(): void
    {
        $http = new FakeOcrHttp(new HttpResponse(400, '{"ErrorMessage":"bad request"}'));
        $gate = new FakeOcrGate();

        $result = $this->provider($http, gate: $gate)->extractText($this->posterPath, 10);

        self::assertFalse($result->isExtracted());
        self::assertSame([], $gate->backoffs, 'a 400 is our mistake, not a reason to cool the service down');
        self::assertSame(OcrExtractionResult::STATUS_FAILED, $result->status);
    }

    public function testFallsBackWhenTheTransportThrowsOrTimesOut(): void
    {
        $http = new FakeOcrHttp(new RuntimeException('HTTP request failed or timed out.'));

        $result = $this->provider($http)->extractText($this->posterPath, 10);

        self::assertFalse($result->isExtracted());
        self::assertSame(OcrExtractionResult::STATUS_FAILED, $result->status);
        self::assertStringContainsString('reply', (string) $result->reason);
    }

    public function testAlwaysPassesTheCallerTimeoutThroughSoTheJobCanStopWaiting(): void
    {
        $http = new FakeOcrHttp(new HttpResponse(200, $this->successBody('Retreat Day')));

        $this->provider($http)->extractText($this->posterPath, 7);

        self::assertSame(7, $http->timeout);
    }

    public function testRefusesToSendAPosterOverTheSizeLimit(): void
    {
        $http = new FakeOcrHttp(new HttpResponse(200, $this->successBody('Retreat Day')));
        $gate = new FakeOcrGate();
        $provider = new OcrSpaceProvider('secret-key-value', $http, $gate, maxImageBytes: 16);

        $result = $provider->extractText($this->posterPath, 10);

        self::assertFalse($result->isExtracted());
        self::assertNull($http->url, 'an over-size poster must never be uploaded');
        self::assertSame(0, $gate->calls, 'the daily cap must not be spent on a poster that was never sent');
        self::assertSame(OcrExtractionResult::STATUS_SKIPPED_SIZE, $result->status);
    }

    public function testReportsAPosterWhoseFileHasGoneMissing(): void
    {
        $http = new FakeOcrHttp(new HttpResponse(200, $this->successBody('Retreat Day')));
        $gate = new FakeOcrGate();
        $provider = new OcrSpaceProvider('secret-key-value', $http, $gate);

        $result = $provider->extractText($this->missingPath, 10);

        self::assertFalse($result->isExtracted());
        self::assertNull($http->url);
        self::assertSame(0, $gate->calls);
        self::assertSame(OcrExtractionResult::STATUS_FAILED, $result->status);
    }

    public function testRejectsAnUnreadableResponseBody(): void
    {
        $http = new FakeOcrHttp(new HttpResponse(200, 'this is not json'));

        $result = $this->provider($http)->extractText($this->posterPath, 10);

        self::assertFalse($result->isExtracted());
        self::assertSame(OcrExtractionResult::STATUS_FAILED, $result->status);
        self::assertStringContainsString('reply', (string) $result->reason);
    }

    public function testRejectsAnOversizedResponseBody(): void
    {
        $http = new FakeOcrHttp(new HttpResponse(200, str_repeat('x', 65537)));

        $result = $this->provider($http)->extractText($this->posterPath, 10);

        self::assertFalse($result->isExtracted());
        self::assertStringContainsString('reply', (string) $result->reason);
    }

    public function testTellsAnErrorPayloadApartFromAnEmptyResult(): void
    {
        $http = new FakeOcrHttp(new HttpResponse(200, '{"ErrorMessage":"Unsupported image format"}'));

        $result = $this->provider($http)->extractText($this->posterPath, 10);

        self::assertFalse($result->isExtracted());
        self::assertSame(OcrExtractionResult::STATUS_FAILED, $result->status);
        self::assertStringContainsString('Unsupported image format', (string) $result->reason);
    }

    public function testTreatsAPosterWithNoWordsAsNeedingManualEntry(): void
    {
        $http = new FakeOcrHttp(new HttpResponse(200, $this->successBody("   \n  \n ")));

        $result = $this->provider($http)->extractText($this->posterPath, 10);

        self::assertFalse($result->isExtracted());
        self::assertSame(OcrExtractionResult::STATUS_NO_TEXT, $result->status);
    }

    public function testNormalisesCarriageReturnsInTheRecoveredText(): void
    {
        $http = new FakeOcrHttp(new HttpResponse(200, $this->successBody("Parish Retreat Day\r\nSaturday 17 October")));

        $result = $this->provider($http)->extractText($this->posterPath, 10);

        self::assertTrue($result->isExtracted());
        self::assertStringNotContainsString("\r", $result->text);
        self::assertStringContainsString("Parish Retreat Day\nSaturday 17 October", $result->text);
    }

    public function testTrimsRecoveredTextToTheLimitThatKeepsOnePosterInsideTheJobBudget(): void
    {
        $http = new FakeOcrHttp(new HttpResponse(200, $this->successBody(str_repeat('a', 20000))));

        $result = $this->provider($http)->extractText($this->posterPath, 10);

        self::assertTrue($result->isExtracted());
        self::assertLessThanOrEqual(
            OcrSpaceProvider::MAX_TEXT_BYTES,
            strlen($result->text),
            'a huge OCR reply must not be appended to the message body unbounded'
        );
    }

    public function testTrimsAtACharacterBoundaryRatherThanCuttingOneInHalf(): void
    {
        // Two-byte characters, and enough of them that the ceiling falls in the
        // middle of a character. `JSON_UNESCAPED_UNICODE` keeps the reply under
        // the size cap so this exercises trimming rather than the cap.
        $text = str_repeat('é', (int) (OcrSpaceProvider::MAX_TEXT_BYTES / 2) + 10);
        $http = new FakeOcrHttp(new HttpResponse(200, $this->successBody($text)));

        $result = $this->provider($http)->extractText($this->posterPath, 10);

        self::assertTrue($result->isExtracted());
        self::assertLessThanOrEqual(OcrSpaceProvider::MAX_TEXT_BYTES, strlen($result->text));
        self::assertNotFalse(
            mb_detect_encoding($result->text, 'UTF-8', true),
            'the trimmed OCR text must still be valid UTF-8'
        );
        self::assertSame(
            1,
            preg_match('//u', $result->text),
            'a byte cut would leave a half character at the end of the trimmed text'
        );
    }

    public function testTheDefaultSizeLimitIsARealCeiling(): void
    {
        self::assertGreaterThan(0, OcrSpaceProvider::DEFAULT_MAX_IMAGE_BYTES);
    }

    public function testRejectsAnEndpointThatIsNotTheDocumentedHttpsApi(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OcrSpaceProvider(
            'secret-key-value',
            new FakeOcrHttp(new HttpResponse(200, '{}')),
            new FakeOcrGate(),
            endpoint: 'http://ocr.example.test/parse/image'
        );
    }

    public function testRejectsAnApiKeyLongerThanAnyRealKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OcrSpaceProvider(str_repeat('k', 500), new FakeOcrHttp(new HttpResponse(200, '{}')), new FakeOcrGate());
    }

    public function testRejectsASizeLimitThatWouldLetAPosterFillMemory(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OcrSpaceProvider(
            'secret-key-value',
            new FakeOcrHttp(new HttpResponse(200, '{}')),
            new FakeOcrGate(),
            maxImageBytes: 0
        );
    }

    private function provider(
        FakeOcrHttp $http,
        string $apiKey = 'secret-key-value',
        ?FakeOcrGate $gate = null
    ): OcrSpaceProvider {
        return new OcrSpaceProvider($apiKey, $http, $gate ?? new FakeOcrGate());
    }

    private function successBody(string $parsedText): string
    {
        return json_encode([
            'ParsedResults' => [['ParsedText' => $parsedText]],
        ], JSON_THROW_ON_ERROR);
    }
}

final class FakeOcrHttp implements HttpClientInterface
{
    public ?string $url = null;

    public ?string $body = null;

    public int $timeout = 0;

    /** @var array<string, string> */
    public array $headers = [];

    public bool $available = true;

    public function __construct(private readonly HttpResponse|RuntimeException $response)
    {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function post(string $url, array $headers, string $body, int $timeout): HttpResponse
    {
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;
        $this->timeout = $timeout;

        if ($this->response instanceof RuntimeException) {
            throw $this->response;
        }

        return $this->response;
    }
}

final class NonRecordingOcrHttp implements HttpClientInterface
{
    public function __construct(private readonly HttpResponse $response)
    {
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function post(string $url, array $headers, string $body, int $timeout): HttpResponse
    {
        return $this->response;
    }
}

final class FakeOcrGate implements AiCallGateInterface
{
    public bool $available = true;

    public int $calls = 0;

    /** @var list<int> */
    public array $backoffs = [];

    public function reserve(): bool
    {
        ++$this->calls;

        return $this->available;
    }

    public function backOff(int $seconds): void
    {
        $this->backoffs[] = $seconds;
    }
}