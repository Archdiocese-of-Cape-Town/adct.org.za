<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Events;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;

final class OccurrenceExpander
{
    private const WEEKDAYS = [
        'MO' => 1,
        'TU' => 2,
        'WE' => 3,
        'TH' => 4,
        'FR' => 5,
        'SA' => 6,
        'SU' => 7,
    ];

    private const GREGORIAN_CYCLE_DAYS = 146097;
    private const GREGORIAN_CYCLE_WEEKS = 20871;
    private const GREGORIAN_CYCLE_MONTHS = 4800;
    private const GREGORIAN_CYCLE_YEARS = 400;

    private RRuleValidator $rruleValidator;
    private EventValidator $eventValidator;
    private DateTimeZone $utc;

    public function __construct(
        private DateTimeZone $timezone,
        ?RRuleValidator $rruleValidator = null
    ) {
        $this->rruleValidator = $rruleValidator ?? new RRuleValidator();
        $this->eventValidator = new EventValidator($timezone, $this->rruleValidator);
        $this->utc = new DateTimeZone('UTC');
    }

    /**
     * @return list<ExpandedOccurrence>
     */
    public function expand(EventDetails $details, OccurrenceWindow $window): array
    {
        if (
            $window->startLocal->getTimezone()->getName()
            !== $this->timezone->getName()
        ) {
            throw new InvalidArgumentException('The occurrence window must use the event timezone.');
        }

        $validation = $this->eventValidator->validate($details);

        if (! $validation->isValid()) {
            throw new DomainException(
                'Event details cannot be expanded: ' . implode(' ', $validation->errors)
            );
        }

        $values = $validation->values;
        $start = $this->parseLocalDateTime($values['start_local']);
        $end = $values['end_local'] === null
            ? null
            : $this->parseLocalDateTime($values['end_local']);
        $rule = $this->rruleValidator->validate($values['rrule'], $details->allDay);
        $candidateStarts = [];

        if ($rule->normalizedRule === null) {
            $this->addCandidate($candidateStarts, $start, $window);
        } else {
            foreach ($this->expandRule($start, $rule->parts, $window, $details->allDay) as $occurrenceStart) {
                $this->addCandidate($candidateStarts, $occurrenceStart, $window);
            }
        }

        foreach ($values['rdates'] as $rdate) {
            $this->addCandidate($candidateStarts, $this->parseLocalDateTime($rdate), $window);
        }

        $excludedStarts = array_fill_keys($values['exdates'], true);
        $candidateStarts = array_diff_key($candidateStarts, $excludedStarts);
        ksort($candidateStarts, SORT_STRING);

        return $this->withEnds(array_values($candidateStarts), $start, $end, $details->allDay);
    }

    /**
     * @param array<string, string> $parts
     * @return list<DateTimeImmutable>
     */
    private function expandRule(
        DateTimeImmutable $start,
        array $parts,
        OccurrenceWindow $window,
        bool $allDay
    ): array {
        $frequency = $parts['FREQ'];
        $interval = isset($parts['INTERVAL']) ? (int) $parts['INTERVAL'] : 1;
        $countLimit = isset($parts['COUNT']) ? (int) $parts['COUNT'] : null;
        $until = $this->parseUntil($parts['UNTIL'] ?? null, $allDay);
        $starts = [];

        if ($this->isWithinUntil($start, $until, $allDay)) {
            $this->addCandidate($starts, $start, $window);
        }

        if (! $this->canReachWindow($start, $until, $window, $allDay)) {
            return array_values($starts);
        }

        $occurrencesBeforeWindow = $countLimit === null
            ? 0
            : $this->countBeforeWindow($start, $parts, $window, $countLimit);

        if ($countLimit !== null && $occurrencesBeforeWindow >= $countLimit) {
            return array_values($starts);
        }

        $occurrenceCount = $occurrencesBeforeWindow;

        if (
            $countLimit !== null
            && $window->contains($start)
            && $this->isWithinUntil($start, $until, $allDay)
        ) {
            ++$occurrenceCount;
        }

        $firstPeriod = $this->firstPeriodForWindowStart(
            $start,
            $frequency,
            $interval,
            $window->startLocal
        );
        $lastPeriod = $this->lastPeriodForWindowEnd(
            $start,
            $frequency,
            $interval,
            $window->endLocal
        );

        if ($lastPeriod < $firstPeriod) {
            return array_values($starts);
        }

        for ($periodIndex = $firstPeriod; $periodIndex <= $lastPeriod; ++$periodIndex) {
            foreach ($this->candidatesForPeriod($start, $parts, $periodIndex) as $candidate) {
                if (
                    $candidate <= $start
                    || ! $window->contains($candidate)
                    || ! $this->isWithinUntil($candidate, $until, $allDay)
                ) {
                    continue;
                }

                if ($countLimit !== null && $occurrenceCount >= $countLimit) {
                    return array_values($starts);
                }

                $this->addCandidate($starts, $candidate, $window);

                if ($countLimit !== null) {
                    ++$occurrenceCount;
                }
            }
        }

        return array_values($starts);
    }

