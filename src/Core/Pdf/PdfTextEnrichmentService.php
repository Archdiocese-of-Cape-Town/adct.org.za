<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Pdf;

use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Ports\AttachmentExtractionStoreInterface;
use ADCT\ParishIntake\Core\Ports\InboundMailStorageReaderInterface;
use ADCT\ParishIntake\Core\Ports\PdfTextExtractorInterface;
use ADCT\ParishIntake\Core\Ports\StopwatchInterface;
use ADCT\ParishIntake\Core\Support\SystemStopwatch;
use Throwable;

/**
 * Adds the text of a message's PDF attachments to its body, so a poster sent as
 * a PDF is parsed like one sent in the email body.
 *
 * The outcome is deliberately forgiving. A PDF that cannot be read must never
 * stop the email around it from being processed, so every failure is swallowed
 * after being recorded on the attachment row and turned into a notice for the
 * operator.
 */
final class PdfTextEnrichmentService
{
    /**
     * Marks the appended section so its origin is obvious on the Manual parser
     * screen and in any stored copy of the body.
     */
    public const SECTION_HEADING = '--- Text from the attached PDF (not OCR) ---';

    /**
     * How many attachments of one message are read. A parish sends a flyer and
     * sometimes a programme, and a dean occasionally forwards a bundle.
     *
     * This is not what keeps the job inside its budget. Time is: one stopwatch
     * spans the whole message and `PdfExtractionLimits::$messageTimeBudgetSeconds`
     * caps the total, however many attachments arrive. The count only stops a
     * mail with a hundred PDFs from queueing a hundred rows of operator work.
     */
    public const MAX_ATTACHMENTS_PER_MESSAGE = 5;

    public function __construct(
        private readonly AttachmentExtractionStoreInterface $store,
        private readonly PdfTextExtractorInterface $extractor,
        private readonly InboundMailStorageReaderInterface $storage,
        private readonly ?PdfExtractionLimits $limits = null,
        private readonly ?StopwatchInterface $stopwatch = null,
    ) {
    }

    public function enrich(int $messageId, Message $message): PdfEnrichmentResult
    {
        try {
            $attachments = $this->store->findPendingPdfsForMessage($messageId);
        } catch (Throwable) {
            return PdfEnrichmentResult::unchanged($message);
        }

        if ($attachments === []) {
            return PdfEnrichmentResult::unchanged($message);
        }

        $limits = $this->limits ?? PdfExtractionLimits::defaults();

        // One stopwatch for the whole message, so time already spent on an
        // earlier attachment counts against a later one.
        $stopwatch = $this->stopwatch ?? new SystemStopwatch();

        $sections = [];
        $notices = [];

        foreach (array_slice($attachments, 0, self::MAX_ATTACHMENTS_PER_MESSAGE) as $attachment) {
            $remaining = $limits->messageTimeBudgetSeconds - $stopwatch->elapsedSeconds();

            if ($remaining <= 0) {
                // Spent. Opening another file here could still run for the full
                // per-file ceiling, which is exactly what this budget prevents.
                $this->record(
                    $attachment->id,
                    $attachment,
                    PdfExtractionResult::skippedTimeout(
                        $limits->maxPages,
                        $limits->messageTimeBudgetSeconds
                    ),
                    $notices
                );

                continue;
            }

            $result = $this->extract($attachment, $this->fileLimits($limits, $remaining));

            if ($result === null) {
                continue;
            }

            $this->record($attachment->id, $attachment, $result, $notices);

            if ($result->isExtracted()) {
                $sections[] = $attachment->filename . "\n" . $result->text;
            }
        }

        if ($sections === []) {
            return PdfEnrichmentResult::unchanged($message, $notices);
        }

        return PdfEnrichmentResult::enriched(
            $message->withBody($this->appendSections($message->getBody(), $sections)),
            $notices
        );
    }

    /**
     * Hands the file the smaller of the per-file ceiling and the time actually
     * left in the message budget, so no single file can overrun the message.
     *
     * The remaining time is a float reading, so it is rounded down. That is the
     * safe direction: the whole seconds granted never exceed the time left.
     */
    private function fileLimits(PdfExtractionLimits $limits, float $remaining): PdfExtractionLimits
    {
        if ($remaining >= $limits->timeBudgetSeconds) {
            return $limits;
        }

        return new PdfExtractionLimits(
            $limits->maxBytes,
            $limits->maxPages,
            max(1, (int) floor($remaining)),
            $limits->messageTimeBudgetSeconds
        );
    }

    /**
     * The extractor is contracted not to throw, but a defensive catch costs
     * nothing here and there is no safe caller further up.
     */
    private function extract(StoredPdfAttachment $attachment, PdfExtractionLimits $limits): ?PdfExtractionResult
    {
        try {
            // The stored name is relative to the private directory, which only
            // the storage adapter knows, so it is resolved rather than trusted.
            $path = $this->storage->resolveAttachmentPath($attachment->storagePath);

            return $this->extractor->extract($path, $limits);
        } catch (Throwable) {
            return PdfExtractionResult::failed('The attached PDF could not be read.');
        }
    }

    private function record(int $attachmentId, StoredPdfAttachment $attachment, PdfExtractionResult $result, array &$notices): void
    {
        try {
            $this->store->recordResult($attachmentId, $result);
        } catch (Throwable) {
            // The text is still usable even if the outcome could not be stored.
        }

        if ($result->needsManualAttention()) {
            $notices[] = new PdfExtractionNotice(
                $attachmentId,
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
