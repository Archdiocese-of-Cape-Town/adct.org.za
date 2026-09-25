<?php

namespace ADCT\ParishIntake\Core\Parsing\Stages;

use ADCT\ParishIntake\Core\Events\RRulePresetMapper;
use ADCT\ParishIntake\Core\Events\RRuleValidator;
use ADCT\ParishIntake\Core\Parsing\Contracts\StageInterface;
use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\ParseContext;
use ADCT\ParishIntake\Core\Parsing\ParseResult;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Support\SystemClock;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;

final class RecurrenceDetectionStage implements StageInterface
{
    private const LOCAL_TIMEZONE = 'Africa/Johannesburg';
    private const WEEKDAY_PATTERN = '(?:Monday|Mon|Tuesday|Tues|Tue|Wednesday|Wed|Thursday|Thurs|Thur|Thu|Friday|Fri|Saturday|Sat|Sunday|Sun)';
    private const MONTH_PATTERN = '(?:January|Jan|February|Feb|March|Mar|April|Apr|May|June|Jun|July|Jul|August|Aug|September|Sept|Sep|October|Oct|November|Nov|December|Dec)';

    private const WEEKDAY_MAP = [
        'monday' => 'MO',
        'mon' => 'MO',
        'tuesday' => 'TU',
        'tues' => 'TU',
        'tue' => 'TU',
        'wednesday' => 'WE',
        'wed' => 'WE',
        'thursday' => 'TH',
        'thurs' => 'TH',
        'thur' => 'TH',
        'thu' => 'TH',
        'friday' => 'FR',
        'fri' => 'FR',
        'saturday' => 'SA',
        'sat' => 'SA',
        'sunday' => 'SU',
        'sun' => 'SU',
    ];

    private const POSITION_MAP = [
        'first' => 1,
        'second' => 2,
        'third' => 3,
        'fourth' => 4,
        'last' => -1,
        '1st' => 1,
        '2nd' => 2,
        '3rd' => 3,
        '4th' => 4,
    ];

    private const RFC_WEEKDAY_NUMBERS = [
        'MO' => 1,
        'TU' => 2,
        'WE' => 3,
        'TH' => 4,
        'FR' => 5,
        'SA' => 6,
        'SU' => 7,
    ];

    private ClockInterface $clock;
    private RRuleValidator $validator;
    private RRulePresetMapper $presetMapper;

    public function __construct(
        ?ClockInterface $clock = null,
        ?RRuleValidator $validator = null,
        ?RRulePresetMapper $presetMapper = null
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->validator = $validator ?? new RRuleValidator();
        $this->presetMapper = $presetMapper ?? new RRulePresetMapper($this->validator);
    }