    /**
     * Count the RRULE instances before the inclusive local-date window boundary.
     *
     * @param array<string, string> $parts
     */
    private function countBeforeWindow(
        DateTimeImmutable $start,
        array $parts,
        OccurrenceWindow $window,
        int $countLimit
    ): int {
        $boundary = $window->startLocal;

        if ($start->format('Y-m-d') >= $boundary->format('Y-m-d')) {
            return 0;
        }

        $count = 1;

        if ($count >= $countLimit) {
            return $countLimit;
        }

        $frequency = $parts['FREQ'];
        $interval = isset($parts['INTERVAL']) ? (int) $parts['INTERVAL'] : 1;

        if (
            $frequency === 'DAILY'
            && ! isset($parts['BYDAY'])
            && ! isset($parts['BYMONTHDAY'])
            && ! isset($parts['BYMONTH'])
            && ! isset($parts['BYSETPOS'])
        ) {
            return min(
                $this->ceilingDivision(
                    $this->calendarDaysBetween($start, $boundary),
                    $interval
                ),
                $countLimit
            );
        }

        $firstPeriodAtOrAfterBoundary = $this->firstPeriodAtOrAfterDate(
            $start,
            $frequency,
            $interval,
            $boundary
        );
        $boundaryDate = $boundary->format('Y-m-d');

        foreach ($this->candidatesForPeriod($start, $parts, 0) as $candidate) {
            if ($candidate > $start && $candidate->format('Y-m-d') < $boundaryDate) {
                ++$count;

                if ($count >= $countLimit) {
                    return $countLimit;
                }
            }
        }

        $completePeriods = max(0, $firstPeriodAtOrAfterBoundary - 2);
        $count = $this->countCompletePeriodsBefore(
            $start,
            $parts,
            $completePeriods,
            $count,
            $countLimit
        );

        if ($count >= $countLimit || $firstPeriodAtOrAfterBoundary < 2) {
            return min($count, $countLimit);
        }

        $lastPartialPeriod = $firstPeriodAtOrAfterBoundary - 1;

        foreach ($this->candidatesForPeriod($start, $parts, $lastPartialPeriod) as $candidate) {
            if ($candidate->format('Y-m-d') < $boundaryDate) {
                ++$count;

                if ($count >= $countLimit) {
                    return $countLimit;
                }
            }
        }

        return min($count, $countLimit);
    }

