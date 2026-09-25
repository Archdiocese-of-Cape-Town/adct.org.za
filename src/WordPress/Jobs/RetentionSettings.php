<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Jobs;

final class RetentionSettings
{
    public const RAW_ENABLED_OPTION = 'adct_pi_retention_raw_enabled';
    public const RAW_DAYS_OPTION = 'adct_pi_retention_raw_days';
    public const PROCESSED_ENABLED_OPTION = 'adct_pi_retention_processed_enabled';
    public const PROCESSED_DAYS_OPTION = 'adct_pi_retention_processed_days';
    public const ACTION_TOKENS_ENABLED_OPTION = 'adct_pi_retention_action_tokens_enabled';
    public const AUDIT_ENABLED_OPTION = 'adct_pi_retention_audit_enabled';

    public const DEFAULT_RAW_DAYS = 365;
    public const DEFAULT_PROCESSED_DAYS = 30;

    private function __construct(
        private readonly bool $rawEnabled,
        private readonly ?int $rawDays,
        private readonly bool $processedEnabled,
        private readonly ?int $processedDays,
        private readonly bool $actionTokensEnabled,
        private readonly bool $auditEnabled,
        private readonly ?string $configurationError
    ) {
    }

    public static function current(): self
    {
        return self::fromValues(
            get_option(self::RAW_ENABLED_OPTION, '0'),
            get_option(self::RAW_DAYS_OPTION, self::DEFAULT_RAW_DAYS),
            get_option(self::PROCESSED_ENABLED_OPTION, '0'),
            get_option(self::PROCESSED_DAYS_OPTION, self::DEFAULT_PROCESSED_DAYS),
            get_option(self::ACTION_TOKENS_ENABLED_OPTION, '0'),
            get_option(self::AUDIT_ENABLED_OPTION, '0')
        );
    }

    public static function fromValues(
        mixed $rawEnabled,
        mixed $rawDays,
        mixed $processedEnabled,
        mixed $processedDays,
        mixed $actionTokensEnabled = '0',
        mixed $auditEnabled = '0'
    ): self {
        $rawEnabled = self::flag($rawEnabled);
        $processedEnabled = self::flag($processedEnabled);
        $rawDays = self::days($rawDays);
        $processedDays = self::days($processedDays);
        $actionTokensEnabled = self::flag($actionTokensEnabled);
        $auditEnabled = self::flag($auditEnabled);

        $error = null;

        if ($rawEnabled && ($rawDays === null || $rawDays < 1)) {
            $error = 'Raw-data retention is enabled, but the retention period must be at least 1 day.';
        }

        if ($error === null && $processedEnabled && ($processedDays === null || $processedDays < 1)) {
            $error = 'Processed-folder pruning is enabled, but the retention period must be at least 1 day.';
        }

        return new self(
            $rawEnabled,
            $rawDays,
            $processedEnabled,
            $processedDays,
            $actionTokensEnabled,
            $auditEnabled,
            $error
        );
    }

    public function hasAnyCleanupEnabled(): bool
    {
        return $this->rawEnabled
            || $this->processedEnabled
            || $this->actionTokensEnabled
            || $this->auditEnabled;
    }

    public function rawCleanupEnabled(): bool
    {
        return $this->rawEnabled;
    }

    public function processedCleanupEnabled(): bool
    {
        return $this->processedEnabled;
    }

    public function actionTokenCleanupEnabled(): bool
    {
        return $this->actionTokensEnabled;
    }

    public function auditCleanupEnabled(): bool
    {
        return $this->auditEnabled;
    }

    public function rawRetentionDays(): int
    {
        return $this->rawDays ?? self::DEFAULT_RAW_DAYS;
    }

    public function processedRetentionDays(): int
    {
        return $this->processedDays ?? self::DEFAULT_PROCESSED_DAYS;
    }

    public function configurationError(): ?string
    {
        return $this->configurationError;
    }

    private static function flag(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
    }

    private static function days(mixed $value): ?int
    {
        $days = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($days) ? $days : null;
    }
}
