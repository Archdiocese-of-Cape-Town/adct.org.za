<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Events;

use DateTimeImmutable;
use DateTimeZone;

final class EventPresentation
{
    private const WEEKDAY_NAMES = [
        'MO' => 'Monday',
        'TU' => 'Tuesday',
        'WE' => 'Wednesday',
        'TH' => 'Thursday',
        'FR' => 'Friday',
        'SA' => 'Saturday',
        'SU' => 'Sunday',
    ];

    private const ORDINAL_NAMES = [
        -1 => 'last',
        1 => 'first',
        2 => 'second',
        3 => 'third',
        4 => 'fourth',
        5 => 'fifth',
    ];

    private const GOOGLE_CALENDAR_BASE = 'https://calendar.google.com/calendar/render';

    public static function recurrencePhrase(?string $rrule): string
    {
        $validation = (new RRuleValidator())->validate($rrule);

        if ($validation->normalizedRule === null) {
            return 'One-off event';
        }

        $parts = $validation->parts;
        $frequency = $parts['FREQ'] ?? '';
        $interval = isset($parts['INTERVAL']) ? max(1, (int) $parts['INTERVAL']) : 1;

        if ($frequency === '') {
            return 'Repeats according to the published schedule';
        }

        $phrase = match ($frequency) {
            'DAILY' => $interval === 1 ? 'Every day' : 'Every ' . $interval . ' days',
            'WEEKLY' => $interval === 1 ? 'Every week' : 'Every ' . $interval . ' weeks',
            'MONTHLY' => $interval === 1 ? 'Every month' : 'Every ' . $interval . ' months',
            'YEARLY' => $interval === 1 ? 'Every year' : 'Every ' . $interval . ' years',
            default => 'Repeats according to the published schedule',
        };

        $details = [];

        if (isset($parts['BYDAY'])) {
            $days = [];
            foreach (explode(',', $parts['BYDAY']) as $day) {
                if (preg_match('/^([+-]?[1-9]\d{0,1})?(MO|TU|WE|TH|FR|SA|SU)$/', $day, $matches) !== 1) {
                    continue;
                }

                $weekday = self::WEEKDAY_NAMES[$matches[2]] ?? $matches[2];
                if (($matches[1] ?? '') !== '') {
                    $ordinal = (int) $matches[1];
                    $days[] = ($ordinal < 0 ? 'last' : self::ordinalWord($ordinal)) . ' ' . $weekday;
                } else {
                    $days[] = $weekday;
                }
            }

            if ($days !== []) {
                $details[] = $frequency === 'WEEKLY' && $interval === 1
                    ? 'on ' . self::joinWithAnd($days)
                    : 'on ' . self::joinWithAnd($days);
            }
        } elseif (isset($parts['BYMONTHDAY'])) {
            $days = array_map(
                static fn (string $day): string => (int) $day < 0
                    ? 'the ' . self::ordinalWord(abs((int) $day)) . ' from the end'
                    : 'day ' . (string) (int) $day,
                explode(',', $parts['BYMONTHDAY'])
            );
            if ($days !== []) {
                $details[] = 'on ' . self::joinWithAnd($days);
            }
        }

        if (isset($parts['BYMONTH'])) {
            $months = [];
            foreach (explode(',', $parts['BYMONTH']) as $month) {
                $monthNumber = (int) $month;
                if ($monthNumber >= 1 && $monthNumber <= 12) {
                    $months[] = self::monthName($monthNumber);
                }
            }
            if ($months !== []) {
                $details[] = 'in ' . self::joinWithAnd($months);
            }
        }

        if (isset($parts['COUNT'])) {
            $details[] = 'for ' . (int) $parts['COUNT'] . ' occurrences';
        } elseif (isset($parts['UNTIL'])) {
            $details[] = 'until ' . self::formatUntil($parts['UNTIL']);
        }

        if ($details === []) {
            return $phrase;
        }

        return $phrase . ' ' . implode(' ', $details);
    }

