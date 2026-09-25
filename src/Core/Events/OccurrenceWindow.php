<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Events;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class OccurrenceWindow
{
    public readonly DateTimeImmutable $startLocal;
    public readonly DateTimeImmutable $endLocal;

    public function __construct(DateTimeImmutable $startLocal, DateTimeImmutable $endLocal)
    {
        $timezone = $startLocal->getTimezone();

        if ($timezone === false) {
            throw new InvalidArgumentException('The occurrence window requires a local timezone.');
        }

        $this->startLocal = $startLocal->setTime(0, 0, 0);
        $this->endLocal = $endLocal->setTimezone($timezone)->setTime(0, 0, 0);

        if ($this->endLocal->format('Y-m-d') < $this->startLocal->format('Y-m-d')) {
            throw new InvalidArgumentException('The occurrence window end must not precede its start.');
        }
    }

    public static function rollingTwelveMonths(DateTimeImmutable $now, DateTimeZone $timezone): self
    {
        $start = $now->setTimezone($timezone)->setTime(0, 0, 0);
        $targetYear = (int) $start->format('Y') + 1;
        $month = (int) $start->format('n');
        $day = (int) $start->format('j');
        $targetMonth = $start->setDate($targetYear, $month, 1);
        $lastDay = (int) $targetMonth->modify('last day of this month')->format('j');
        $end = $targetMonth->setDate($targetYear, $month, min($day, $lastDay));

        return new self($start, $end);
    }

    public static function fromLocalDates(
        string $startLocalDate,
        string $endLocalDate,
        DateTimeZone $timezone
    ): self {
        $start = self::parseLocalDate($startLocalDate, $timezone);
        $end = self::parseLocalDate($endLocalDate, $timezone);

        return new self($start, $end);
    }

    public function contains(DateTimeImmutable $localDateTime): bool
    {
        $localDate = $localDateTime->setTimezone($this->startLocal->getTimezone())->format('Y-m-d');

        return $localDate >= $this->startLocal->format('Y-m-d')
            && $localDate <= $this->endLocal->format('Y-m-d');
    }

    private static function parseLocalDate(string $value, DateTimeZone $timezone): DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidArgumentException('Occurrence window boundaries must be local YYYY-MM-DD dates.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            throw new InvalidArgumentException('Occurrence window boundaries must be valid local dates.');
        }

        return $date;
    }
}
