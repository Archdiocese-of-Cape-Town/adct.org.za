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
        $body = (string) $context->getRuntimeValue('cleaned_body', $message->getBody());
        $signature = (string) $context->getRuntimeValue('signature_text', '');
        $lower = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
        $blockContext = $context->getRuntimeValue('block_context', []);

        if (! is_array($blockContext)) {
            $blockContext = [];
        }

        $bulletinRange = $this->findBulletinDateRange($text);
        $dateText = $text;

        if ($bulletinRange !== null) {
            $dateText = substr_replace(
                $text,
                ' ',
                $bulletinRange['offset'],
                strlen($bulletinRange['match'])
            );
        } elseif (isset($blockContext['bulletin_date_range']) && is_string($blockContext['bulletin_date_range'])) {
            $bulletinRange = $this->findBulletinDateRange($blockContext['bulletin_date_range']);
        }

        $referenceDate = $bulletinRange['date'] ?? $this->referenceDate($message);
        $context->setRuntimeValue('reference_date', $referenceDate);
        $monthContext = isset($blockContext['month']) && is_string($blockContext['month'])
            ? $blockContext['month']
            : null;
        $yearContext = isset($blockContext['year']) && is_numeric($blockContext['year'])
            ? (int) $blockContext['year']
            : null;
        $dateText = $this->removeUntilDate($dateText);
        $replacementText = $this->replacementScheduleText($body);
        $replacementDate = $replacementText === null
            ? null
            : $this->extractDate($replacementText, $referenceDate);
        $replacementTimes = $replacementText === null ? null : $this->extractTimes($replacementText);
        $date = $replacementDate ?? $this->extractDate($dateText, $referenceDate, $monthContext, $yearContext);
        $times = $replacementTimes ?? ($replacementDate === null ? $this->extractTimes($text) : null);

        $classification = $this->classify($lower, $date !== null, $times !== null);
        $result->setClassification($classification);
        if ($replacementText !== null && $replacementDate === null && $replacementTimes === null) {
            $result->setNeedsReprocess(true);
            $result->setField('replacement_schedule_unresolved', true);
            $result->addNote('A replacement date was not found; review the change notice schedule.');
        }
        $blockTitle = $context->getRuntimeValue('block_title');
        $result->setField(
            'title',
            is_string($blockTitle) && trim($blockTitle) !== ''
                ? trim($blockTitle)
                : $this->extractTitle($message, $text)
        );
        $result->setField(
            'parish_name',
            (isset($blockContext['parish_name']) ? (string) $blockContext['parish_name'] : null)
                ?? $this->extractParishName($text, '')
                ?? $this->extractParishName('', $message->getSenderName())
        );
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

        $venue = $this->extractVenue($body);
        $venueSource = $venue === null
            ? null
            : ($this->hasLabelledVenue($body) ? 'label' : 'text');

        if ($venue === null) {
            $venue = $this->extractVenue($message->getSubject());
            $venueSource = $venue === null
                ? null
                : ($this->hasLabelledVenue($message->getSubject()) ? 'label' : 'text');
        }

        if ($venue === null && isset($blockContext['venue'])) {
            $venue = (string) $blockContext['venue'];
            $venueSource = 'context';
        }

        $result->setField('venue', $venue);
        $context->setRuntimeValue('venue_source', $venueSource);
        $result->setField('contact', $this->extractContact($text . "\n" . $signature, $message->getSenderEmail()));
        $result->setField('description', $this->eventDescription($body));
        $result->setField('attachment_names', array_map(static fn ($attachment) => $attachment->getName(), $message->getAttachments()));
        $result->addStrategy('rule_based_extraction');

        return $result;
    }

    private function classify(string $lower, bool $hasDate, bool $hasTime): string
    {
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

    private function removeUntilDate(string $text): string
    {
        $weekday = '(?:(?:' . self::WEEKDAY_PATTERN . ')\.?\s+)?';
        $year = '(?:,?\s+\d{4}|\s+\d{2})?';
        $dayMonth = '\d{1,2}(?:st|nd|rd|th)?\s+' . self::MONTH_PATTERN . '\.?' . $year;
        $monthDay = self::MONTH_PATTERN . '\.?\s+\d{1,2}(?:st|nd|rd|th)?' . $year;
        $pattern = '~\buntil\s+' . $weekday . '(?:' . $dayMonth . '|' . $monthDay . ')\b~iu';

        return preg_replace($pattern, ' ', $text) ?? $text;
    }

    private function replacementScheduleText(string $body): ?string
    {
        if (! preg_match('~\b(?:postponed|rescheduled|moved)\s+to\s+([^\r\n]+)~iu', $body, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function extractTitle(Message $message, string $text): ?string
    {
        $subject = trim(preg_replace('/^(?:re|fw|fwd):\s*/i', '', $message->getSubject()) ?? $message->getSubject());

        if ($subject !== '') {
            return $subject;
        }

        return Text::firstMeaningfulLine($text);
    }

    /**
     * A captured parish name must start a name, not continue a sentence. These leading
     * words are the ones observed in real bulletins, where the word "Parish" belongs to
     * a council, an office or an article rather than to a name.
     */
    private const PARISH_NAME_LEADING_WORDS = [
        'i', 'we', 'you', 'the', 'a', 'an', 'this', 'that', 'these', 'those', 'all',
        'every', 'each', 'our', 'your', 'their', 'his', 'her', 'its', 'please', 'kindly',
        'thank', 'thanks', 'and', 'or', 'but', 'if', 'when', 'while', 'for', 'from', 'to',
        'of', 'in', 'on', 'at', 'by', 'as', 'so', 'who', 'what', 'which', 'dear', 'contact',
    ];

    /**
     * Words that follow "St"/"Saint" but are never a saint's name.
     */
    private const SAINT_NAME_STOP_WORDS = [
        'parish', 'church', 'council', 'councils', 'office', 'secretary', 'counsellor',
        'pastoral', 'finance', 'financial', 'administrator', 'community', 'committee',
        'team', 'staff', 'school', 'hall', 'centre', 'center', 'festival',
        'day', 'week', 'month', 'year', 'mass', 'times', 'newsletter', 'bulletin',
    ];

    private function extractParishName(string $text, string $senderName): ?string
    {
        foreach (self::parishNameCandidates($text) as $candidate) {
            $name = $this->normaliseParishNameCandidate($candidate);

            if ($name !== null) {
                return $name;
            }
        }

        if (stripos($senderName, 'parish') !== false || stripos($senderName, 'church') !== false) {
            return trim($senderName);
        }

        return null;
    }

    /**
     * Yields plausible parish-name candidates in priority order: an explicit label
     * first, then names that begin a line.
     *
     * @return list<string>
     */
    private static function parishNameCandidates(string $text): array
    {
        $candidates = [];

        if (preg_match_all('/^\s*(?:parish|venue|church)\s*[:\-]\s*([^\n\r]+)/imu', $text, $matches)) {
            foreach ($matches[1] as $match) {
                $candidates[] = $match;
            }
        }

        // A name token is a capitalised word, optionally carrying internal apostrophes
        // and hyphens ("St Mary's", "Our Lady of Peace"). "of the" is a legal connector
        // in a dedication such as "Our Lady of the Cape", so the article is allowed
        // after "of" but never in place of a name. The optional "of ..." group must
        // keep its trailing space inside, or it swallows the separator the name
        // token then needs.
        $nameToken = "[\p{Lu}][\p{Ll}\p{Lu}\'’\-]*";
        $ofClause = '(?:\s+of\s+(?:the\s+)?[A-Z][A-Za-z]*)*';
        $dedication = 'St\.?|Saint|Our\s+Lady(?:\s+of(?:\s+the)?)?|Church\s+of';
        $name = $nameToken . $ofClause;

        $lines = preg_split('/\R/u', $text) ?: [];

        foreach ($lines as $line) {
            if (preg_match(
                '/^\s*((?:' . $dedication . ')\s+' . $name . '(?:\s+(?:Parish|Church))?)/u',
                $line,
                $match
            )) {
                $candidates[] = $match[1];
            }

            if (preg_match(
                '/^\s*(' . $name . '\s+Parish)\b/u',
                $line,
                $match
            )) {
                $candidates[] = $match[1];
            }
        }

        return $candidates;
    }

    private function normaliseParishNameCandidate(string $candidate): ?string
    {
        // A parish name never contains sentence punctuation. Apostrophes are kept:
        // they belong to names such as "St Mary's".
        $name = trim(preg_split('/[.,;:!?()\[\]"]/u', $candidate)[0] ?? '');

        if ($name === '' || strlen($name) > 120) {
            return null;
        }

        $words = preg_split('/\s+/u', $name) ?: [];

        if ($words === []) {
            return null;
        }

        $isOurLady = strtolower($words[0]) === 'our' && strtolower($words[1] ?? '') === 'lady';

        if (! $isOurLady && in_array(strtolower($words[0]), self::PARISH_NAME_LEADING_WORDS, true)) {
            return null;
        }

        if (! $this->hasCapitalisedNameToken($words)) {
            return null;
        }

        if (in_array(strtolower($words[0]), ['st', 'st.', 'saint', 'church'], true) && ! $this->hasSaintName($words)) {
            return null;
        }

        return $name;
    }

    /**
     * @param list<string> $words
     */
    private function hasCapitalisedNameToken(array $words): bool
    {
        foreach (array_slice($words, 1) as $word) {
            if (in_array(strtolower($word), ['of', 'the', 'and'], true)) {
                continue;
            }

            return $this->isCapitalised($word);
        }

        return false;
    }

    /**
     * "St Mary" is a name; "St Parish" is not. The second token after the title must be
     * capitalised and must not be a common noun.
     *
     * @param list<string> $words
     */
    private function hasSaintName(array $words): bool
    {
        $nameToken = null;

        foreach (array_slice($words, 1) as $word) {
            if (strtolower($word) === 'of') {
                continue;
            }

            $nameToken = $word;
            break;
        }

        if ($nameToken === null || ! $this->isCapitalised($nameToken)) {
            return false;
        }

        return ! in_array(strtolower($nameToken), self::SAINT_NAME_STOP_WORDS, true);
    }

    private function isCapitalised(string $word): bool
    {
        return preg_match('/^\p{Lu}/u', $word) === 1;
    }

    /**
     * @return array{date: string, end_date: ?string, weekday_mismatch: bool, end_before_start: bool, next_weekday_ambiguous: ?string}|null
     */
    private function extractDate(
        string $text,
        DateTimeImmutable $referenceDate,
        ?string $monthContext = null,
        ?int $yearContext = null
    ): ?array
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
            // "this coming <weekday>" and "coming <weekday>" are the same rule as
            // "this <weekday>": a parish writes both, and the word "coming" in
            // between must not stop the date resolving.
            //
            // At least one qualifier is required. Making both optional would turn a
            // bare weekday into a date, which collides with recurrence phrases such as
            // "every Tuesday" and with "on or after 29 September".
            'this_weekday' => '~\b(?:(?:this|coming)\s+)+(?<weekday>' . self::WEEKDAY_PATTERN . ')\b~i',
            'next_weekday' => '~\bnext\s+(?:coming\s+)?(?<weekday>' . self::WEEKDAY_PATTERN . ')\b~i',
            'tomorrow' => '~\btomorrow\b~i',
            'tonight' => '~\btonight\b~i',
            // "end of ..." is a deadline phrasing, but it is the only date information
            // the line carries, so it is resolved here. Typed as a deadline
            // separately -- see the note in docs/parish-intake-project-backlog.md.
            'end_of_month' => '~\b(?:end|close)\s+(?:of\s+)?(?:the\s+)?(?:(?<month>' . self::MONTH_PATTERN . ')\.?(?:,?\s+(?<year>\d{4}))?|month|this\s+month|next\s+month)\b~iu',
            'first_of_month' => '~\b(?:the\s+)?first\s+of\s+(?<month>' . self::MONTH_PATTERN . ')\.?(?:,?\s+(?<year>\d{4}))?\b~iu',
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
            } elseif ($candidate['type'] === 'end_of_month') {
                $date = $this->resolveEndOfMonth($match, $referenceDate, $monthContext, $yearContext);
                $weekdayMismatch = false;
            } elseif ($candidate['type'] === 'first_of_month') {
                $monthText = $this->capturedValue($match, 'month');
                $month = $this->monthNumber($monthText ?? '');
                $date = $month === null
                    ? null
                    : $this->resolveMonthDay(1, $month, $this->capturedValue($match, 'year'), $referenceDate);
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

        if ($monthContext !== null) {
            return $this->extractContextualDate($text, $referenceDate, $monthContext, $yearContext);
        }

        return null;
    }

    /**
     * Resolves "end of this month", "end of September" and "end of September 2026"
     * to the last day of the month, never the first.
     *
     * A named month with no year is read against the bulletin month context where one
     * is known, so "end of September" in a September bulletin means this year rather
     * than rolling forward a whole year. "Next month" is relative to the reference
     * date.
     *
     * @param array<int, array{0: string, 1: int}|null> $match
     */
    private function resolveEndOfMonth(
        array $match,
        DateTimeImmutable $referenceDate,
        ?string $monthContext,
        ?int $yearContext
    ): ?DateTimeImmutable {
        $monthText = $this->capturedValue($match, 'month');
        $month = $monthText === null ? null : $this->monthNumber($monthText);

        if ($month === null) {
            return $this->resolveRelativeMonth($match, $referenceDate, $monthContext, $yearContext);
        }

        $year = $this->capturedValue($match, 'year');

        if ($year !== null) {
            return $this->lastDayOfMonth((int) $this->normalizeYear($year), $month);
        }

        $contextMonth = $monthContext === null ? null : $this->monthNumber($monthContext);

        if ($contextMonth !== null) {
            $year = $yearContext ?? (int) $referenceDate->format('Y');
            $date = $this->lastDayOfMonth($year, $month);

            if ($date !== null && $date >= $referenceDate) {
                return $date;
            }
        }

        return $this->lastDayOfMonth((int) $referenceDate->format('Y'), $month);
    }

    /**
     * "end of this month" and "end of next month" carry no month name of their own.
     *
     * @param array<int, array{0: string, 1: int}|null> $match
     */
    private function resolveRelativeMonth(
        array $match,
        DateTimeImmutable $referenceDate,
        ?string $monthContext,
        ?int $yearContext
    ): ?DateTimeImmutable {
        $whole = strtolower($match[0][0] ?? '');

        if (str_contains($whole, 'next')) {
            $anchor = $monthContext === null ? null : $this->monthNumber($monthContext);

            if ($anchor !== null) {
                $year = $yearContext ?? (int) $referenceDate->format('Y');

                if ($anchor === 12) {
                    return $this->lastDayOfMonth($year + 1, 1);
                }

                return $this->lastDayOfMonth($year, $anchor + 1);
            }

            return $referenceDate->modify('last day of next month');
        }

        $contextMonth = $monthContext === null ? null : $this->monthNumber($monthContext);
        $month = $contextMonth ?? (int) $referenceDate->format('n');
        $year = $yearContext ?? (int) $referenceDate->format('Y');
        $date = $this->lastDayOfMonth($year, $month);

        if ($date === null) {
            return null;
        }

        // "the month" is the calendar month the reference date falls in, so it never
        // needs rolling forward. Guard anyway so a stale bulletin date cannot produce
        // a deadline that has already passed.
        if (str_contains($whole, 'this') && $date < $referenceDate) {
            return $this->lastDayOfMonth($year + 1, $month);
        }

        return $date;
    }

    private function lastDayOfMonth(int $year, int $month): ?DateTimeImmutable
    {
        if ($year < 1 || $month < 1 || $month > 12) {
            return null;
        }

        // Deliberately not cal_days_in_month(): that lives in ext-calendar, which the
        // hosting environment is not required to have. "last day of month" is
        // calendar-correct for February, so it needs no leap-year special case.
        $lastDay = DateTimeImmutable::createFromFormat(
            '!Y-m',
            sprintf('%04d-%02d', $year, $month),
            new DateTimeZone(self::LOCAL_TIMEZONE)
        );

        if (! $lastDay instanceof DateTimeImmutable) {
            return null;
        }

        return $lastDay->modify('last day of this month')->setTime(0, 0);
    }

    private function resolveMonthDay(
        int $day,
        int $month,
        ?string $yearText,
        DateTimeImmutable $referenceDate
    ): ?DateTimeImmutable {
        return $yearText === null
            ? $this->nextMonthDay($day, $month, $referenceDate)
            : $this->makeDate($this->normalizeYear($yearText), $month, $day);
    }

    /**
     * @return array{date: string, end_date: null, weekday_mismatch: bool, end_before_start: false, next_weekday_ambiguous: null}|null
     */
    private function extractContextualDate(
        string $text,
        DateTimeImmutable $referenceDate,
        string $monthText,
        ?int $yearContext
    ): ?array {
        $month = $this->monthNumber($monthText);

        if ($month === null) {
            return null;
        }

        $referenceDate = $referenceDate
            ->setTimezone(new DateTimeZone(self::LOCAL_TIMEZONE))
            ->setTime(0, 0);
        $pattern = '~^\s*(?:(?<weekday>' . self::WEEKDAY_PATTERN . ')\.?\s+)?'
            . '(?<day>\d{1,2})(?:st|nd|rd|th)?\s*(?:[-–—|]|:(?!\d)|$)~iu';

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = preg_replace('/^\s*(?:[-*•]\s+|\d+[.)]\s+)/u', '', trim($line)) ?? trim($line);
            $line = preg_replace('/^\s*(?:date|when)\s*:\s*/iu', '', $line) ?? $line;

            if (! preg_match($pattern, $line, $matches)) {
                continue;
            }

            $day = (int) $matches['day'];
            $date = $yearContext === null
                ? $this->nextMonthDay($day, $month, $referenceDate)
                : $this->makeDate($yearContext, $month, $day);

            if ($date === null) {
                continue;
            }

            $weekdayText = $matches['weekday'] ?? '';

            return [
                'date' => $date->format('Y-m-d'),
                'end_date' => null,
                'weekday_mismatch' => $weekdayText !== ''
                    && $this->weekdayNumber($weekdayText) !== (int) $date->format('N'),
                'end_before_start' => false,
                'next_weekday_ambiguous' => null,
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
        $citations = $this->scriptureCitationSpans($text);

        preg_match_all(
            '~\b(?:\d{1,2}(?:[:.]\d{2})?\s*(?:am|pm)|\d{1,2}:\d{2})\b~i',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        foreach ($matches[0] as [$match, $offset]) {
            if ($this->isScriptureCitation($citations, $offset, strlen($match))) {
                continue;
            }

            $time = $this->normalizeTime($match);

            if ($time !== null) {
                return $time;
            }
        }

        return null;
    }

    /**
     * Locates scripture citations in the text. A citation is a capitalised word
     * followed by a chapter number, a colon and a verse, optionally continuing into a
     * verse range. Anchoring on the word before the chapter — rather than on a list of
     * book names — is what keeps this rule based on the shape of a citation, so a book
     * this has never seen is still recognised.
     *
     * @return list<array{start: int, end: int}>
     */
    private function scriptureCitationSpans(string $text): array
    {
        $spans = [];

        preg_match_all(
            '~(?<![:\w])(?<book>[A-Z][A-Za-z]{1,20}(?:\s+[A-Z][A-Za-z]{1,20}){0,2})\s+'
            . '(?<chapter>\d{1,3}):(?<verse>\d{1,3})\b(?:'
            . '\s*[\-–—]\s*\d{1,3}\b'
            . ')?~u',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        foreach ($matches[0] as [$citation, $offset]) {
            $spans[] = [
                'start' => $offset,
                'end' => $offset + strlen($citation),
            ];
        }

        return $spans;
    }

    /**
     * @param list<array{start: int, end: int}> $citations
     */
    private function isScriptureCitation(array $citations, int $offset, int $length): bool
    {
        foreach ($citations as $citation) {
            if ($offset >= $citation['start'] && ($offset + $length) <= $citation['end']) {
                return true;
            }
        }

        return false;
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
        $labelledVenueValue = '[^\r\n]+?';
        $unlabelledVenueName = '(?:St\.?\s+)?[A-Z][A-Za-z0-9\'\- &,]{4,80}?';
        $time = '(?:\d{1,2}(?:[:.]\d{2})?\s*(?:a\.?m\.?|p\.?m\.?)|\d{1,2}[:.]\d{2})';
        $venueEnd = '(?=\s+(?:for\s+(?:a|an|the)\b'
            . '|at\s+' . $time . '\b'
            . '|on\s+' . self::WEEKDAY_PATTERN . '\b)|[,;]|\R|(?<!St)\.(?=\s|$)|$)';
        $patterns = [
            '/(?:venue|where|location)\s*[:\-]\s*(' . $labelledVenueValue . ')' . $venueEnd . '/iu',
            '/\bat\s+(' . $unlabelledVenueName . ')' . $venueEnd . '/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    private function hasLabelledVenue(string $text): bool
    {
        return preg_match('/(?:venue|where|location)\s*[:\-]/iu', $text) === 1;
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

    private function eventDescription(string $body): string
    {
        $lines = preg_split('/\R/u', $body) ?: [];
        $descriptionLines = array_filter(
            $lines,
            static fn (string $line): bool => ! preg_match('/^\s*parish\s*[:\-]/i', $line)
        );

        return trim(implode("\n", $descriptionLines));
    }
}
