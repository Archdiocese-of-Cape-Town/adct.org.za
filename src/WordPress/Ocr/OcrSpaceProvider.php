<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Ocr;

use ADCT\ParishIntake\Core\Ocr\OcrExtractionResult;
use ADCT\ParishIntake\Core\Ports\AiCallGateInterface;
use ADCT\ParishIntake\Core\Ports\HttpClientInterface;
use ADCT\ParishIntake\Core\Ports\OcrProviderInterface;
use ADCT\ParishIntake\Core\Ports\HttpResponse;
use RuntimeException;

/**
 * The OCR.space free-tier adapter.
 *
 * This is the only class in the plugin that sends a parish poster to a third
 * party, so it is deliberately hard to make it do anything:
 *
 * - it will not make a request without an API key, so an unconfigured install
 *   cannot leak a poster by accident;
 * - it will not read, measure or upload a file it cannot stat, and it will not
 *   upload one over the size ceiling;
 * - it spends one unit of the daily cap per poster and refuses once spent,
 *   which is what keeps the third party's free tier and the parish's cost
 *   bounded;
 * - it passes the caller's timeout straight to the transport, so the
 *   processing job's ~60 second budget cannot be overrun by one poster;
 * - every failure comes back as an `OcrExtractionResult`, never as an
 *   exception, so the queue job always reaches manual entry.
 *
 * The API key is passed in a header rather than in the body so it cannot end
 * up in a captured request log alongside the multipart part, and it is never
 * placed in a reason string, an exception message or a debug view.
 */
final class OcrSpaceProvider implements OcrProviderInterface
{
    /**
     * Multipart boundaries are generated per request.
     *
     * A fixed boundary is theoretically guessable, and a boundary appearing
     * inside a poster's bytes would truncate the upload. This is derived from
     * random bytes, so neither can happen.
     */
    private const BOUNDARY_PREFIX = '----ADCTParishIntakeOcr';

    public const DEFAULT_ENDPOINT = 'https://api.ocr.space/parse/image';

    /**
     * The third party posters are sent to, spelled out for the operator.
     *
     * The settings screen has to name the destination before anyone can decide
     * to opt in, so the name lives beside the endpoint rather than being
     * retyped in the admin screen.
     */
    public const PROVIDER_NAME = 'OCR.space';

    /**
     * The largest poster OCR.space's free tier is offered here.
     *
     * The free plan accepts images up to 1 MB by default; anything larger is
     * refused before it is uploaded rather than rejected by the service, which
     * would cost a daily-cap unit for nothing.
     */
    public const DEFAULT_MAX_IMAGE_BYTES = 1024 * 1024;

    /**
     * The ceiling on the text appended to a message body.
     *
     * A poster can recover a page of text at a time. The parsed message body is
     * already capped elsewhere, so this keeps one noisy poster from pushing the
     * event fields out of the head of the text the parser reads.
     */
    public const MAX_TEXT_BYTES = 20000;

    /**
     * No real OCR.space key is anywhere near this long, so a longer value is a
     * mistake or a pasted secret rather than a key.
     */
    private const MAX_API_KEY_LENGTH = 200;

    /**
     * A reply larger than this is not a document read; refusing it stops a
     * misbehaving or hostile endpoint from filling the PHP memory limit.
     */
    private const MAX_RESPONSE_BYTES = 65536;

    private const RATE_LIMIT_BACKOFF_SECONDS = 600;

    private const SERVER_ERROR_BACKOFF_SECONDS = 300;

    /**
     * The key is held behind a closure so no inspection of this object can read
     * it.
     *
     * `print_r`, `var_export` and `var_dump` on a plugin object all walk its
     * declared properties, and `__debugInfo()` does not apply to any of them.
     * A closure captures the value in an uninspectable scope instead of as a
     * property, so a debug dump of this adapter shows the endpoint and the
     * limits and never the secret.
     */
    private readonly \Closure $apiKey;

