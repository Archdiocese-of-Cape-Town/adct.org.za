<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Events;

final class RecurrenceSummary
{
    public static function describe(string $rrule): string
    {
        $parts = [];
        foreach (explode(';', $rrule) as $part) {
            $pair = explode('=', $part, 2);
            if (count($pair) === 2) {
                $parts[$pair[0]] = $pair[1];
            }
        }
        $interval = isset($parts['INTERVAL']) && ctype_digit($parts['INTERVAL'])
            ? (int) $parts['INTERVAL'] : 1;
        $weekday = [
            'MO' => 'Monday', 'TU' => 'Tuesday', 'WE' => 'Wednesday',
            'TH' => 'Thursday', 'FR' => 'Friday', 'SA' => 'Saturday', 'SU' => 'Sunday',
        ];
        $byday = $parts['BYDAY'] ?? '';
        $position = $parts['BYSETPOS'] ?? '';
        if (preg_match('/^(-?[1-4])?(MO|TU|WE|TH|FR|SA|SU)$/', $byday, $matches) === 1) {
            $position = $position !== '' ? $position : ($matches[1] ?? '');
            $day = $weekday[$matches[2]];
            if (($parts['FREQ'] ?? '') === 'MONTHLY' && $interval === 1
                && in_array($position, ['1', '2', '3', '4', '-1'], true)) {
                $ordinal = ['1' => 'first', '2' => 'second', '3' => 'third', '4' => 'fourth', '-1' => 'last'];
                return 'Every ' . $ordinal[$position] . ' ' . $day;
            }
            if (($parts['FREQ'] ?? '') === 'WEEKLY' && $interval === 1) {
                return 'Every ' . $day;
            }
        }
        if (($parts['FREQ'] ?? '') === 'MONTHLY' && $interval === 1
            && preg_match('/^([1-9]|[12][0-9]|3[01])$/', $parts['BYMONTHDAY'] ?? '') === 1) {
            return 'Every month on day ' . $parts['BYMONTHDAY'];
        }
        return match ($parts['FREQ'] ?? '') {
            'DAILY' => $interval === 1 ? 'Every day' : 'Every ' . $interval . ' days',
            'WEEKLY' => $interval === 1 ? 'Every week' : 'Every ' . $interval . ' weeks',
            'MONTHLY' => $interval === 1 ? 'Every month' : 'Every ' . $interval . ' months',
            'YEARLY' => $interval === 1 ? 'Every year' : 'Every ' . $interval . ' years',
            default => 'Recurring event',
        };
    }
}