    public function process(Message $message, ParseResult $result, ParseContext $context): ParseResult
    {
        $text = $result->getNormalizedText();

        if (
            $result->getClassification() === 'general_notice'
            && preg_match('/\b(?:newsletter|bulletin)\b/i', $text) === 1
        ) {
            return $result;
        }

        $seasonal = $this->findSeasonalRecurrence($text);

        if ($seasonal !== null) {
            $result->setRecurrence([
                'frequency' => 'daily',
                'interval' => 1,
                'text' => $seasonal['text'],
                'ambiguous' => true,
            ]);
            $result->setClassification('recurring_event');
            $result->addStrategy('recurrence_detection');
            $result->setNeedsReprocess(true);
            $result->addNote(
                'recurrence_ambiguous_season: Lent/Advent dates are not inferred; confirm the recurrence dates.'
            );
            $result->addNote('Recurring wording detected but needs human confirmation.');

            return $result;
        }

        $definition = $this->findRecurrence($text);

        if ($definition === null) {
            return $result;
        }

        $anchor = $this->dateFromField($result->getField('event_date'));
        $anchorInferred = $anchor === null;

        if ($anchorInferred) {
            $anchor = $this->firstOccurrenceOnOrAfter(
                $this->referenceDate($message, $context),
                $definition['parts']
            );
            $result->setField('event_date', $anchor->format('Y-m-d'));
            $result->addNote('recurrence_anchor_inferred: ' . $anchor->format('Y-m-d'));
        }

        $parts = $definition['parts'];
        $until = $this->findUntilDate($text);
        $untilDate = null;

        if ($until !== null) {
            $untilDate = $this->resolveUntilDate($until, $anchor);

            if ($untilDate === null) {
                $result->setRecurrence([
                    'frequency' => strtolower($parts['FREQ']),
                    'interval' => (int) ($parts['INTERVAL'] ?? 1),
                    'text' => trim($definition['text'] . ' ' . $until['text']),
                    'ambiguous' => true,
                    'anchor_inferred' => $anchorInferred,
                ]);
                $result->setClassification('recurring_event');
                $result->addStrategy('recurrence_detection');
                $result->setNeedsReprocess(true);
                $result->addError('The recurrence end date could not be resolved; confirm it before publication.');
                $result->addNote('recurrence_until_invalid: confirm the recurrence end date.');

                return $result;
            }

            if ($until['year'] === null) {
                $result->addNote(sprintf(
                    'recurrence_until_year_inferred: resolved %s to %s on or after %s.',
                    $until['text'],
                    $untilDate->format('Y-m-d'),
                    $anchor->format('Y-m-d')
                ));
            }

            $allDay = $this->isAllDay($result);
            $parts['UNTIL'] = $this->untilValue($untilDate, $allDay);
            $definition['text'] = trim($definition['text'] . ' ' . $until['text']);
        }

        $rule = $this->buildRule($parts);
        $allDay = $this->isAllDay($result);
        $validation = $this->validator->validate($rule, $allDay);

        if (! $validation->isValid() || $validation->normalizedRule === null) {
            $result->setNeedsReprocess(true);
            $result->addError(
                'The detected recurrence could not be represented by a supported RRULE: '
                . implode(' ', $validation->errors)
            );

            return $result;
        }

        $recurrence = $this->recurrenceMetadata(
            $definition,
            $parts,
            $validation->normalizedRule,
            $anchorInferred,
            $untilDate
        );
        $result->setRecurrence($recurrence);
        $result->setClassification('recurring_event');
        $result->addStrategy('recurrence_detection');

        if (! empty($recurrence['ambiguous'])) {
            $result->setNeedsReprocess(true);
            $result->addNote('Recurring wording detected but needs human confirmation.');
        }

        return $result;
    }

    /**
     * @return array{text: string}|null
     */
    private function findSeasonalRecurrence(string $text): ?array
    {
        if (! preg_match(
            '/\b(?:daily|every\s+day)\s+(?:during|in)\s+(?<season>lent|advent)\b/i',
            $text,
            $matches
        )) {
            return null;
        }

        return ['text' => $matches[0]];
    }

