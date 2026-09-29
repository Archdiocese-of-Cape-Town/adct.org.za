<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Pdf;

use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Ports\AttachmentExtractionStoreInterface;
use ADCT\ParishIntake\Core\Ports\InboundMailStorageReaderInterface;
use ADCT\ParishIntake\Core\Ports\PdfTextExtractorInterface;
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
     * How many attachments of one message are read. A parish sends one flyer;
     * this only exists so a mail with a dozen PDFs cannot eat the job budget.
     */
    public const MAX_ATTACHMENTS_PER_MESSAGE = 3;

    public function __construct(
        private readonly AttachmentExtractionStoreInterface $store,
        private readonly PdfTextExtractorInterface $extractor,
        private readonly InboundMailStorageReaderInterface $storage,
        private readonly ?PdfExtractionLimits $limits = null,
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

        $sections = [];
        $notices = [];

        foreach (array_slice($attachments, 0, self::MAX_ATTACHMENTS_PER_MESSAGE) as $attachment) {
            $result = $this->extract($attachment);

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
     * The extractor is contracted not to throw, but a defensive catch costs
     * nothing here and there is no safe caller further up.
     */
    private function extract(StoredPdfAttachment $attachment): ?PdfExtractionResult
    {
        try {
            // The stored name is relative to the private directory, which only
            // the storage adapter knows, so it is resolved rather than trusted.
            $path = $this->storage->resolveAttachmentPath($attachment->storagePath);

            return $this->extractor->extract(
                $path,
                $this->limits ?? PdfExtractionLimits::defaults()
            );
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
