<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

use InvalidArgumentException;

final class MailQueueConfiguration
{
    public const DEFAULT_HOURLY_CAP = 100;
    public const MAX_HOURLY_CAP = 500;
    public const HOURLY_WINDOW_SECONDS = 3600;
    public const MAX_ATTEMPTS = 5;
    public const RETRY_BASE_SECONDS = 60;
    public const RETRY_MAX_SECONDS = 21600;
    public const SENT_RETENTION_DAYS = 30;
    public const PRUNE_BATCH_SIZE = 100;

    public function __construct(
        public readonly int $hourlyCap = self::DEFAULT_HOURLY_CAP
    ) {
        if ($hourlyCap < 1 || $hourlyCap > self::MAX_HOURLY_CAP) {
            throw new InvalidArgumentException(sprintf(
                'The mail queue hourly cap must be between 1 and %d.',
                self::MAX_HOURLY_CAP
            ));
        }
    }

    public function retryDelaySeconds(int $attempt): int
    {
        if ($attempt < 1) {
            throw new InvalidArgumentException('A mail attempt number must be positive.');
        }

        $exponent = min($attempt - 1, 16);

        return min(self::RETRY_MAX_SECONDS, self::RETRY_BASE_SECONDS * (2 ** $exponent));
    }
}
