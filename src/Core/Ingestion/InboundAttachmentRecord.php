<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

use InvalidArgumentException;

final readonly class InboundAttachmentRecord
{
    public function __construct(
        public string $filename,
        public string $mimeType,
        public int $sizeBytes,
        public string $storagePath,
        public string $contentHash,
        public string $status
    ) {
        if (
            $filename === ''
            || $mimeType === ''
            || $sizeBytes < 0
            || preg_match('/\A[a-f0-9]{64}\z/i', $contentHash) !== 1
        ) {
            throw new InvalidArgumentException('An inbound attachment record has invalid metadata.');
        }
    }
}
