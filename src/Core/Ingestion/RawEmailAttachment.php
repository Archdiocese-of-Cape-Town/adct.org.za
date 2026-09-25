<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

final readonly class RawEmailAttachment
{
    public function __construct(
        public string $filename,
        public string $mimeType,
        public string $content
    ) {
    }
}
