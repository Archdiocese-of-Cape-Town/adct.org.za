<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

final class SenderTrust
{
    public const UNKNOWN = 'unknown';
    public const PENDING = 'pending';
    public const VERIFIED = 'verified';
    public const BLOCKED = 'blocked';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::UNKNOWN,
            self::PENDING,
            self::VERIFIED,
            self::BLOCKED,
        ];
    }

    public static function isValid(string $trust): bool
    {
        return in_array($trust, self::all(), true);
    }
}