    /**
     * @return array{
     *     parts: array<string, string>,
     *     text: string,
     *     ambiguous?: bool
     * }|null
     */
    private function findRecurrence(string $text): ?array
    {
        if (preg_match('/\bnovena\b[^\r\n]{0,80}?\bfor\s+(?<count>9|nine)\s+days?\b/iu', $text, $matches)) {
            return [
                'parts' => ['FREQ' => 'DAILY', 'COUNT' => (string) $this->dayCount($matches['count'])],
                'text' => $matches[0],
            ];
        }

        if (preg_match('/\bfor\s+(?<count>9|nine)\s+days?\b[^\r\n]{0,80}?\bnovena\b/iu', $text, $matches)) {
            return [
                'parts' => ['FREQ' => 'DAILY', 'COUNT' => (string) $this->dayCount($matches['count'])],
                'text' => $matches[0],
            ];
        }

        if (preg_match('/\bevery\s+second\s+week\b/i', $text, $matches)) {
            return [
                'parts' => ['FREQ' => 'WEEKLY', 'INTERVAL' => '2'],
                'text' => $matches[0],
            ];
        }

        if (preg_match('/\bfortnightly\b/i', $text, $matches)) {
            return [
                'parts' => ['FREQ' => 'WEEKLY', 'INTERVAL' => '2'],
                'text' => $matches[0],
            ];
        }

        $ordinal = '(?:first|second|third|fourth|last|1st|2nd|3rd|4th)';
        $ordinalList = $ordinal . '(?:\s*(?:,|and|&)\s*' . $ordinal . ')+';

        if (preg_match(
            '/\b(?<ordinals>' . $ordinalList . ')\s+(?<weekday>' . self::WEEKDAY_PATTERN . ')\b/i',
            $text,
            $matches
        )) {
            $weekday = $this->weekdayCode($matches['weekday']);
            preg_match_all('/' . $ordinal . '/i', $matches['ordinals'], $ordinalMatches);
            $byDays = [];

            foreach ($ordinalMatches[0] as $ordinalText) {
                $position = self::POSITION_MAP[strtolower($ordinalText)] ?? null;

                if ($position !== null) {
                    $byDays[] = $position . $weekday;
                }
            }

            $byDays = array_values(array_unique($byDays));

            if ($byDays !== []) {
                return [
                    'parts' => ['FREQ' => 'MONTHLY', 'BYDAY' => implode(',', $byDays)],
                    'text' => $matches[0],
                ];
            }
        }

        if (preg_match(
            '/\b(?<phrase>(?:every\s+)?last\s+(?<weekday>' . self::WEEKDAY_PATTERN
            . ')(?:\s+of\s+the\s+month)?)\b/i',
            $text,
            $matches
        ) && (stripos($matches['phrase'], 'every ') === 0 || stripos($matches['phrase'], 'of the month') !== false)) {
            $weekday = $this->weekdayCode($matches['weekday']);

            return [
                'parts' => ['FREQ' => 'MONTHLY', 'BYDAY' => '-1' . $weekday],
                'text' => $matches['phrase'],
            ];
        }

        if (preg_match(
            '/\bevery\s+(?<ordinal>first|second|third|fourth|last)\s+'
            . '(?<weekday>' . self::WEEKDAY_PATTERN . ')(?:\s+(?:of\s+the\s+month|\(of\s+the\s+month\)))?/i',
            $text,
            $matches
        )) {
            $position = self::POSITION_MAP[strtolower($matches['ordinal'])];
            $weekday = $this->weekdayCode($matches['weekday']);

            return [
                'parts' => ['FREQ' => 'MONTHLY', 'BYDAY' => $position . $weekday],
                'text' => $matches[0],
            ];
        }

        if (preg_match(
            '/\b(?:(?:every\s+month)|monthly)\s+on\s+(?:the\s+)?'
            . '(?<day>\d{1,2})(?:st|nd|rd|th)?\b/i',
            $text,
            $matches
        )) {
            $day = (int) $matches['day'];

            if ($day >= 1 && $day <= 31) {
                return [
                    'parts' => ['FREQ' => 'MONTHLY', 'BYMONTHDAY' => (string) $day],
                    'text' => $matches[0],
                ];
            }

            return null;
        }

        if (preg_match('/\b(?:weekdays|every\s+weekday)\b/i', $text, $matches)) {
            return [
                'parts' => ['FREQ' => 'WEEKLY', 'BYDAY' => 'MO,TU,WE,TH,FR'],
                'text' => $matches[0],
            ];
        }

        $weekdayToken = self::WEEKDAY_PATTERN . '\b';
        $weekdayList = $weekdayToken
            . '(?:\s*(?:,|and|&)\s*' . $weekdayToken . ')*';
        $weekdayListPatterns = [
            '/\bweekly\s+on\s+(?<weekdays>' . $weekdayList . ')/i',
            '/\bevery\s+(?<weekdays>' . $weekdayList . ')/i',
        ];

        foreach ($weekdayListPatterns as $pattern) {
            if (! preg_match($pattern, $text, $matches)) {
                continue;
            }

            $byDays = $this->weekdayCodes($matches['weekdays']);

            if ($byDays !== []) {
                return [
                    'parts' => ['FREQ' => 'WEEKLY', 'BYDAY' => implode(',', $byDays)],
                    'text' => $matches[0],
                ];
            }
        }

        if (preg_match('/\b(?:every\s+month|monthly)\b/i', $text, $matches)) {
            return [
                'parts' => ['FREQ' => 'MONTHLY'],
                'text' => $matches[0],
                'ambiguous' => true,
            ];
        }

        return null;
    }

