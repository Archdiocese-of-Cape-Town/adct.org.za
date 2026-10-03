<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ocr;

use ADCT\ParishIntake\Core\Ingestion\AttachmentStoragePolicy;

/**
 * The hard limits applied to a poster before and during OCR.
 *
 * OCR is the only part of ingestion that leaves the host, so these limits exist
 * to keep it inside the processing job's budget and to keep the number of paid
 * third-party calls bounded whatever arrives by email.
 */
final readonly class OcrExtractionLimits
{
    /**
     * The storage policy already rejects anything larger, so by the time a
     * poster reaches OCR it is at most this size. The ceiling is repeated here
     * so the adapter is safe if it is ever called directly.
     */
    public const DEFAULT_MAX_BYTES = AttachmentStoragePolicy::MAX_ATTACHMENT_SIZE_BYTES;

    /**
     * The ceiling on one network call.
     *
     * A hosted hook on this shared plan gets 90 seconds, and the processing job
     * itself about 60, so a call that can occupy the whole PHP limit is not a
     * defensible default. This is passed to the transport as its timeout, so it
     * is the longest the request can hang.
     */
    public const DEFAULT_TIMEOUT_SECONDS = 20;

    /**
     * The ceiling on the OCR work for one message as a whole.
     *
     * The per-call ceiling is deliberately not the only limit. Three posters at
     * twenty seconds each would spend a minute of the job's budget on one
     * email, so the message is capped too. Once this is spent the remaining
     * posters are recorded as timed out for manual entry rather than dropped
     * silently.
     */
    public const DEFAULT_MESSAGE_TIME_BUDGET_SECONDS = 30;

    public function __construct(
        public int $maxBytes = self::DEFAULT_MAX_BYTES,
        public int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        public int $messageTimeBudgetSeconds = self::DEFAULT_MESSAGE_TIME_BUDGET_SECONDS,
    ) {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('The OCR size limit must be a positive number of bytes.');
        }

        if ($timeoutSeconds < 1) {
            throw new \InvalidArgumentException('The OCR timeout must be at least one second.');
        }

        if ($messageTimeBudgetSeconds < $timeoutSeconds) {
            throw new \InvalidArgumentException(
                'The message OCR budget must be at least as long as the budget for one poster.'
            );
        }
    }

    public static function defaults(): self
    {
        return new self();
    }
}