<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Events;

final class RRuleValidator
{
    private const ALLOWED_PARTS = [
        'FREQ',
        'INTERVAL',
        'COUNT',
        'UNTIL',
        'BYDAY',
        'BYMONTHDAY',
        'BYMONTH',
        'BYSETPOS',
    ];

    private const FREQUENCIES = [
        'DAILY',
        'WEEKLY',
        'MONTHLY',
        'YEARLY',
    ];

    public function validate(?string $rule, bool $allDay = false): RRuleValidationResult
    {
        $input = trim((string) $rule);

        if ($input === '') {
            return new RRuleValidationResult(null, [], []);
        }

        if (strlen($input) > 512) {
            return new RRuleValidationResult(null, [], ['RRULE must not exceed 512 characters.']);
        }

        if (preg_match('/[\r\n]/', $input) === 1) {
            return new RRuleValidationResult(null, [], ['RRULE must be a single line.']);
        }

        if (strncasecmp($input, 'RRULE:', 6) === 0) {
            $input = substr($input, 6);
        }

        if ($input === '' || preg_match('/\s/', $input) === 1) {
            return new RRuleValidationResult(null, [], ['RRULE must be a non-empty, single-line rule.']);
        }

        $parts = [];
        $errors = [];

        foreach (explode(';', $input) as $component) {
            if (substr_count($component, '=') !== 1) {
                $errors[] = 'RRULE contains an invalid component.';
                continue;
            }

            [$name, $value] = explode('=', $component, 2);
            $name = strtoupper($name);
            $value = strtoupper($value);

            if (! in_array($name, self::ALLOWED_PARTS, true)) {
                $errors[] = 'RRULE contains an unsupported component: ' . $name . '.';
                continue;
            }

            if ($value === '') {
                $errors[] = 'RRULE component ' . $name . ' must not be empty.';
                continue;
            }

            if (isset($parts[$name])) {
                $errors[] = 'RRULE component ' . $name . ' must not be repeated.';
                continue;
            }

            $parts[$name] = $value;
        }

        if (! isset($parts['FREQ'])) {
            $errors[] = 'RRULE must include one FREQ component.';
        } elseif (! in_array($parts['FREQ'], self::FREQUENCIES, true)) {
            $errors[] = 'RRULE frequency must be DAILY, WEEKLY, MONTHLY or YEARLY.';
        }

        if (isset($parts['INTERVAL']) && ! $this->isPositiveInteger($parts['INTERVAL'])) {
            $errors[] = 'RRULE INTERVAL must be a positive integer.';
        }

        if (isset($parts['COUNT']) && ! $this->isPositiveInteger($parts['COUNT'])) {
            $errors[] = 'RRULE COUNT must be a positive integer.';
        }

        if (isset($parts['COUNT'], $parts['UNTIL'])) {
            $errors[] = 'RRULE must not contain both COUNT and UNTIL.';
        }

        if (isset($parts['UNTIL'])) {
            $untilIsDate = preg_match('/^\d{8}$/', $parts['UNTIL']) === 1;

            if (! $this->isValidUntil($parts['UNTIL'])) {
                $errors[] = 'RRULE UNTIL must be a valid RFC 5545 date or date-time.';
            } elseif ($allDay && ! $untilIsDate) {
                $errors[] = 'UNTIL must be a date for an all-day event.';
            } elseif (! $allDay && $untilIsDate) {
                $errors[] = 'UNTIL must include a time for a timed event.';
            }
        }

        $frequency = $parts['FREQ'] ?? '';

        if (isset($parts['BYDAY'])) {
            $ordinalUsed = false;

            foreach (explode(',', $parts['BYDAY']) as $day) {
                if (preg_match('/^([+-]?[1-9]\d{0,1})?(MO|TU|WE|TH|FR|SA|SU)$/', $day, $matches) !== 1) {
                    $errors[] = 'RRULE BYDAY contains an invalid weekday or ordinal.';
                    continue;
                }

                if (($matches[1] ?? '') !== '') {
                    $ordinal = (int) $matches[1];

                    if ($ordinal < -53 || $ordinal > 53) {
                        $errors[] = 'RRULE BYDAY ordinals must be between -53 and -1 or 1 and 53.';
                    }

                    $ordinalUsed = true;
                }
            }

            if ($ordinalUsed && ! in_array($frequency, ['MONTHLY', 'YEARLY'], true)) {
                $errors[] = 'RRULE BYDAY ordinals are only valid for MONTHLY or YEARLY rules.';
            }
        }

        if (isset($parts['BYMONTHDAY'])) {
            foreach (explode(',', $parts['BYMONTHDAY']) as $day) {
                if (! $this->isIntegerInRange($day, -31, 31) || (int) $day === 0) {
                    $errors[] = 'RRULE BYMONTHDAY values must be from -31 to -1 or 1 to 31.';
                    break;
                }
            }

            if ($frequency === 'WEEKLY') {
                $errors[] = 'RRULE BYMONTHDAY cannot be used with WEEKLY frequency.';
            }
        }

        if (isset($parts['BYMONTH'])) {
            foreach (explode(',', $parts['BYMONTH']) as $month) {
                if (! $this->isIntegerInRange($month, 1, 12)) {
                    $errors[] = 'RRULE BYMONTH values must be between 1 and 12.';
                    break;
                }
            }
        }

        if (isset($parts['BYSETPOS'])) {
            foreach (explode(',', $parts['BYSETPOS']) as $position) {
                if (! $this->isIntegerInRange($position, -366, 366) || (int) $position === 0) {
                    $errors[] = 'RRULE BYSETPOS values must be from -366 to -1 or 1 to 366.';
                    break;
                }
            }

            if (! isset($parts['BYDAY']) && ! isset($parts['BYMONTHDAY']) && ! isset($parts['BYMONTH'])) {
                $errors[] = 'RRULE BYSETPOS requires another BY rule.';
            }
        }

        $normalizedRule = $errors === []
            ? implode(';', array_map(
                static fn (string $name, string $value): string => $name . '=' . $value,
                array_keys($parts),
                array_values($parts)
            ))
            : null;

        return new RRuleValidationResult($normalizedRule, $parts, array_values(array_unique($errors)));
    }

