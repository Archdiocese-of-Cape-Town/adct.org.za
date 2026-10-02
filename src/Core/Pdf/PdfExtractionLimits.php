<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Pdf;

use ADCT\ParishIntake\Core\Ingestion\AttachmentStoragePolicy;

/**
 * The hard limits applied to a PDF before and during text extraction.
 *
 * These exist so a hostile or oversized attachment can never exhaust the
 * shared host: a 15 MB file will not be held in memory, a 200 page newsletter
 * will not be read in full, and neither one PDF nor one email's worth of PDFs
 * can spend the processing job's time budget.
 */
final readonly class PdfExtractionLimits
{
    public const DEFAULT_MAX_BYTES = AttachmentStoragePolicy::MAX_ATTACHMENT_SIZE_BYTES;

    public const DEFAULT_MAX_PAGES = 10;

    /**
     * The ceiling on one PDF.
     *
     * It is only ever compared with `>`, so it caps work without being spent: a
     * file that finishes early leaves the rest of its allowance for the next
     * attachment in the same message.
     */
    public const DEFAULT_TIME_BUDGET_SECONDS = 10;

    /**
     * The ceiling on the PDF work for one message as a whole.
     *
     * The per-file ceiling is deliberately not the only limit. Five PDFs at ten
     * seconds each would spend half of the processing job's ~60 second budget
     * on a single email, so the message is capped too. Once this is spent the
     * remaining attachments are recorded as timed out for manual entry rather
     * than dropped silently.
     */
    public const DEFAULT_MESSAGE_TIME_BUDGET_SECONDS = 30;

    public function __construct(
        public int $maxBytes = self::DEFAULT_MAX_BYTES,
        public int $maxPages = self::DEFAULT_MAX_PAGES,
        public int $timeBudgetSeconds = self::DEFAULT_TIME_BUDGET_SECONDS,
        public int $messageTimeBudgetSeconds = self::DEFAULT_MESSAGE_TIME_BUDGET_SECONDS,
    ) {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('The PDF size limit must be a positive number of bytes.');
        }

        if ($maxPages < 1) {
            throw new \InvalidArgumentException('The PDF page limit must be at least one page.');
        }

        if ($timeBudgetSeconds < 1) {
            throw new \InvalidArgumentException('The PDF time budget must be at least one second.');
        }

        if ($messageTimeBudgetSeconds < $timeBudgetSeconds) {
            throw new \InvalidArgumentException(
                'The message PDF budget must be at least as long as the budget for one file.'
            );
        }
    }

    public static function defaults(): self
    {
        return new self();
    }
}