    private function findUntilDate(string $text): ?array
    {
        $pattern = '~\buntil\s+(?:(?:' . self::WEEKDAY_PATTERN . ')\.?\s+)?'
            . '(?<day>\d{1,2})(?:st|nd|rd|th)?\s+(?<month>' . self::MONTH_PATTERN . ')'
            . '\.?(?:,?\s+(?<year>\d{4}|\d{2}))?\b~iu';

        if (! preg_match($pattern, $text, $matches, PREG_UNMATCHED_AS_NULL)) {
            return null;
        }

        return [
            'day' => (int) $matches['day'],
            'month' => $this->monthNumber($matches['month']),
            'year' => $matches['year'] === null ? null : $this->normalizeYear($matches['year']),
            'text' => $matches[0],
        ];
    }

    private function resolveUntilDate(array $until, DateTimeImmutable $anchor): ?DateTimeImmutable
    {
        if ($until['month'] === null) {
            return null;
        }

        if ($until['year'] !== null) {
            return $this->makeDate($until['year'], $until['month'], $until['day']);
        }

        $firstYear = (int) $anchor->format('Y');

        for ($year = $firstYear; $year <= $firstYear + 8; ++$year) {
            $date = $this->makeDate($year, $until['month'], $until['day']);

            if ($date !== null && $date >= $anchor) {
                return $date;
            }
        }

        return null;
    }

    private function firstOccurrenceOnOrAfter(DateTimeImmutable $referenceDate, array $parts): DateTimeImmutable
    {
        $frequency = $parts['FREQ'];
        $referenceDate = $referenceDate
            ->setTimezone(new DateTimeZone(self::LOCAL_TIMEZONE))
            ->setTime(0, 0);

        if ($frequency === 'DAILY' || $frequency === 'WEEKLY' && ! isset($parts['BYDAY'])) {
            return $referenceDate;
        }

        if ($frequency === 'WEEKLY') {
            $days = array_map('trim', explode(',', $parts['BYDAY']));

            for ($offset = 0; $offset < 7; ++$offset) {
                $candidate = $referenceDate->modify('+' . $offset . ' days');

                if (in_array($this->weekdayCodeFromNumber((int) $candidate->format('N')), $days, true)) {
                    return $candidate;
                }
            }

            return $referenceDate;
        }

        if ($frequency === 'MONTHLY' && isset($parts['BYMONTHDAY'])) {
            $day = (int) $parts['BYMONTHDAY'];
            $monthStart = $referenceDate->modify('first day of this month');

            for ($offset = 0; $offset < 120; ++$offset) {
                $month = $monthStart->modify('+' . $offset . ' months');
                $candidate = $this->makeDate(
                    (int) $month->format('Y'),
                    (int) $month->format('n'),
                    $day
                );

                if ($candidate !== null && $candidate >= $referenceDate) {
                    return $candidate;
                }
            }

            return $referenceDate;
        }

        if ($frequency === 'MONTHLY' && isset($parts['BYDAY'])) {
            $monthStart = $referenceDate->modify('first day of this month');

            for ($offset = 0; $offset < 120; ++$offset) {
                $month = $monthStart->modify('+' . $offset . ' months');
                $candidates = [];

                foreach (explode(',', $parts['BYDAY']) as $byDay) {
                    $candidate = $this->monthlyByDayOccurrence($month, trim($byDay));

                    if ($candidate !== null && $candidate >= $referenceDate) {
                        $candidates[] = $candidate;
                    }
                }

                if ($candidates !== []) {
                    usort($candidates, static fn (DateTimeImmutable $left, DateTimeImmutable $right): int => $left <=> $right);

                    return $candidates[0];
                }
            }
        }

        return $referenceDate;
    }