    /**
     * @param array<string, string> $parts
     */
    private function countCompletePeriodsBefore(
        DateTimeImmutable $start,
        array $parts,
        int $periodCount,
        int $count,
        int $countLimit
    ): int {
        if ($periodCount < 1) {
            return $count;
        }

        $frequency = $parts['FREQ'];
        $interval = isset($parts['INTERVAL']) ? (int) $parts['INTERVAL'] : 1;
        $cycleLength = $this->cycleLength($frequency, $interval);
        $periodsInFirstCycle = min($periodCount, $cycleLength);
        $firstCycleCount = 0;

        for ($offset = 0; $offset < $periodsInFirstCycle; ++$offset) {
            $firstCycleCount += count($this->candidatesForPeriod($start, $parts, $offset + 1));

            if ($firstCycleCount >= $countLimit - $count) {
                return $countLimit;
            }
        }

        if ($periodCount <= $cycleLength) {
            return $count + $firstCycleCount;
        }

        $cycleCount = $firstCycleCount;

        if ($cycleCount === 0) {
            return $count;
        }

        $wholeCycles = intdiv($periodCount, $cycleLength);
        $remainingCount = $countLimit - $count;

        if ($wholeCycles >= intdiv($remainingCount - 1, $cycleCount) + 1) {
            return $countLimit;
        }

        $count += $cycleCount * $wholeCycles;
        $remainingPeriods = $periodCount % $cycleLength;

        for ($offset = 0; $offset < $remainingPeriods; ++$offset) {
            $count += count($this->candidatesForPeriod($start, $parts, $offset + 1));

            if ($count >= $countLimit) {
                return $countLimit;
            }
        }

        return $count;
    }

    /**
     * @param array<string, string> $parts
     * @return list<DateTimeImmutable>
     */
    private function candidatesForPeriod(DateTimeImmutable $start, array $parts, int $periodIndex): array
    {
        $frequency = $parts['FREQ'];
        $periodStart = $this->periodStart($start, $frequency, $parts, $periodIndex);
        $candidates = [];

        if ($frequency === 'DAILY') {
            $candidate = $periodStart->setTime(
                (int) $start->format('H'),
                (int) $start->format('i')
            );

            if ($this->matchesDateRules($candidate, $start, $parts)) {
                $candidates[] = $candidate;
            }
        } elseif ($frequency === 'WEEKLY') {
            for ($offset = 0; $offset < 7; ++$offset) {
                $candidate = $periodStart->modify('+' . $offset . ' days')->setTime(
                    (int) $start->format('H'),
                    (int) $start->format('i')
                );

                if ($this->matchesDateRules($candidate, $start, $parts)) {
                    $candidates[] = $candidate;
                }
            }
        } elseif ($frequency === 'MONTHLY') {
            $daysInMonth = (int) $periodStart->modify('last day of this month')->format('j');

            for ($day = 1; $day <= $daysInMonth; ++$day) {
                $candidate = $periodStart->setDate(
                    (int) $periodStart->format('Y'),
                    (int) $periodStart->format('n'),
                    $day
                )->setTime((int) $start->format('H'), (int) $start->format('i'));

                if ($this->matchesDateRules($candidate, $start, $parts)) {
                    $candidates[] = $candidate;
                }
            }
        } else {
            $daysInYear = (int) $periodStart->setDate(
                (int) $periodStart->format('Y'),
                12,
                31
            )->format('z') + 1;

            for ($offset = 0; $offset < $daysInYear; ++$offset) {
                $candidate = $periodStart->modify('+' . $offset . ' days')->setTime(
                    (int) $start->format('H'),
                    (int) $start->format('i')
                );

                if ($this->matchesDateRules($candidate, $start, $parts)) {
                    $candidates[] = $candidate;
                }
            }
        }

        $candidates = $this->applySetPositions($candidates, $parts['BYSETPOS'] ?? null);
        $unique = [];

        foreach ($candidates as $candidate) {
            $unique[$candidate->format('Y-m-d\TH:i')] = $candidate;
        }

        ksort($unique, SORT_STRING);

        return array_values($unique);
    }

