<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Mail;

use InvalidArgumentException;

final class MailDeliveryResult
{
    private function __construct(
        public readonly bool $sent,
        public readonly ?string $failureCode
    ) {
        if (
            $sent === ($failureCode !== null)
            || ($failureCode !== null && preg_match('/\A[a-z0-9_]{1,64}\z/D', $failureCode) !== 1)
        ) {
            throw new InvalidArgumentException('A mail delivery result has an invalid outcome.');
        }
    }

    public static function sent(): self
    {
        return new self(true, null);
    }

    public static function failed(string $failureCode): self
    {
        return new self(false, $failureCode);
    }
}
