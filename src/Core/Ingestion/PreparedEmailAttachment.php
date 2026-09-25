<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

final readonly class PreparedEmailAttachment
{
    public function __construct(
        public string $filename,
        public string $mimeType,
        public int $sizeBytes,
        public string $contentHash,
        public string $status,
        public ?string $extension,
        public string $content
    ) {
    }
}
