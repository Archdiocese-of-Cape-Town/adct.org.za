<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\WordPress\Approval;

use DateTimeImmutable;
use DateTimeZone;

final class ApprovalPreviewText
{
    public static function date(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Africa/Johannesburg'));
        return $date !== false && $date->format('Y-m-d') === $value
            ? $date->format('j F Y') : $value;
    }

    public static function editDate(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Africa/Johannesburg'));
        return $date !== false && $date->format('Y-m-d') === $value
            ? $date->format('d/m/Y') : $value;
    }

    public static function shorten(string $value): string
    {
        if (strlen($value) <= 1000) {
            return $value;
        }
        $value = substr($value, 0, 1000);
        while ($value !== '' && preg_match('//u', $value) !== 1) {
            $value = substr($value, 0, -1);
        }
        return $value . '...';
    }
}
