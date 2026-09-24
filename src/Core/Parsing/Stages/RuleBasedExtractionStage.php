<?php

namespace ADCT\ParishIntake\Core\Parsing\Stages;

use ADCT\ParishIntake\Core\Parsing\Contracts\StageInterface;
use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\ParseContext;
use ADCT\ParishIntake\Core\Parsing\ParseResult;
use ADCT\ParishIntake\Core\Ports\ClockInterface;
use ADCT\ParishIntake\Core\Support\SystemClock;
use ADCT\ParishIntake\Core\Support\Text;
use DateTimeImmutable;
use DateTimeZone;

final class RuleBasedExtractionStage implements StageInterface
{
    private const LOCAL_TIMEZONE = 'Africa/Johannesburg';
    private const WEEKDAY_PATTERN = '(?:Saturday|Sat|Sunday|Sun|Monday|Mon|Tuesday|Tues|Tue|Wednesday|Wed|Thursday|Thurs|Thur|Thu|Friday|Fri)';
    private const MONTH_PATTERN = '(?:January|Jan|February|Feb|March|Mar|April|Apr|May|June|Jun|July|Jul|August|Aug|September|Sept|Sep|October|Oct|November|Nov|December|Dec)';
    private const EVENT_KEYWORDS = ['mass', 'healing', 'retreat', 'novena', 'pilgrimage', 'fundraiser', 'conference', 'celebration', 'vigil', 'feast'];
    private const NOTICE_KEYWORDS = ['notice', 'announcement', 'newsletter', 'update', 'bulletin'];

    private ClockInterface $clock;

    public function __construct(?ClockInterface $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    public function process(Message $message, ParseResult $result, ParseContext $context): ParseResult
    {
        $text = $result->getNormalizedText();
        $lower = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);

        $bulletinRange = $this->findBulletinDateRange($text);
        $dateText = $text;

        if ($bulletinRange !== null) {
            $dateText = substr_replace(
                $text,
                ' ',
                $bulletinRange['offset'],
                strlen($bulletinRange['match'])
            );
        }

        $referenceDate = $bulletinRange['date'] ?? $this->referenceDate($message);
        $date = $this->extractDate($dateText, $referenceDate);
        $times = $this->extractTimes($text);

        $classification = $this->classify($lower, $date !== null, $times !== null);
        $result->setClassification($classification);
        $result->setField('title', $this->extractTitle($message, $text));
        $result->setField('parish_name', $this->extractParishName($text, $message->getSenderName()));
        $rangeEndBeforeStart = false;

        if ($date !== null) {
            $result->setField('event_date', $date['date']);
            $result->setField('event_end_date', $date['end_date']);

            if ($date['weekday_mismatch']) {
                $result->markDateWeekdayMismatch();
                $result->addNote('The stated weekday does not match the parsed event date; verify it.');
            }

            if ($date['next_weekday_ambiguous'] !== null) {
                $result->markNextWeekdayAmbiguous();
                $result->addNote(sprintf(
                    'The phrase "next %s" is ambiguous between this week and next week; the first future occurrence was selected.',
                    $date['next_weekday_ambiguous']
                ));
            }

            $rangeEndBeforeStart = $date['end_before_start'];
        }

        if ($times !== null) {
            $result->setField('event_time', $times['start']);
            $result->setField('event_end_time', $times['end']);
            $rangeEndBeforeStart = $rangeEndBeforeStart || $times['end_before_start'];
        }

        if ($rangeEndBeforeStart) {
            $result->markRangeEndBeforeStart();
            $result->addNote('The stated event end is before its start; verify the range.');
        }

        $result->setField('venue', $this->extractVenue($text));
        $result->setField('contact', $this->extractContact($text, $message->getSenderEmail()));
        $result->setField('description', $message->getBody());
        $result->setField('attachment_names', array_map(static fn ($attachment) => $attachment->getName(), $message->getAttachments()));
        $result->addStrategy('rule_based_extraction');

        return $result;
    }

    private function classify(string $lower, bool $hasDate, bool $hasTime): string
    {
        if ($this->containsRecurringHint($lower)) {
            return 'recurring_event';
        }

        foreach (self::EVENT_KEYWORDS as $keyword) {
            if (strpos($lower, $keyword) !== false) {
                return 'event';
            }
        }

        if ($hasDate || $hasTime) {
            return 'event';
        }

        foreach (self::NOTICE_KEYWORDS as $keyword) {
            if (strpos($lower, $keyword) !== false) {
                return 'general_notice';
            }
        }

        return 'low_confidence';
    }