    private function monthlyByDayOccurrence(DateTimeImmutable $month, string $byDay): ?DateTimeImmutable
    {
        if (preg_match('/^(-?[1-4])?(MO|TU|WE|TH|FR|SA|SU)$/', $byDay, $matches) !== 1) {
            return null;
        }

        $ordinal = ($matches[1] ?? '') === '' ? 1 : (int) $matches[1];
        $weekday = self::RFC_WEEKDAY_NUMBERS[$matches[2]];
        $monthStart = $month->modify('first day of this month');
        $year = (int) $monthStart->format('Y');
        $monthNumber = (int) $monthStart->format('n');

        if ($ordinal > 0) {
            $firstWeekday = (int) $monthStart->format('N');
            $offset = ($weekday - $firstWeekday + 7) % 7 + (($ordinal - 1) * 7);
            $candidate = $monthStart->modify('+' . $offset . ' days');

            return (int) $candidate->format('n') === $monthNumber ? $candidate : null;
        }

        $monthEnd = $monthStart->modify('last day of this month');
        $weekdayOffset = ((int) $monthEnd->format('N') - $weekday + 7) % 7;
        $ordinalOffset = (abs($ordinal) - 1) * 7;
        $candidate = $monthEnd->modify('-' . ($weekdayOffset + $ordinalOffset) . ' days');

        return (int) $candidate->format('Y') === $year
            && (int) $candidate->format('n') === $monthNumber
            ? $candidate
            : null;
    }

    private function buildRule(array $parts): string
    {
        $baseRule = $this->simplePresetRule($parts);
        $components = $baseRule === null ? [] : explode(';', $baseRule);
        $seen = [];

        foreach ($components as $component) {
            [$name] = explode('=', $component, 2);
            $seen[$name] = true;
        }

        foreach ($parts as $name => $value) {
            if (! isset($seen[$name])) {
                $components[] = $name . '=' . $value;
            }
        }

        $rule = $this->presetMapper->toRRule(
            'custom',
            customRule: implode(';', $components)
        );

        if ($rule === null) {
            throw new LogicException('A recurrence rule could not be serialized.');
        }

        return $rule;
    }

    private function simplePresetRule(array $parts): ?string
    {
        if (($parts['FREQ'] ?? '') === 'WEEKLY' && isset($parts['BYDAY'])
            && preg_match('/^(MO|TU|WE|TH|FR|SA|SU)$/', $parts['BYDAY']) === 1
        ) {
            return $this->presetMapper->toRRule('weekly', $parts['BYDAY']);
        }

        if (($parts['FREQ'] ?? '') === 'MONTHLY' && isset($parts['BYDAY'])
            && preg_match('/^(-?[1-4])(MO|TU|WE|TH|FR|SA|SU)$/', $parts['BYDAY'], $matches) === 1
        ) {
            return $this->presetMapper->toRRule('monthly_ordinal', $matches[2], $matches[1]);
        }

        if (($parts['FREQ'] ?? '') === 'MONTHLY' && isset($parts['BYMONTHDAY'])) {
            return $this->presetMapper->toRRule('monthly_day', '', '', $parts['BYMONTHDAY']);
        }

        return null;
    }

