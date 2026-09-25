<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ingestion;

use DateTimeImmutable;

final readonly class RawMailMessage
{
    /**
     * @param list<string> $flags
     */
    public function __construct(
        public int $uid,
        public string $raw,
        public int $size,
        public DateTimeImmutable $internalDate,
        public array $flags,
    ) {
    }
}