    /**
     * @param array<string, string> $parts
     */
    private function matchesDateRules(
        DateTimeImmutable $candidate,
        DateTimeImmutable $start,
        array $parts
    ): bool {
        if (
            isset($parts['BYMONTH'])
            && ! in_array((int) $candidate->format('n'), $this->integerList($parts['BYMONTH']), true)
        ) {
            return false;
        }

        if (
            isset($parts['BYMONTHDAY'])
            && ! $this->matchesMonthDay($candidate, $parts['BYMONTHDAY'])
        ) {
            return false;
        }

        if (isset($parts['BYDAY'])) {
            if (! $this->matchesByDay($candidate, $parts['BYDAY'], $parts)) {
                return false;
            }
        } elseif (
            $parts['FREQ'] === 'WEEKLY'
            && $candidate->format('N') !== $start->format('N')
        ) {
            return false;
        }

        if (
            $parts['FREQ'] === 'MONTHLY'
            && ! isset($parts['BYDAY'])
            && ! isset($parts['BYMONTHDAY'])
            && (int) $candidate->format('j') !== (int) $start->format('j')
        ) {
            return false;
        }

        if (
            $parts['FREQ'] === 'YEARLY'
            && ! isset($parts['BYDAY'])
            && ! isset($parts['BYMONTHDAY'])
            && (int) $candidate->format('j') !== (int) $start->format('j')
        ) {
            return false;
        }

        if (
            $parts['FREQ'] === 'YEARLY'
            && ! isset($parts['BYDAY'])
            && ! isset($parts['BYMONTHDAY'])
            && ! isset($parts['BYMONTH'])
            && (int) $candidate->format('n') !== (int) $start->format('n')
        ) {
            return false;
        }

        return true;
    }

