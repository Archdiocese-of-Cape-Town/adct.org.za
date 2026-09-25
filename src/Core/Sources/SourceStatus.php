<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Sources;

use InvalidArgumentException;

final class SourceStatus
{
    public const ACTIVE = 'active';
    public const PAUSED = 'paused';
    public const UNRELIABLE = 'unreliable';
    public const DISABLED = 'disabled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [self::ACTIVE, self::PAUSED, self::UNRELIABLE, self::DISABLED];
    }

    public static function assertValid(string $status): string
    {
        if (! in_array($status, self::values(), true)) {
            throw new InvalidArgumentException('Choose a valid source status.');
        }

        return $status;
    }
}