    /**
     * @param DateTimeImmutable $startLocal The event start in the site's local timezone.
     */
    public static function googleCalendarUrl(
        string $title,
        string $description,
        string $location,
        DateTimeImmutable $startLocal,
        ?DateTimeImmutable $endLocal,
        bool $allDay,
        ?string $rrule = null
    ): string {
        $utc = new DateTimeZone('UTC');
        $johannesburg = new DateTimeZone('Africa/Johannesburg');
        $end = $endLocal ?? ($allDay ? $startLocal->modify('+1 day') : $startLocal->modify('+1 hour'));

        $query = [
            'action' => 'TEMPLATE',
            'text' => $title,
            'details' => $description,
        ];

        if ($location !== '') {
            $query['location'] = $location;
        }

        if ($allDay) {
            $startDate = $startLocal->setTimezone($johannesburg)->setTime(0, 0);
            $endDate = $end->setTimezone($johannesburg)->setTime(0, 0)->modify('+1 day');

            $query['dates'] = $startDate->format('Ymd')
                . '/'
                . $endDate->format('Ymd');
        } else {
            $query['dates'] = $startLocal->setTimezone($utc)->format('Ymd\THis\Z')
                . '/'
                . $end->setTimezone($utc)->format('Ymd\THis\Z');
        }

        $normalizedRule = trim((string) $rrule);
        if ($normalizedRule !== '') {
            $normalizedRule = strncasecmp($normalizedRule, 'RRULE:', 6) === 0
                ? substr($normalizedRule, 6)
                : $normalizedRule;
            $query['recur'] = 'RRULE:' . $normalizedRule;
        }

        return self::GOOGLE_CALENDAR_BASE . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public static function mapUrl(?float $latitude, ?float $longitude, ?string $address = null): ?string
    {
        if ($latitude !== null && $longitude !== null) {
            return 'https://www.google.com/maps/search/?api=1&query='
                . rawurlencode($latitude . ',' . $longitude);
        }

        $location = trim((string) $address);
        if ($location === '') {
            return null;
        }

        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($location);
    }

    private static function joinWithAnd(array $items): string
    {
        $items = array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
        $count = count($items);

        if ($count === 0) {
            return '';
        }

        if ($count === 1) {
            return $items[0];
        }

        if ($count === 2) {
            return $items[0] . ' and ' . $items[1];
        }

        return implode(', ', array_slice($items, 0, -1)) . ', and ' . $items[$count - 1];
    }

    private static function ordinalWord(int $value): string
    {
        return self::ORDINAL_NAMES[$value] ?? ((string) $value . 'th');
    }

    private static function monthName(int $month): string
    {
        static $months = [
            1 => 'January',
            2 => 'February',
            3 => 'March',
            4 => 'April',
            5 => 'May',
            6 => 'June',
            7 => 'July',
            8 => 'August',
            9 => 'September',
            10 => 'October',
            11 => 'November',
            12 => 'December',
        ];

        return $months[$month] ?? (string) $month;
    }

    private static function formatUntil(string $value): string
    {
        $utc = new DateTimeZone('UTC');
        $local = new DateTimeZone('Africa/Johannesburg');

        if (preg_match('/^\d{8}$/', $value) === 1) {
            $date = DateTimeImmutable::createFromFormat('!Ymd', $value, $local);
            if ($date instanceof DateTimeImmutable) {
                return $date->format('j F Y');
            }
        }

        $suffix = str_ends_with($value, 'Z') ? 'Z' : '';
        $format = $suffix === 'Z' ? '!Ymd\THis\Z' : '!Ymd\THis';
        $date = DateTimeImmutable::createFromFormat($format, $value, $suffix === 'Z' ? $utc : $local);
        if ($date instanceof DateTimeImmutable) {
            return $date->setTimezone($local)->format('j F Y H:i');
        }

        return $value;
    }
}