    private function matchesMonthDay(DateTimeImmutable $candidate, string $rule): bool
    {
        $day = (int) $candidate->format('j');
        $daysInMonth = (int) $candidate->modify('last day of this month')->format('j');

        foreach ($this->integerList($rule) as $monthDay) {
            if ($monthDay > 0 && $day === $monthDay) {
                return true;
            }

            if ($monthDay < 0 && $day === $daysInMonth + $monthDay + 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $parts
     */
    private function matchesByDay(DateTimeImmutable $candidate, string $rule, array $parts): bool
    {
        foreach (explode(',', $rule) as $specification) {
            if (
                preg_match('/^([+-]?[1-9]\d{0,1})?(MO|TU|WE|TH|FR|SA|SU)$/', $specification, $matches) !== 1
            ) {
                throw new InvalidArgumentException('A validated BYDAY component could not be expanded.');
            }

            $weekday = self::WEEKDAYS[$matches[2]];

            if ((int) $candidate->format('N') !== $weekday) {
                continue;
            }

            if (($matches[1] ?? '') === '') {
                return true;
            }

            $ordinal = (int) $matches[1];
            $usesMonthOrdinal = $parts['FREQ'] === 'MONTHLY'
                || ($parts['FREQ'] === 'YEARLY' && isset($parts['BYMONTH']));
            $actualOrdinal = $usesMonthOrdinal
                ? $this->weekdayOrdinalInMonth($candidate, $ordinal)
                : $this->weekdayOrdinalInYear($candidate, $ordinal);

            if ($actualOrdinal === $ordinal) {
                return true;
            }
        }

        return false;
    }

    private function weekdayOrdinalInMonth(DateTimeImmutable $candidate, int $requestedOrdinal): int
    {
        $day = (int) $candidate->format('j');
        $daysInMonth = (int) $candidate->modify('last day of this month')->format('j');
        $ordinalFromStart = intdiv($day - 1, 7) + 1;
        $ordinalFromEnd = -(intdiv($daysInMonth - $day, 7) + 1);

        return $requestedOrdinal > 0 ? $ordinalFromStart : $ordinalFromEnd;
    }

    private function weekdayOrdinalInYear(DateTimeImmutable $candidate, int $requestedOrdinal): int
    {
        $yearStart = $candidate->setDate((int) $candidate->format('Y'), 1, 1);
        $daysInYear = (int) $candidate->setDate((int) $candidate->format('Y'), 12, 31)->format('z') + 1;
        $weekday = (int) $candidate->format('N');
        $firstMatch = ($weekday - (int) $yearStart->format('N') + 7) % 7;
        $lastDate = $yearStart->modify('+' . ($daysInYear - 1) . ' days');
        $lastMatch = $daysInYear - 1
            - (((int) $lastDate->format('N') - $weekday + 7) % 7);
        $dayOfYear = (int) $candidate->format('z');

        if ($requestedOrdinal > 0) {
            return intdiv($dayOfYear - $firstMatch, 7) + 1;
        }

        return -(intdiv($lastMatch - $dayOfYear, 7) + 1);
    }

    /**
     * @param list<DateTimeImmutable> $candidates
     * @return list<DateTimeImmutable>
     */
    private function applySetPositions(array $candidates, ?string $setPositions): array
    {
        if ($setPositions === null || $candidates === []) {
            return $candidates;
        }

        $selected = [];
        $candidateCount = count($candidates);

        foreach ($this->integerList($setPositions) as $position) {
            $index = $position > 0
                ? $position - 1
                : $candidateCount + $position;

            if (isset($candidates[$index])) {
                $selected[$index] = $candidates[$index];
            }
        }

        ksort($selected, SORT_NUMERIC);

        return array_values($selected);
    }

    /**
     * @param array<string, string> $parts
     */
    private function periodStart(
        DateTimeImmutable $start,
        string $frequency,
        array $parts,
        int $periodIndex
    ): DateTimeImmutable {
        if ($periodIndex < 0) {
            throw new InvalidArgumentException('A recurrence period index cannot be negative.');
        }

        $interval = isset($parts['INTERVAL']) ? (int) $parts['INTERVAL'] : 1;

        if ($frequency === 'DAILY') {
            $base = $start->setTime(0, 0, 0);
            $days = $periodIndex * $interval;

            return $days === 0 ? $base : $base->modify('+' . $days . ' days');
        }

        if ($frequency === 'WEEKLY') {
            $weekdayOffset = (int) $start->format('N') - 1;
            $base = $start->setTime(0, 0, 0)->modify('-' . $weekdayOffset . ' days');
            $days = $periodIndex * $interval * 7;

            return $days === 0 ? $base : $base->modify('+' . $days . ' days');
        }

        if ($frequency === 'MONTHLY') {
            $base = $start->setDate((int) $start->format('Y'), (int) $start->format('n'), 1)
                ->setTime(0, 0, 0);
            $months = $periodIndex * $interval;

            return $months === 0 ? $base : $base->modify('+' . $months . ' months');
        }

        $years = $periodIndex * $interval;
        $year = (int) $start->format('Y') + $years;

        return $start->setDate($year, 1, 1)->setTime(0, 0, 0);
    }

    private function firstPeriodForWindowStart(
        DateTimeImmutable $start,
        string $frequency,
        int $interval,
        DateTimeImmutable $windowStart
    ): int {
        if ($windowStart->format('Y-m-d') <= $start->format('Y-m-d')) {
            return 0;
        }

        if ($frequency === 'DAILY') {
            return $this->ceilingDivision(
                $this->calendarDaysBetween($start, $windowStart),
                $interval
            );
        }

        if ($frequency === 'WEEKLY') {
            $startWeek = $this->weekStart($start);
            $days = $this->calendarDaysBetween($startWeek, $windowStart);
            $weekIndex = intdiv($days, 7);

            return intdiv($weekIndex, $interval);
        }

        if ($frequency === 'MONTHLY') {
            return intdiv($this->monthsBetween($start, $windowStart), $interval);
        }

        return intdiv(
            max(0, (int) $windowStart->format('Y') - (int) $start->format('Y')),
            $interval
        );
    }

    private function lastPeriodForWindowEnd(
        DateTimeImmutable $start,
        string $frequency,
        int $interval,
        DateTimeImmutable $windowEnd
    ): int {
        if ($windowEnd->format('Y-m-d') < $start->format('Y-m-d')) {
            return -1;
        }

        if ($frequency === 'DAILY') {
            return intdiv($this->calendarDaysBetween($start, $windowEnd), $interval);
        }

        if ($frequency === 'WEEKLY') {
            $days = $this->calendarDaysBetween($this->weekStart($start), $windowEnd);

            return intdiv(intdiv($days, 7), $interval);
        }

        if ($frequency === 'MONTHLY') {
            return intdiv($this->monthsBetween($start, $windowEnd), $interval);
        }

        return intdiv(
            max(0, (int) $windowEnd->format('Y') - (int) $start->format('Y')),
            $interval
        );
    }

    private function firstPeriodAtOrAfterDate(
        DateTimeImmutable $start,
        string $frequency,
        int $interval,
        DateTimeImmutable $boundary
    ): int {
        if ($boundary->format('Y-m-d') <= $start->format('Y-m-d')) {
            return 0;
        }

        if ($frequency === 'DAILY') {
            return $this->ceilingDivision(
                $this->calendarDaysBetween($start, $boundary),
                $interval
            );
        }

        if ($frequency === 'WEEKLY') {
            $base = $this->weekStart($start);
            $days = $this->calendarDaysBetween($base, $boundary);
            $periodIndex = intdiv(intdiv($days, 7), $interval);
            $periodStart = $this->periodStart($start, $frequency, ['INTERVAL' => (string) $interval], $periodIndex);

            return $periodStart->format('Y-m-d') < $boundary->format('Y-m-d')
                ? $periodIndex + 1
                : $periodIndex;
        }

        if ($frequency === 'MONTHLY') {
            $periodIndex = intdiv($this->monthsBetween($start, $boundary), $interval);
            $periodStart = $this->periodStart($start, $frequency, ['INTERVAL' => (string) $interval], $periodIndex);

            return $periodStart->format('Y-m-d') < $boundary->format('Y-m-d')
                ? $periodIndex + 1
                : $periodIndex;
        }

        $periodIndex = intdiv(
            max(0, (int) $boundary->format('Y') - (int) $start->format('Y')),
            $interval
        );
        $periodStart = $this->periodStart($start, $frequency, ['INTERVAL' => (string) $interval], $periodIndex);

        return $periodStart->format('Y-m-d') < $boundary->format('Y-m-d')
            ? $periodIndex + 1
            : $periodIndex;
    }

    private function cycleLength(string $frequency, int $interval): int
    {
        $cycle = match ($frequency) {
            'DAILY' => self::GREGORIAN_CYCLE_DAYS,
            'WEEKLY' => self::GREGORIAN_CYCLE_WEEKS,
            'MONTHLY' => self::GREGORIAN_CYCLE_MONTHS,
            'YEARLY' => self::GREGORIAN_CYCLE_YEARS,
            default => throw new InvalidArgumentException('The recurrence frequency is unsupported.'),
        };

        return intdiv($cycle, $this->greatestCommonDivisor($interval, $cycle));
    }

    private function greatestCommonDivisor(int $first, int $second): int
    {
        while ($second !== 0) {
            [$first, $second] = [$second, $first % $second];
        }

        return $first;
    }

    private function weekStart(DateTimeImmutable $date): DateTimeImmutable
    {
        $weekdayOffset = (int) $date->format('N') - 1;

        return $date->setTime(0, 0, 0)->modify('-' . $weekdayOffset . ' days');
    }

    private function calendarDaysBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $fromDate = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $from->format('Y-m-d'),
            $this->utc
        );
        $toDate = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $to->format('Y-m-d'),
            $this->utc
        );

        if ($fromDate === false || $toDate === false) {
            throw new InvalidArgumentException('A local calendar date could not be compared.');
        }

        $difference = $fromDate->diff($toDate);

        if ($difference->invert === 1) {
            return -($difference->days ?? 0);
        }

        return $difference->days ?? 0;
    }

