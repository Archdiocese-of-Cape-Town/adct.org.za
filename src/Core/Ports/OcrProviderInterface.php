<?php

namespace ADCT\ParishIntake\Core\Ports;

interface OcrProviderInterface
{
    /**
     * Provisional: the signature will be refined by its first consumer, optional OCR in E12 (including E8.3, #75).
     */
    public function extractText(string $filePath): ?string;
}
