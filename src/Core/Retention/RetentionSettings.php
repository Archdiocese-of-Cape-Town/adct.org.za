<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Retention;

use InvalidArgumentException;

final readonly class RetentionSettings
{
    public const DEFAULT_RAW_MONTHS = 12;
    public const DEFAULT_PROCESSED_DAYS = 90;

    public function __construct(
        public int $rawMonths = self::DEFAULT_RAW_MONTHS,
        public int $processedDays = self::DEFAULT_PROCESSED_DAYS
    ) {
        if ($rawMonths < 1 || $rawMonths > 120 || $processedDays < 1 || $processedDays > 3650) {
            throw new InvalidArgumentException('Retention must be 1–120 months for files and 1–3650 days for processed mail.');
        }
    }
}
