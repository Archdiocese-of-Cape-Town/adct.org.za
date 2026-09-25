<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Sources;

use InvalidArgumentException;

final class SourceRole
{
    public const OFFICIAL = 'official';
    public const MONITORED = 'monitored';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [self::OFFICIAL, self::MONITORED];
    }

    public static function assertValid(string $role): string
    {
        if (! in_array($role, self::values(), true)) {
            throw new InvalidArgumentException('Choose official or monitored for the source role.');
        }

        return $role;
    }
}
