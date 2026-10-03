<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Ocr\OcrExtractionResult;

/**
 * Reads the words out of a poster image.
 *
 * The contract is deliberately outcome-shaped. Every way this can go wrong —
 * no key, a size limit, a timeout, a rate limit, an unreadable reply — comes
 * back as an {@see OcrExtractionResult} rather than an exception, because the
 * caller has no useful recovery and must always continue with the email.
 */
interface OcrProviderInterface
{
    /**
     * False when no key is configured or the HTTP transport is missing.
     *
     * Checked before a poster is offered to the provider so nothing is read,
     * uploaded or counted against a daily cap when OCR cannot run at all.
     */
    public function isAvailable(): bool;

    /**
     * @param int $timeoutSeconds the caller's remaining budget, so an adapter
     *                           can never wait longer than the job allows
     */
    public function extractText(string $filePath, int $timeoutSeconds): OcrExtractionResult;
}