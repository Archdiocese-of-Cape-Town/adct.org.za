<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Retention;

use ADCT\ParishIntake\Core\Retention\RetentionSettings;
use RuntimeException;

final class WordPressRetentionSettings
{
    public const RAW_OPTION = 'adct_pi_retention_raw_months';
    public const PROCESSED_OPTION = 'adct_pi_retention_processed_days';

    public static function current(): RetentionSettings
    {
        $raw = get_option(self::RAW_OPTION, RetentionSettings::DEFAULT_RAW_MONTHS);
        $processed = get_option(self::PROCESSED_OPTION, RetentionSettings::DEFAULT_PROCESSED_DAYS);
        if (! self::validInteger($raw) || ! self::validInteger($processed)) {
            throw new RuntimeException('Retention settings are invalid; review them before running cleanup.');
        }
        try {
            return new RetentionSettings((int) $raw, (int) $processed);
        } catch (\InvalidArgumentException $failure) {
            throw new RuntimeException('Retention settings are outside safe limits; review them before running cleanup.', 0, $failure);
        }
    }

    public static function validInteger(mixed $value): bool
    {
        return (is_string($value) || is_int($value)) && preg_match('/\A[1-9][0-9]*\z/D', (string) $value) === 1;
    }
}
