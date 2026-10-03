<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ocr;

use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Ports\ImageExtractionStoreInterface;
use ADCT\ParishIntake\Core\Ports\InboundMailStorageReaderInterface;
use ADCT\ParishIntake\Core\Ports\OcrProviderInterface;
use ADCT\ParishIntake\Core\Ports\StopwatchInterface;
use ADCT\ParishIntake\Core\Support\SystemStopwatch;
use Throwable;

/**
 * Adds the words read from a message's poster images to its body, so a poster
 * sent as a photo is parsed like one written out in the email.
 *
 * The outcome is deliberately forgiving. OCR is an optional extra that costs
 * money and sends data to a third party, so it can only ever add to what the
 * parish already wrote: a poster that cannot be read must never stop the email
 * around it from being processed, and must never turn into a blank candidate.
 * Every failure is swallowed after being recorded on the attachment row and
 * turned into a notice for the operator.
 */
final class OcrTextEnrichmentService
{
    /**
     * Marks the appended section so its origin is obvious on the Manual parser
     * screen and in any stored copy of the body.
     *
     * "OCR" without a negation, unlike the PDF equivalent: the wording is what
     * makes an operator check the spelling of a date that came out of a
     * photograph rather than trusting it.
     */
    public const SECTION_HEADING = '--- Text read from the attached poster (OCR) ---';

    /**
     * How many posters of one message are sent to OCR. A parish sends the
     * poster and sometimes a map or a programme photo, and a dean occasionally
     * forwards a small album.
     *
     * This is not what keeps the job inside its budget. Time is: one stopwatch
     * spans the whole message and `OcrExtractionLimits::$messageTimeBudgetSeconds`
     * caps the total, however many posters arrive. The count only stops a mail
     * with a hundred photos from queueing a hundred paid third-party calls.
     */
    public const MAX_IMAGES_PER_MESSAGE = 3;

    /** The image types offered to OCR. */
    private const OCR_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
         * Whether the operator has opted in, asked afresh on every message.
         *
         * A resolver rather than a plain bool so that the WordPress adapter can
         * read the setting lazily. Core must not touch WordPress, and an opt-in
         * recorded after this object was built has to take effect on the next
         * message without anything being re-wired.
         *
         * @var \Closure():bool
         */
        private readonly \Closure $isEnabled;

        public function __construct(
            private readonly ImageExtractionStoreInterface $store,
            private readonly OcrProviderInterface $provider,
            private readonly InboundMailStorageReaderInterface $storage,
            private readonly ?OcrExtractionLimits $limits = null,
            private readonly ?StopwatchInterface $stopwatch = null,
            bool|\Closure $enabled = true,
        ) {
            $this->isEnabled = $enabled instanceof \Closure
                ? $enabled
                : static fn (): bool => $enabled;
        }

    public function enrich(int $messageId, Message $message): OcrEnrichmentResult
    {
        try {
            $attachments = $this->store->findPendingImagesForMessage($messageId);
        } catch (Throwable) {
            return OcrEnrichmentResult::unchanged($message);
        }

        $attachments = array_values(array_filter(
            $attachments,
            fn (StoredImageAttachment $attachment): bool => $attachment->needsExtraction()
        ));

        if ($attachments === []) {
            return OcrEnrichmentResult::unchanged($message);
        }

        // Opted out. Nothing is read, nothing is sent, and nothing is recorded:
        // the poster stays `pending` so it can still be read once an operator
        // turns OCR on. A deliberate opt-out is not an error worth reporting.
        if (! ($this->isEnabled)()) {
            return OcrEnrichmentResult::unchanged($message);
        }

        if (! $this->provider->isAvailable()) {
            $notice = new OcrExtractionNotice(
                $attachments[0]->id,
                $attachments[0]->filename,
                OcrExtractionResult::notConfigured()->noticeKey(),
                (string) OcrExtractionResult::notConfigured()->reason
            );

            return OcrEnrichmentResult::unchanged($message, [$notice]);
        }

        $limits = $this->limits ?? OcrExtractionLimits::defaults();

        // One stopwatch for the whole message, so time already spent on an
        // earlier poster counts against a later one.
        $stopwatch = $this->stopwatch ?? new SystemStopwatch();

        $sections = [];
        $notices = [];

        foreach (array_slice($attachments, 0, self::MAX_IMAGES_PER_MESSAGE) as $attachment) {
            $remaining = $limits->messageTimeBudgetSeconds - $stopwatch->elapsedSeconds();

            if ($remaining <= 0) {
                // Spent. Another call here could still hang for the full
                // per-poster ceiling, which is exactly what this budget exists
                // to prevent. Recorded, so a later run does not send it again.
                $this->record(
                    $attachment,
                    OcrExtractionResult::skippedTimeout($limits->timeoutSeconds),
                    $notices
                );

                continue;
            }

            $result = $this->read($attachment, $limits, $remaining);

            $this->record($attachment, $result, $notices);

            if ($result->isExtracted()) {
                $sections[] = $attachment->filename . "\n" . $result->text;
            }
        }

        if ($sections === []) {
            return OcrEnrichmentResult::unchanged($message, $notices);
        }

        return OcrEnrichmentResult::enriched(
            $message->withBody($this->appendSections($message->getBody(), $sections)),
            $notices
        );
    }

    private function read(
        StoredImageAttachment $attachment,
        OcrExtractionLimits $limits,
        float $remaining
    ): OcrExtractionResult {
        if (! in_array($attachment->mimeType, self::OCR_MIME_TYPES, true)) {
            return OcrExtractionResult::skippedType($attachment->mimeType);
        }

        if ($attachment->sizeBytes > $limits->maxBytes) {
            return OcrExtractionResult::skippedSize($attachment->sizeBytes, $limits->maxBytes);
        }

        try {
            // The stored name is relative to the private directory, which only
            // the storage adapter knows, so it is resolved rather than trusted.
            $path = $this->storage->resolveAttachmentPath($attachment->storagePath);

            return $this->provider->extractText($path, $this->timeoutFor($limits, $remaining));
        } catch (Throwable) {
            // The provider is contracted not to throw, but a defensive catch
            // costs nothing here and there is no safe caller further up. The
            // reason is generic on purpose: an exception message could carry
            // anything the transport was doing, and this reaches an admin
            // screen.
            return OcrExtractionResult::failed('The poster could not be read by the OCR service.');
        }
    }

    /**
     * Hands the provider the smaller of the per-poster ceiling and the time
     * actually left in the message budget, so no single poster can overrun the
     * message.
     *
     * The remaining time is a float reading, so it is rounded down. That is the
     * safe direction: the whole seconds granted never exceed the time left.
     */
    private function timeoutFor(OcrExtractionLimits $limits, float $remaining): int
    {
        if ($remaining >= $limits->timeoutSeconds) {
            return $limits->timeoutSeconds;
        }

        return max(1, (int) floor($remaining));
    }

    private function record(StoredImageAttachment $attachment, OcrExtractionResult $result, array &$notices): void
    {
        try {
            $this->store->recordResult($attachment->id, $result);
        } catch (Throwable) {
            // The text is still usable even if the outcome could not be stored.
        }

        if ($result->needsManualAttention()) {
            $notices[] = new OcrExtractionNotice(
                $attachment->id,
                $attachment->filename,
                $result->noticeKey(),
                (string) $result->reason
            );
        }
    }

    /**
     * @param list<string> $sections
     */
    private function appendSections(string $body, array $sections): string
    {
        $trimmedBody = rtrim($body);

        return $trimmedBody . "\n\n" . self::SECTION_HEADING . "\n\n" . implode("\n\n", $sections);
    }
}