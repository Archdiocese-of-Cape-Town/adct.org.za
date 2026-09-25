<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Events;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class ListingRange
{
    public readonly DateTimeImmutable $from;
    public readonly DateTimeImmutable $through;

    public function __construct(
        string $period,
        string $from,
        string $through,
        DateTimeImmutable $now,
        DateTimeZone $timezone
    ) {
        $today = $now->setTimezone($timezone)->setTime(0, 0);
        $last = OccurrenceWindow::rollingTwelveMonths($now, $timezone)->endLocal;

        if ($period === 'week') {
            $start = $today->modify('monday this week');
            $end = $start->modify('+6 days');
        } elseif ($period === 'month') {
            $start = $today->modify('first day of this month');
            $end = $start->modify('last day of this month');
        } elseif ($period === 'range') {
            $start = self::parseDate($from, $timezone);
            $end = self::parseDate($through, $timezone);
        } elseif ($period === 'upcoming') {
            $start = $today;
            $end = $last;
        } else {
            throw new InvalidArgumentException('Choose a valid date filter.');
        }

        if ($start > $end || $start > $last || $end < $today || $end > $last) {
            throw new InvalidArgumentException('Choose a date range within the next year.');
        }

        $this->from = $start < $today ? $today : $start;
        $this->through = $end;
    }

    private static function parseDate(string $value, DateTimeZone $timezone): DateTimeImmutable
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('Enter dates in YYYY-MM-DD format.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Enter valid calendar dates.');
        }

        return $date;
    }
}
