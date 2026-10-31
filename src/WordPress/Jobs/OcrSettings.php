<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Jobs;

/**
 * The legacy setting and daily call cap for server-side poster OCR.
 *
 * The saved values are retained for compatibility, but server-side OCR is
 * unavailable until the project owner approves it. A saved opt-in must never
 * enable the external provider.
 *
 * This class only reads options. It never calls out to WordPress beyond
 * `get_option`, so the Plugin container can build it before any screen loads.
 */
final class OcrSettings
{
    public const ENABLED_OPTION = 'adct_pi_ocr_enabled';
    public const DAILY_CALL_LIMIT_OPTION = 'adct_pi_ocr_daily_call_limit';
    public const UNAVAILABLE_MESSAGE = 'Automatic server-side poster OCR is unavailable; this setting cannot be enabled.';

    /**
     * Twenty posters a day is enough for a normal bulletin week and small
     * enough that a misconfigured loop cannot run up an unbounded bill.
     */
    public const DEFAULT_DAILY_CALL_LIMIT = 20;

    public const MIN_DAILY_CALL_LIMIT = 1;
    public const MAX_DAILY_CALL_LIMIT = 1000;

    private function __construct(
        private readonly bool $enabled,
        private readonly int $dailyCallLimit,
        private readonly ?string $configurationError
    ) {
    }

    public static function current(): self
    {
        return self::fromValues(
            get_option(self::ENABLED_OPTION, '0'),
            get_option(self::DAILY_CALL_LIMIT_OPTION, self::DEFAULT_DAILY_CALL_LIMIT)
        );
    }

    public static function fromValues(mixed $enabled, mixed $dailyCallLimit): self
    {
        $enabled = self::flag($enabled);
        $limit = self::limit($dailyCallLimit);

        $error = null;

        if ($enabled && $limit === null) {
            $error = 'Poster OCR is enabled, but the daily call limit must be a whole number between '
                . self::MIN_DAILY_CALL_LIMIT . ' and ' . self::MAX_DAILY_CALL_LIMIT . '.';
        }

        return new self(
            $enabled,
            $limit ?? self::DEFAULT_DAILY_CALL_LIMIT,
            $error
        );
    }

    public function isEnabled(): bool
    {
        return false;
    }

    /**
     * Whether an older saved value or submitted request asks to enable OCR.
     */
    public function isConfiguredEnabled(): bool
    {
        return $this->enabled;
    }

    public function dailyCallLimit(): int
    {
        return $this->dailyCallLimit;
    }

    public function configurationError(): ?string
    {
        return $this->configurationError;
    }

    private static function flag(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
    }

    private static function limit(mixed $value): ?int
    {
        $limit = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => self::MIN_DAILY_CALL_LIMIT,
                'max_range' => self::MAX_DAILY_CALL_LIMIT,
            ],
        ]);

        return is_int($limit) ? $limit : null;
    }
}