    /**
     * Derived so that a debug dump, a `var_export` or an exception trace shows
     * only the endpoint, never the key.
     */
    public function __construct(
        string $apiKey,
        private readonly HttpClientInterface $httpClient,
        private readonly AiCallGateInterface $gate,
        private readonly int $maxImageBytes = self::DEFAULT_MAX_IMAGE_BYTES,
        private readonly string $endpoint = self::DEFAULT_ENDPOINT,
    ) {
        $this->apiKey = static fn (): string => $apiKey;

        if (! str_starts_with(strtolower($this->endpoint), 'https://')) {
            throw new \InvalidArgumentException('The OCR endpoint must be an HTTPS URL.');
        }

        if (strlen(($this->apiKey)()) > self::MAX_API_KEY_LENGTH) {
            throw new \InvalidArgumentException('The OCR API key is not a valid key length.');
        }


        if ($this->maxImageBytes < 1) {
            throw new \InvalidArgumentException('The OCR image size limit must be a positive number of bytes.');
        }
    }

    public function __debugInfo(): array
    {
        return ['provider' => 'ocr.space'];
    }

    public function isAvailable(): bool
    {
        return ($this->apiKey)() !== '' && $this->httpClient->isAvailable();
    }

    public function extractText(string $filePath, int $timeoutSeconds): OcrExtractionResult
    {
        $unreadable = $this->checkFile($filePath);
        if ($unreadable !== null) {
            return $unreadable;
        }

        if (($this->apiKey)() === '') {
            return OcrExtractionResult::notConfigured();
        }

        if (! $this->gate->reserve()) {
            return OcrExtractionResult::rateLimited(
                'The daily OCR limit has been reached, so the poster was not read. It needs manual entry.'
            );
        }

        try {
            $boundary = self::BOUNDARY_PREFIX . bin2hex(random_bytes(12));

            $response = $this->httpClient->post(
                $this->endpoint,
                [
                    'apikey' => ($this->apiKey)(),
                    'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                ],
                $this->buildBody($filePath, $boundary),
                $timeoutSeconds
            );
        } catch (RuntimeException) {
            return OcrExtractionResult::failed(
                'The OCR service did not reply in time, so the poster was not read. It needs manual entry.'
            );
        }

        return $this->interpret($response);
    }

    /**
     * The file checks run before the API key is consulted so that a missing or
     * over-size poster is reported for what it is, and before the daily cap is
     * spent so a poster that was never uploaded does not cost a unit.
     */
    private function checkFile(string $filePath): ?OcrExtractionResult
    {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            return OcrExtractionResult::failed(
                'The stored poster could not be read, so it was not sent for OCR. It needs manual entry.'
            );
        }

        $size = @filesize($filePath);
        if ($size === false) {
            return OcrExtractionResult::failed(
                'The stored poster could not be measured, so it was not sent for OCR. It needs manual entry.'
            );
        }

        if ($size > $this->maxImageBytes) {
            return OcrExtractionResult::skippedSize($size, $this->maxImageBytes);
        }

        return null;
    }