    private function recurrenceMetadata(
        array $definition,
        array $parts,
        string $rule,
        bool $anchorInferred,
        ?DateTimeImmutable $untilDate
    ): array {
        $recurrence = [
            'frequency' => strtolower($parts['FREQ']),
            'interval' => (int) ($parts['INTERVAL'] ?? 1),
            'text' => $definition['text'],
            'rrule' => $rule,
        ];

        if (isset($parts['BYDAY'])) {
            if (preg_match('/^(-?[1-4])(MO|TU|WE|TH|FR|SA|SU)$/', $parts['BYDAY'], $matches) === 1) {
                $recurrence['by_day'] = $matches[2];
                $recurrence['by_set_position'] = (int) $matches[1];
            } else {
                $recurrence['by_day'] = $parts['BYDAY'];
            }
        }

        if (isset($parts['BYMONTHDAY'])) {
            $recurrence['by_month_day'] = (int) $parts['BYMONTHDAY'];
        }

        if (isset($parts['COUNT'])) {
            $recurrence['count'] = (int) $parts['COUNT'];
        }

        if ($untilDate !== null) {
            $recurrence['until'] = $untilDate->format('Y-m-d');
        }

        if (! empty($definition['ambiguous'])) {
            $recurrence['ambiguous'] = true;
        }

        if ($anchorInferred) {
            $recurrence['anchor_inferred'] = true;
        }

        return $recurrence;
    }

    private function untilValue(DateTimeImmutable $date, bool $allDay): string
    {
        if ($allDay) {
            return $date->format('Ymd');
        }

        return $date
            ->setTime(23, 59, 59)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Ymd\THis\Z');
    }

    private function isAllDay(ParseResult $result): bool
    {
        return $result->getField('all_day') === true;
    }

    private function referenceDate(Message $message, ParseContext $context): DateTimeImmutable
    {
        $referenceDate = $context->getRuntimeValue('reference_date');

        if (! $referenceDate instanceof DateTimeImmutable) {
            $referenceDate = $message->getReceivedAt() ?? $this->clock->now();
        }

        return $referenceDate
            ->setTimezone(new DateTimeZone(self::LOCAL_TIMEZONE))
            ->setTime(0, 0);
    }

    private function dateFromField($value): ?DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year)
            ? new DateTimeImmutable($value, new DateTimeZone(self::LOCAL_TIMEZONE))
            : null;
    }

    private function weekdayCode(string $weekday): string
    {
        return self::WEEKDAY_MAP[strtolower(rtrim($weekday, '.'))];
    }

    private function weekdayCodes(string $text): array
    {
        preg_match_all('/\b' . self::WEEKDAY_PATTERN . '\b/i', $text, $matches);
        $codes = [];

        foreach ($matches[0] as $weekday) {
            $code = $this->weekdayCode($weekday);

            if (! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    private function weekdayCodeFromNumber(int $weekday): string
    {
        $codes = [
            1 => 'MO',
            2 => 'TU',
            3 => 'WE',
            4 => 'TH',
            5 => 'FR',
            6 => 'SA',
            7 => 'SU',
        ];

        return $codes[$weekday];
    }

    private function monthNumber(string $month): ?int
    {
        $months = [
            'jan' => 1,
            'feb' => 2,
            'mar' => 3,
            'apr' => 4,
            'may' => 5,
            'jun' => 6,
            'jul' => 7,
            'aug' => 8,
            'sep' => 9,
            'oct' => 10,
            'nov' => 11,
            'dec' => 12,
        ];

        return $months[strtolower(substr($month, 0, 3))] ?? null;
    }

    private function normalizeYear(string $year): int
    {
        $year = (int) $year;

        if ($year < 100) {
            return $year <= 69 ? 2000 + $year : 1900 + $year;
        }

        return $year;
    }

    private function makeDate(int $year, int $month, int $day): ?DateTimeImmutable
    {
        if ($year < 1 || ! checkdate($month, $day, $year)) {
            return null;
        }

        return new DateTimeImmutable(
            sprintf('%04d-%02d-%02d', $year, $month, $day),
            new DateTimeZone(self::LOCAL_TIMEZONE)
        );
    }

    private function dayCount(string $count): int
    {
        return strtolower($count) === 'nine' ? 9 : (int) $count;
    }
}