    private function isPositiveInteger(string $value): bool
    {
        if (preg_match('/^\d+$/', $value) !== 1) {
            return false;
        }

        $canonical = ltrim($value, '0');

        if ($canonical === '') {
            return false;
        }

        $maximum = (string) PHP_INT_MAX;

        return strlen($canonical) < strlen($maximum)
            || (
                strlen($canonical) === strlen($maximum)
                && strcmp($canonical, $maximum) <= 0
            );
    }

    private function isIntegerInRange(string $value, int $minimum, int $maximum): bool
    {
        if (preg_match('/^-?\d+$/', $value) !== 1) {
            return false;
        }

        $number = (int) $value;

        return $number >= $minimum && $number <= $maximum;
    }

    private function isValidUntil(string $value): bool
    {
        if (preg_match('/^\d{8}$/', $value) === 1) {
            return $this->isValidDate(substr($value, 0, 4), substr($value, 4, 2), substr($value, 6, 2));
        }

        if (preg_match('/^\d{8}T\d{6}Z?$/', $value) !== 1) {
            return false;
        }

        return $this->isValidDate(substr($value, 0, 4), substr($value, 4, 2), substr($value, 6, 2))
            && (int) substr($value, 9, 2) <= 23
            && (int) substr($value, 11, 2) <= 59
            && (int) substr($value, 13, 2) <= 59;
    }

    private function isValidDate(string $year, string $month, string $day): bool
    {
        return checkdate((int) $month, (int) $day, (int) $year);
    }
}
