<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Approval;

use ADCT\ParishIntake\Core\Support\Text;

final class ApprovalSenderIdentity
{
    public static function format(mixed $address, mixed $name): string
    {
        return self::displayAddress($address) . ' (' . self::displayName($name, $address) . ')';
    }

    private static function displayName(mixed $name, mixed $address): string
    {
        if (! is_string($name) || trim($name) === '') {
            return 'no name supplied';
        }

        $lines = Text::lines(ApprovalPreviewText::shorten($name));
        $first = $lines[0] ?? '';

        // Intake uses the address as the name when the header has no display name.
        if (
            $first === ''
            || (is_string($address) && trim($address) !== '' && strcasecmp($first, trim($address)) === 0)
        ) {
            return 'no name supplied';
        }

        return ApprovalPreviewText::shorten($first);
    }

    private static function displayAddress(mixed $address): string
    {
        $parsed = is_string($address) ? trim($address) : '';

        if ($parsed === '' || filter_var($parsed, FILTER_VALIDATE_EMAIL) === false) {
            return $parsed === '' ? 'Unknown' : $parsed;
        }

        return strtolower($parsed);
    }
}