    private function interpret(HttpResponse $response): OcrExtractionResult
    {
        if ($response->status === 429) {
            $this->gate->backOff(self::RATE_LIMIT_BACKOFF_SECONDS);

            return OcrExtractionResult::rateLimited(
                'The OCR service reports its daily limit has been reached. The poster needs manual entry.'
            );
        }

        if ($response->status >= 500) {
            $this->gate->backOff(self::SERVER_ERROR_BACKOFF_SECONDS);

            return OcrExtractionResult::failed(sprintf(
                'The OCR service returned HTTP %d. The poster needs manual entry.',
                $response->status
            ));
        }

        if ($response->status !== 200) {
            return OcrExtractionResult::failed(sprintf(
                'The OCR service returned HTTP %d. The poster needs manual entry.',
                $response->status
            ));
        }

        if (strlen($response->body) > self::MAX_RESPONSE_BYTES) {
            return OcrExtractionResult::failed('The OCR service sent an oversized reply. The poster needs manual entry.');
        }

        try {
            $decoded = json_decode($response->body, true, 8, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return OcrExtractionResult::failed('The OCR service sent an unreadable reply. The poster needs manual entry.');
        }

        if (! is_array($decoded)) {
            return OcrExtractionResult::failed('The OCR service sent an unreadable reply. The poster needs manual entry.');
        }

        // OCR.space reports most refusals with HTTP 200 and an error message,
        // so a 200 on its own is not proof that anything was read.
        $error = $decoded['ErrorMessage'] ?? null;
        if (is_string($error) && trim($error) !== '') {
            return OcrExtractionResult::failed(sprintf(
                'The OCR service could not read the poster: %s',
                self::condense($error)
            ));
        }

        $parsedText = $decoded['ParsedResults'][0]['ParsedText'] ?? null;
        if (! is_string($parsedText)) {
            return OcrExtractionResult::noTextFound();
        }

        // A single str_replace with an array, not two in sequence: replacing
        // "\r\n" after "\r" has already become "\n" would insert a blank line.
        $text = self::tidy(str_replace(["\r\n", "\r"], "\n", $parsedText));
        if ($text === '') {
            return OcrExtractionResult::noTextFound();
        }

        return OcrExtractionResult::extracted(self::trimTo($text, self::MAX_TEXT_BYTES));
    }

    /**
     * Normalises an OCR engine's layout without collapsing it.
     *
     * Line breaks matter: the parser reads the appended section as document
     * text, and a poster's title is usually on its own line above the date.
     * Trailing whitespace and runs of blank lines are removed because an OCR
     * engine emits them freely and they would otherwise crowd the section out
     * of the body the parser reads.
     */
    private static function tidy(string $text): string
    {
        $normalised = preg_replace('/[ \t]+\n/u', "\n", $text) ?? $text;
        $collapsed = preg_replace('/\n{3,}/u', "\n\n", $normalised) ?? $normalised;

        return trim($collapsed);
    }

    /**
     * A single-line reason, so a long service message cannot break the layout
     * of the Manual parser screen or an email.
     */
    private static function condense(string $text): string
    {
        $collapsed = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return self::trimTo($collapsed, 300);
    }

    /**
     * Trims on a character boundary.
     *
     * A byte cut can split a multi-byte character in half, which would leave
     * invalid UTF-8 in the message body for every reader after it.
     */
    private static function trimTo(string $text, int $maxBytes): string
    {
        if (strlen($text) <= $maxBytes) {
            return $text;
        }

        if (function_exists('mb_strcut')) {
            return mb_strcut($text, 0, $maxBytes, 'UTF-8');
        }

        // Without mbstring, walk back from the byte cut until the text decodes
        // cleanly; at most three bytes of a UTF-8 character can be at stake.
        $trimmed = substr($text, 0, $maxBytes);

        while ($trimmed !== '' && preg_match('//u', $trimmed) !== 1) {
            $trimmed = substr($trimmed, 0, -1);
        }

        return $trimmed;
    }

    /**
     * OCR.space is read by engines that predate the multipart/file convention,
     * so the `file` part carries the base64 payload and its own filename
     * rather than a `filename=` part header.
     */
    private function buildBody(string $filePath, string $boundary): string
    {
        $contents = @file_get_contents($filePath);
        if ($contents === false) {
            return '';
        }

        $name = self::partName(basename($filePath));

        return self::part($boundary, 'language', 'eng')
            . self::part($boundary, 'isOverlayRequired', 'false')
            . self::part($boundary, 'detectOrientation', 'true')
            . self::part($boundary, 'scale', 'true')
            . self::part($boundary, 'OCREngine', '2')
            . self::part($boundary, 'isTable', 'true')
            . '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="file"; filename="' . $name . "\"\r\n"
            . 'Content-Type: application/octet-stream' . "\r\n\r\n"
            . base64_encode($contents) . "\r\n"
            . '--' . $boundary . "--\r\n";
    }

    /**
     * The key goes in the header, so it is deliberately absent from the body:
     * a captured request body is far more likely to be pasted into a support
     * ticket than a header, and the body travels to the third party either way.
     */
    private static function part(string $boundary, string $name, string $value): string
    {
        return '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n"
            . $value . "\r\n";
    }

    /**
     * The filename is copied from the parish's own attachment into a third
     * party's request, so it is reduced to a plain name and length-capped.
     */
    private static function partName(string $filename): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? 'poster.jpg';

        return substr($clean === '' ? 'poster.jpg' : $clean, 0, 60);
    }
}