    private function monthsBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return max(
            0,
            ((int) $to->format('Y') - (int) $from->format('Y')) * 12
                + (int) $to->format('n')
                - (int) $from->format('n')
        );
    }

    private function ceilingDivision(int $value, int $divisor): int
    {
        if ($value <= 0) {
            return 0;
        }

        return intdiv($value, $divisor) + ($value % $divisor === 0 ? 0 : 1);
    }

    /**
     * @param array<string, string> $parts
     * @return array{value: DateTimeImmutable|string, date_only: bool, utc: bool}|null
     */
    private function parseUntil(?string $value, bool $allDay): ?array
    {
        if ($value === null) {
            return null;
        }

        if ($allDay) {
            return [
                'value' => $value,
                'date_only' => true,
                'utc' => false,
            ];
        }

        $isUtc = str_ends_with($value, 'Z');
        $timezone = $isUtc ? $this->utc : $this->timezone;
        $format = $isUtc ? '!Ymd\THis\Z' : '!Ymd\THis';
        $until = DateTimeImmutable::createFromFormat($format, $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $until === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        ) {
            throw new InvalidArgumentException('A validated RRULE UNTIL value could not be parsed.');
        }

        return [
            'value' => $until,
            'date_only' => false,
            'utc' => $isUtc,
        ];
    }

    /**
     * @param array{value: DateTimeImmutable|string, date_only: bool, utc: bool}|null $until
     */
    private function isWithinUntil(DateTimeImmutable $candidate, ?array $until, bool $allDay): bool
    {
        if ($until === null) {
            return true;
        }

        if ($allDay) {
            return $candidate->format('Ymd') <= (string) $until['value'];
        }

        $comparison = $until['utc'] ? $candidate->setTimezone($this->utc) : $candidate;

        return $comparison <= $until['value'];
    }

    /**
     * @param array{value: DateTimeImmutable|string, date_only: bool, utc: bool}|null $until
     */
    private function canReachWindow(
        DateTimeImmutable $start,
        ?array $until,
        OccurrenceWindow $window,
        bool $allDay
    ): bool {
        if ($start->format('Y-m-d') > $window->endLocal->format('Y-m-d')) {
            return false;
        }

        if ($until === null) {
            return true;
        }

        return $this->isWithinUntil($window->startLocal, $until, $allDay);
    }

    /**
     * @param array<string, DateTimeImmutable> $candidates
     */
    private function addCandidate(
        array &$candidates,
        DateTimeImmutable $candidate,
        OccurrenceWindow $window
    ): void {
        if ($window->contains($candidate)) {
            $candidates[$candidate->format('Y-m-d\TH:i')] = $candidate;
        }
    }

    /**
     * @param list<DateTimeImmutable> $starts
     * @return list<ExpandedOccurrence>
     */
    private function withEnds(
        array $starts,
        DateTimeImmutable $eventStart,
        ?DateTimeImmutable $eventEnd,
        bool $allDay
    ): array {
        if ($eventEnd === null) {
            return array_map(
                static fn (DateTimeImmutable $start): ExpandedOccurrence => new ExpandedOccurrence($start, null),
                $starts
            );
        }

        $dayOffset = $this->calendarDaysBetween($eventStart, $eventEnd);
        $endOffset = $dayOffset + ($allDay ? 1 : 0);
        $endHour = $allDay ? 0 : (int) $eventEnd->format('H');
        $endMinute = $allDay ? 0 : (int) $eventEnd->format('i');

        return array_map(
            function (DateTimeImmutable $start) use ($endOffset, $endHour, $endMinute): ExpandedOccurrence {
                $end = $start->setTime(0, 0, 0);

                if ($endOffset > 0) {
                    $end = $end->modify('+' . $endOffset . ' days');
                }

                $end = $end->setTime($endHour, $endMinute);

                return new ExpandedOccurrence($start, $end);
            },
            $starts
        );
    }

    private function parseLocalDateTime(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $this->timezone);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            $date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d\TH:i') !== $value
        ) {
            throw new InvalidArgumentException('A validated event date could not be parsed.');
        }

        return $date;
    }

    /**
     * @param list<string> $values
     * @return list<int>
     */
    private function integerList(string $values): array
    {
        return array_values(array_unique(array_map('intval', explode(',', $values))));
    }
}
