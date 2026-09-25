<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Directory;

final class CsvFormulaGuard
{
    /**
     * @var list<string>
     */
    private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public static function protect(string $value, bool $phoneColumn = false): string
    {
        if ($phoneColumn && self::isPhoneNumber($value)) {
            return $value;
        }

        if (self::isFormulaPrefix(substr($value, 0, 1))) {
            return "'" . $value;
        }

        if (
            strlen($value) > 1
            && $value[0] === "'"
            && ($value[1] === "'" || self::isFormulaPrefix($value[1]))
        ) {
            return "'" . $value;
        }

        return $value;
    }

    public static function unprotect(string $value): string
    {
        if (strlen($value) < 2 || $value[0] !== "'") {
            return $value;
        }

        if (
            $value[1] === "'"
            && strlen($value) > 2
            && ($value[2] === "'" || self::isFormulaPrefix($value[2]))
        ) {
            return substr($value, 1);
        }

        return self::isFormulaPrefix($value[1])
            ? substr($value, 1)
            : $value;
    }

    private static function isFormulaPrefix(string $character): bool
    {
        return in_array($character, self::FORMULA_PREFIXES, true);
    }

    private static function isPhoneNumber(string $value): bool
    {
        if (preg_match('/^\+[1-9][0-9 ().\/-]*$/D', $value) !== 1) {
            return false;
        }

        $digits = preg_replace('/\D/', '', $value);

        return is_string($digits) && strlen($digits) >= 8 && strlen($digits) <= 15;
    }
}
