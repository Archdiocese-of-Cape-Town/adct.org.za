<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Jobs;

/**
 * The opt-in switch and daily spend cap for server-side OCR of poster images.
 *
 * OCR sends a parish poster to an external provider, so it is off until an
 * administrator explicitly turns it on. The daily cap is a separate budget
 * from the AI budget: an OCR spend limit can never be spent down by AI calls,
 * and the two features can be enabled independently.
 *
 * This class only reads options. It never calls out to WordPress beyond
 * `get_option`, so the Plugin container can build it before any screen loads.
 */
final class OcrSettings
{
    public const ENABLED_OPTION = 'adct_pi_ocr_enabled';
    public const DAILY_CALL_LIMIT_OPTION = 'adct_pi_ocr_daily_call_limit';

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

    /**
     * False when the operator has not opted in. Callers must treat this as
     * "do nothing at all" rather than as an error.
     */
    public function isEnabled(): bool
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