<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

use InvalidArgumentException;

final class EmailAddress
{
    public static function normalize(string $email): string
    {
        $normalized = strtolower(trim($email));

        if (
            $normalized === ''
            || strlen($normalized) > 191
            || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new InvalidArgumentException('The email address is not valid.');
        }

        return $normalized;
    }
}