    private function containsRecurringHint(string $lower): bool
    {
        return (bool) preg_match('/\b(?:every|weekly|monthly|fortnightly|first\s+friday|last\s+sunday)\b/i', $lower);
    }

    private function extractTitle(Message $message, string $text): ?string
    {
        $subject = trim(preg_replace('/^(?:re|fwd):\s*/i', '', $message->getSubject()) ?? $message->getSubject());

        if ($subject !== '') {
            return $subject;
        }

        return Text::firstMeaningfulLine($text);
    }

    private function extractParishName(string $text, string $senderName): ?string
    {
        $patterns = [
            '/parish\s*[:\-]\s*([^\n]+)/i',
            '/\b((?:St\.?|Saint|Our Lady of|Church of) [A-Z][A-Za-z\'\- ]+(?:Parish|Church)?)\b/u',
            '/\b([A-Z][A-Za-z\'\- ]+ Parish)\b/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                return trim($matches[1]);
            }
        }

        if (stripos($senderName, 'parish') !== false || stripos($senderName, 'church') !== false) {
            return trim($senderName);
        }

        return null;
    }

    /**
     * @return array{date: string, end_date: ?string, weekday_mismatch: bool, end_before_start: bool, next_weekday_ambiguous: ?string}|null
     */
    private function extractDate(string $text, DateTimeImmutable $referenceDate): ?array
    {
        $weekdayPrefix = '(?:(?<weekday>' . self::WEEKDAY_PATTERN . ')\.?\s+)?';
        $patterns = [
            'iso' => '~\b' . $weekdayPrefix . '(?<year>\d{4})-(?<month>\d{1,2})-(?<day>\d{1,2})\b~i',
            'numeric' => '~\b' . $weekdayPrefix . '(?<day>\d{1,2})[./-](?<month>\d{1,2})[./-](?<year>\d{4}|\d{2})\b~i',
            'day_month' => '~\b' . $weekdayPrefix . '(?<day>\d{1,2})(?:st|nd|rd|th)?(?:(?:\s*[-–]\s*|\s+to\s+)(?<end_day>\d{1,2})(?:st|nd|rd|th)?)?\s+(?<month>' . self::MONTH_PATTERN . ')\.?(?:,?\s+(?<year>\d{4}|\d{2}))?\b~iu',
            'month_day' => '~\b' . $weekdayPrefix . '(?<month>' . self::MONTH_PATTERN . ')\.?\s+(?<day>\d{1,2})(?:st|nd|rd|th)?(?:,?\s+(?<year>\d{4}|\d{2}))?\b~i',
        ];
        $candidates = [];

        foreach ($patterns as $type => $pattern) {
            preg_match_all(
                $pattern,
                $text,
                $matches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL
            );

            foreach ($matches as $match) {
                $candidates[] = [
                    'type' => $type,
                    'offset' => $match[0][1],
                    'match' => $match,
                ];
            }
        }

        $relativePatterns = [
            'this_weekday' => '~\bthis\s+(?<weekday>' . self::WEEKDAY_PATTERN . ')\b~i',
            'next_weekday' => '~\bnext\s+(?<weekday>' . self::WEEKDAY_PATTERN . ')\b~i',
            'tomorrow' => '~\btomorrow\b~i',
            'tonight' => '~\btonight\b~i',
        ];

        foreach ($relativePatterns as $type => $pattern) {
            preg_match_all(
                $pattern,
                $text,
                $matches,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL
            );

            foreach ($matches as $match) {
                $candidates[] = [
                    'type' => $type,
                    'offset' => $match[0][1],
                    'match' => $match,
                ];
            }
        }

        usort($candidates, static fn (array $left, array $right): int => $left['offset'] <=> $right['offset']);
        $referenceDate = $referenceDate
            ->setTimezone(new DateTimeZone(self::LOCAL_TIMEZONE))
            ->setTime(0, 0);

        foreach ($candidates as $candidate) {
            $match = $candidate['match'];
            $endDate = null;
            $endBeforeStart = false;
            $ambiguousWeekday = null;

            if ($candidate['type'] === 'this_weekday' || $candidate['type'] === 'next_weekday') {
                $weekdayText = $this->capturedValue($match, 'weekday');
                $targetWeekday = $weekdayText === null ? null : $this->weekdayNumber($weekdayText);
                $date = $this->resolveWeekday(
                    $referenceDate,
                    $weekdayText,
                    $candidate['type'] === 'next_weekday'
                );
                $weekdayMismatch = false;

                if (
                    $candidate['type'] === 'next_weekday'
                    && $targetWeekday !== null
                    && $targetWeekday > (int) $referenceDate->format('N')
                ) {
                    $ambiguousWeekday = $weekdayText;
                }
            } elseif ($candidate['type'] === 'tomorrow') {
                $date = $referenceDate->modify('+1 day');
                $weekdayMismatch = false;
            } elseif ($candidate['type'] === 'tonight') {
                $date = $referenceDate;
                $weekdayMismatch = false;
            } else {
                $monthText = $this->capturedValue($match, 'month');
                $month = $monthText !== null && ctype_digit($monthText)
                    ? (int) $monthText
                    : $this->monthNumber($monthText ?? '');

                if ($month === null) {
                    continue;
                }

                $day = (int) $this->capturedValue($match, 'day');
                $yearText = $this->capturedValue($match, 'year');
                $date = $yearText === null
                    ? $this->nextMonthDay($day, $month, $referenceDate)
                    : $this->makeDate($this->normalizeYear($yearText), $month, $day);

                if ($date === null) {
                    continue;
                }

                $endDayText = $this->capturedValue($match, 'end_day');

                if ($endDayText !== null) {
                    $endDate = $this->makeDate((int) $date->format('Y'), $month, (int) $endDayText);
                    $endBeforeStart = $endDate !== null && $endDate < $date;
                }

                $weekdayText = $this->capturedValue($match, 'weekday');
                $weekdayMismatch = $weekdayText !== null
                    && $this->weekdayNumber($weekdayText) !== (int) $date->format('N');
            }

            if ($date === null) {
                continue;
            }

            return [
                'date' => $date->format('Y-m-d'),
                'end_date' => $endDate?->format('Y-m-d'),
                'weekday_mismatch' => $weekdayMismatch,
                'end_before_start' => $endBeforeStart,
                'next_weekday_ambiguous' => $ambiguousWeekday,
            ];
        }

        return null;
    }

    /**
     * @return array{start: string, end: ?string, end_before_start: bool}|null
     */
    private function extractTimes(string $text): ?array
    {
        $timeToken = '(?:\d{1,2}(?:[:.]\d{2})?\s*(?:am|pm)|\d{1,2}:\d{2})';
        $rangePattern = '~\b(?:from\s+)?(?<start>' . $timeToken . ')\s+to\s+(?<end>' . $timeToken . ')\b~i';

        preg_match_all($rangePattern, $text, $rangeMatches, PREG_SET_ORDER);

        foreach ($rangeMatches as $match) {
            $start = $this->normalizeTime($match['start']);
            $end = $this->normalizeTime($match['end']);

            if ($start === null || $end === null) {
                continue;
            }

            return [
                'start' => $start,
                'end' => $end,
                'end_before_start' => $end < $start,
            ];
        }

        $start = $this->extractFirstTime($text);

        if ($start === null) {
            return null;
        }

        return [
            'start' => $start,
            'end' => null,
            'end_before_start' => false,
        ];
    }

    private function extractFirstTime(string $text): ?string
    {
        preg_match_all(
            '~\b(?:\d{1,2}(?:[:.]\d{2})?\s*(?:am|pm)|\d{1,2}:\d{2})\b~i',
            $text,
            $matches
        );

        foreach ($matches[0] as $match) {
            $time = $this->normalizeTime($match);

            if ($time !== null) {
                return $time;
            }
        }

        return null;
    }

    private function normalizeTime(string $time): ?string
    {
        if (! preg_match(
            '/^(?<hour>\d{1,2})(?:(?:[:.](?<minute>\d{2})))?\s*(?<meridiem>am|pm)?$/i',
            trim($time),
            $matches,
            PREG_UNMATCHED_AS_NULL
        )) {
            return null;
        }

        $hour = (int) $matches['hour'];
        $minute = (int) ($matches['minute'] ?? 0);
        $meridiem = strtolower($matches['meridiem'] ?? '');

        if ($minute > 59) {
            return null;
        }

        if ($meridiem !== '') {
            if ($hour < 1 || $hour > 12) {
                return null;
            }

            if ($meridiem === 'pm' && $hour < 12) {
                $hour += 12;
            } elseif ($meridiem === 'am' && $hour === 12) {
                $hour = 0;
            }
        } elseif ($hour > 23) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    /**
     * @return array{date: DateTimeImmutable, match: string, offset: int}|null
     */
    private function findBulletinDateRange(string $text): ?array
    {
        $header = substr($text, 0, 512);
        $pattern = '~\b(?:bulletin|newsletter)\b[\s\S]{0,120}?\b(?<start_day>\d{1,2})(?:st|nd|rd|th)?\s+(?<start_month>' . self::MONTH_PATTERN . ')\.?\s+(?:to|[-–])\s*(?<end_day>\d{1,2})(?:st|nd|rd|th)?\s+(?<end_month>' . self::MONTH_PATTERN . ')\.?,?\s+(?<year>\d{4})\b~iu';

        if (! preg_match($pattern, $header, $matches, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL)) {
            return null;
        }

        $startMonth = $this->monthNumber($matches['start_month'][0]);
        $endMonth = $this->monthNumber($matches['end_month'][0]);
        $year = (int) $matches['year'][0];

        if ($startMonth === null || $endMonth === null) {
            return null;
        }

        if ($startMonth > $endMonth) {
            --$year;
        }

        $date = $this->makeDate($year, $startMonth, (int) $matches['start_day'][0]);

        if ($date === null) {
            return null;
        }

        return [
            'date' => $date,
            'match' => $matches[0][0],
            'offset' => $matches[0][1],
        ];
    }

    private function referenceDate(Message $message): DateTimeImmutable
    {
        $referenceDate = $message->getReceivedAt() ?? $this->clock->now();

        return $referenceDate
            ->setTimezone(new DateTimeZone(self::LOCAL_TIMEZONE))
            ->setTime(0, 0);
    }

    private function resolveWeekday(
        DateTimeImmutable $referenceDate,
        ?string $weekday,
        bool $strictlyAfter
    ): ?DateTimeImmutable {
        if ($weekday === null) {
            return null;
        }

        $targetWeekday = $this->weekdayNumber($weekday);

        if ($targetWeekday === null) {
            return null;
        }

        $daysUntil = ($targetWeekday - (int) $referenceDate->format('N') + 7) % 7;

        if ($strictlyAfter && $daysUntil === 0) {
            $daysUntil = 7;
        }

        return $referenceDate->modify('+' . $daysUntil . ' days');
    }

    private function nextMonthDay(int $day, int $month, DateTimeImmutable $referenceDate): ?DateTimeImmutable
    {
        $referenceYear = (int) $referenceDate->format('Y');

        for ($year = $referenceYear; $year <= $referenceYear + 8; ++$year) {
            $date = $this->makeDate($year, $month, $day);

            if ($date !== null && $date >= $referenceDate) {
                return $date;
            }
        }

        return null;
    }

    private function makeDate(int $year, int $month, int $day): ?DateTimeImmutable
    {
        if ($year < 1 || ! checkdate($month, $day, $year)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            sprintf('%04d-%02d-%02d', $year, $month, $day),
            new DateTimeZone(self::LOCAL_TIMEZONE)
        );

        return $date instanceof DateTimeImmutable ? $date : null;
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

    private function weekdayNumber(string $weekday): ?int
    {
        $weekdays = [
            'mon' => 1,
            'tue' => 2,
            'wed' => 3,
            'thu' => 4,
            'fri' => 5,
            'sat' => 6,
            'sun' => 7,
        ];

        return $weekdays[strtolower(substr($weekday, 0, 3))] ?? null;
    }

    private function capturedValue(array $match, string $name): ?string
    {
        if (! isset($match[$name]) || ! is_array($match[$name]) || ! is_string($match[$name][0])) {
            return null;
        }

        return $match[$name][0];
    }

    private function extractVenue(string $text): ?string
    {
        $patterns = [
            '/(?:venue|where|location)\s*[:\-]\s*([^\n]+)/i',
            '/\bat\s+([A-Z][A-Za-z0-9\'\- &,]{4,80})/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                return trim(rtrim($matches[1], '.'));
            }
        }

        return null;
    }

    private function extractContact(string $text, string $fallbackEmail): ?string
    {
        $emails = Text::extractEmails($text);
        $phones = Text::extractPhones($text);
        $parts = [];

        if (! empty($emails)) {
            $parts[] = $emails[0];
        }

        if (! empty($phones)) {
            $parts[] = $phones[0];
        }

        if (empty($parts) && $fallbackEmail !== '') {
            $parts[] = $fallbackEmail;
        }

        return empty($parts) ? null : implode(' | ', $parts);
    }
}
