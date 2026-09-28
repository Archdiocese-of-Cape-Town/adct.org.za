<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Pdf;

use ADCT\ParishIntake\Core\Ingestion\AttachmentStoragePolicy;

/**
 * The hard limits applied to a PDF before and during text extraction.
 *
 * These exist so a hostile or oversized attachment can never exhaust the
 * shared host: a 15 MB file will not be held in memory, and a 200 page
 * newsletter will not consume the processing job's time budget.
 */
final readonly class PdfExtractionLimits
{
    public const DEFAULT_MAX_BYTES = AttachmentStoragePolicy::MAX_ATTACHMENT_SIZE_BYTES;

    public const DEFAULT_MAX_PAGES = 10;

    /**
     * Leaves headroom inside the processing job's roughly 60 second budget so
     * that a slow PDF cannot starve the rest of the queue.
     */
    public const DEFAULT_TIME_BUDGET_SECONDS = 10;

    public function __construct(
        public int $maxBytes = self::DEFAULT_MAX_BYTES,
        public int $maxPages = self::DEFAULT_MAX_PAGES,
        public int $timeBudgetSeconds = self::DEFAULT_TIME_BUDGET_SECONDS,
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
    }

    public static function defaults(): self
    {
        return new self();
    }
}
