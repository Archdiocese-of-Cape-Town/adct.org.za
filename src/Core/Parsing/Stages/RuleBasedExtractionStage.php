<?php

namespace ADCT\ParishIntake\Core\Parsing\Stages;

use ADCT\ParishIntake\Core\Parsing\Confidence\FieldEvidence;
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
                $this->recordTitleEvidence($result, $message, $blockTitle, $text);

                $parishMatch = $this->parishNameMatch($text, '');
                $parishName = (isset($blockContext['parish_name']) ? (string) $blockContext['parish_name'] : null)
                    ?? ($parishMatch['value'] ?? null)
                    ?? $this->parishNameMatch('', $message->getSenderName())['value'] ?? null;

                if ($parishName !== null) {
                    if (isset($blockContext['parish_name'])) {
                        // The block's owning heading, which is safe shared context.
                        $result->recordFieldEvidence('parish_name', new FieldEvidence(FieldEvidence::CONTEXT));
                    } elseif ($parishMatch !== null) {
                        $result->recordFieldEvidence('parish_name', match ($parishMatch['rule']) {
                            'labelled' => new FieldEvidence(FieldEvidence::LABELLED),
                            'unanchored' => FieldEvidence::unsupported('unanchored_parish_match'),
                            default => new FieldEvidence(FieldEvidence::EXPLICIT),
                        });
                    } else {
                        // Read from the sender display name, a weaker signal than the body itself.
                        $result->recordFieldEvidence('parish_name', new FieldEvidence(FieldEvidence::INFERRED));
                    }
                }

                $result->setField('parish_name', $parishName);
                $rangeEndBeforeStart = false;

        if ($date !== null) {
            $result->setField('event_date', $date['date']);
            $result->setField('event_end_date', $date['end_date']);
                    $result->recordFieldEvidence('event_date', $this->dateEvidence($date));

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
                    $result->recordFieldEvidence('event_time', $times['type'] === 'bare'
                        // A bare 24-hour clock time with no scripture wording around it is a real,
                        // if slightly weaker, piece of evidence. A citation is never recorded as a
                        // time at all, so there is no citation case here.
                        ? new FieldEvidence(FieldEvidence::CONTEXT)
                        : new FieldEvidence(FieldEvidence::EXPLICIT));
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

                if ($venue !== null && $venueSource !== null) {
                    $result->recordFieldEvidence('venue', match ($venueSource) {
                        'label' => new FieldEvidence(FieldEvidence::LABELLED),
                        'text' => new FieldEvidence(FieldEvidence::DIRECTORY_TEXT),
                        default => new FieldEvidence(FieldEvidence::CONTEXT),
                    });
                }

                $context->setRuntimeValue('venue_source', $venueSource);
                $contact = $this->extractContact($text . "\n" . $signature, $message->getSenderEmail());

                if ($contact !== null) {
                    // The sender address is only used when the body had no contact of its own, so it
                    // is never evidence from the notice.
                    $result->recordFieldEvidence('contact', $contact === $message->getSenderEmail()
                        ? FieldEvidence::unsupported('sender_fallback')
                        : new FieldEvidence(FieldEvidence::EXPLICIT));
                }

                $result->setField('contact', $contact);
                $description = $this->eventDescription($body);

                if ($description !== null && $description !== '') {
                    $result->recordFieldEvidence('description', new FieldEvidence(FieldEvidence::EXPLICIT));
                }

                $result->setField('description', $description);
                $result->setField('attachment_names', array_map(static fn ($attachment) => $attachment->getName(), $message->getAttachments()));
        $result->addStrategy('rule_based_extraction');

        return $result;
    }

    /**
     * A title read from the message subject is the sender's own description of the notice, so
     * it is good evidence. A title scraped from the first meaningful line of the body is not:
     * in a bulletin that line is the masthead, which describes the whole document rather than
     * any one event.
     *
     * @param mixed $blockTitle
     */
    private function recordTitleEvidence(ParseResult $result, Message $message, mixed $blockTitle, string $text): void
    {
        if ($result->getField('title') === null) {
            return;
        }

        $subject = trim(preg_replace('/^(?:re|fw|fwd):\s*/i', '', $message->getSubject()) ?? $message->getSubject());

        if (is_string($blockTitle) && trim($blockTitle) !== '') {
            // The first meaningful line of a bulletin is usually running prose that happens to
            // begin the body, not a title. If the line is cut off mid-sentence, the parser invented
            // it by cutting a sentence in half, and that is fabrication rather than weak evidence.
            $result->recordFieldEvidence('title', $this->looksLikeSentenceFragment((string) $blockTitle)
                ? FieldEvidence::unsupported('sentence_fragment')
                : new FieldEvidence(FieldEvidence::EXPLICIT));

            return;
        }

        if ($subject !== '') {
                    $result->recordFieldEvidence('title', $this->subjectLooksLikeMasthead($subject)
                        ? FieldEvidence::unsupported('masthead_subject')
                        : new FieldEvidence(FieldEvidence::EXPLICIT));

                    return;
                }

                $firstLine = Text::firstMeaningfulLine($text);
                $result->recordFieldEvidence('title', $firstLine !== null && $firstLine !== $subject
                    ? FieldEvidence::unsupported('masthead_fallback')
                    : new FieldEvidence(FieldEvidence::CONTEXT));
            }

            /**
             * A subject that merely announces the bulletin ("… Weekly Bulletin October 2026", "Parish
             * Newsletter – week 42") names the document, not the event, so it is not evidence of a
             * title. The value is still extracted; only its evidence changes.
             */
            private function subjectLooksLikeMasthead(string $subject): bool
            {
                return preg_match(
                    '/\b(?:bulletin|newsletter|parish\s+news|diocesan\s+news|'
                    . 'this\s+week(?:end)?(?:\s+in\s+the\s+archdiocese)?|'
                    . 'week\s+\d+|mass\s+sheet|liturgy\s+of\s+the\s+word)\b/iu',
                    $subject
                ) === 1;
            }

    /**
     * @param array<string, mixed> $date
     */
    private function dateEvidence(array $date): FieldEvidence
    {
        $flags = [];

        if (($date['weekday_mismatch'] ?? false) === true) {
            $flags[] = 'weekday_mismatch';
        }

        if (($date['end_before_start'] ?? false) === true) {
            $flags[] = 'end_before_start';
        }

        if (($date['next_weekday_ambiguous'] ?? null) !== null) {
            $flags[] = 'next_weekday_ambiguous';
        }

        $type = (string) ($date['type'] ?? '');

        $origin = match ($type) {
            'iso', 'numeric', 'day_month', 'month_day' => FieldEvidence::EXPLICIT,
            'this_weekday', 'next_weekday', 'tomorrow', 'tonight' => FieldEvidence::CONTEXT,
            'contextual' => FieldEvidence::INFERRED,
            // A date the parser produced without recognising the pattern that produced it is
            // not evidence of anything.
            default => FieldEvidence::INFERRED,
        };

        if ($type === 'contextual' && ! ($date['has_date_context'] ?? true)) {
            $flags[] = 'no_date_context';
        }

        return new FieldEvidence($origin, $flags);
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
     * Find the parish name and report *which* rule produced it, so the confidence scorer can
     * tell a labelled match from the loose one that matches a phrase inside a sentence.
     *
     * A saint or church dedication is treated as an explicit name; anything else matched
     * without a label stays "unanchored", because the sentence-fragment rejection in
     * normaliseParishNameCandidate() can only be so precise.
     *
     * @return array{value: string, rule: string}|null
     */
    private function parishNameMatch(string $text, string $senderName): ?array
    {
        $name = $this->extractParishName($text, $senderName);

        if ($name === null) {
            return null;
        }

        if ($senderName === $name) {
            return ['value' => $name, 'rule' => 'sender_name'];
        }

        $labelled = preg_match('/^\s*(?:parish|venue|church)\s*[:\-]\s*/imu', $text) === 1;
        $dedication = preg_match('/^\s*(?:St\.?|Saint|Our Lady of|Church of)\s+/iu', $name) === 1;

        return [
            'value' => $name,
            'rule' => $labelled ? 'labelled' : ($dedication ? 'named_church' : 'unanchored'),
        ];
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
                'type' => (string) $candidate['type'],
                'has_date_context' => true,
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
                'type' => 'contextual',
                'has_date_context' => true,
                            'weekday_mismatch' => $weekdayText !== ''
                                && $this->weekdayNumber($weekdayText) !== (int) $date->format('N'),
                'end_before_start' => false,
                'next_weekday_ambiguous' => null,
            ];
        }

        return null;
    }

    /**
         * @return array{start: string, end: ?string, end_before_start: bool, type: string}|null
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
                'type' => 'range',
            ];
            }

            $single = $this->extractFirstTime($text);

            if ($single === null) {
                return null;
            }

            return [
                'start' => $single['time'],
                'end' => null,
                'end_before_start' => false,
                'type' => $single['type'],
            ];
        }

        /**
         * @return array{time: string, type: string}|null
         */
    /**
     * Finds the first start time in the text, skipping a number that is really a
     * scripture citation.
     *
     * A bare `12:45` with no `am`/`pm` is also what a scripture citation looks like, so the
     * candidate matches are located by offset and tested against the citation spans found
     * in the whole text. A citation is skipped rather than published as a time the parish
     * never gave.
     *
     * @return array{time: string, type: string}|null
     */
    private function extractFirstTime(string $text): ?array
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

            // A citation with no book name -- "Scripture for today is 12:45" -- has no citation
            // shape to detect, so the wording in front of the number is the only cue left. Checked
            // only for a bare number: an explicit "6pm" is unambiguous and is always a time.
            if (preg_match('/[ap]m/i', $match) !== 1
                && $this->isScriptureIntroduction($text, $offset)
            ) {
                continue;
            }

            $time = $this->normalizeTime($match);

            if ($time === null) {
                continue;
            }

            return [
                'time' => $time,
                'type' => preg_match('/[ap]m/i', $match) === 1 ? 'explicit' : 'bare',
            ];
        }

        return null;
    }

    /**
     * Whether the wording immediately before a bare clock-shaped number introduces a
     * scripture reference rather than a time: a citation is introduced by a scripture
     * keyword ("Scripture for today is 12:45"), whereas a time is introduced by "at",
     * "from", or nothing at all. Used only as a fallback for a citation that has no book
     * name to key off, since a book name or a citation shape is the stronger signal.
     */
    private function isScriptureIntroduction(string $text, int $offset): bool
    {
        $before = substr($text, max(0, $offset - 60), min($offset, 60));

        return preg_match(
            '/(?:scripture|reading|readings|gospel|epistle|passage|verse|chapter|'
            . 'psalm|romans|corinthians|ephesians|thessalonians|timothy|peter|john|'
            . 'matthew|mark|luke|acts|colossians|hebrews)\b[^0-9]{0,20}$/iu',
            $before
        ) === 1;
    }

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
    private function isScriptureCitation(array $citations, int $offset, int $length): bool
    {
        foreach ($citations as $citation) {
            if ($offset >= $citation['start'] && ($offset + $length) <= $citation['end']) {
                return true;
            }
        }

        return false;
    }

                    /**
                     * Decide whether a bare clock-shaped number is a scripture or chapter citation rather
                     * than a start time. The cue is the wording immediately before it: a citation is
                     * introduced by a scripture keyword or a capitalised book name ("Scripture for today
                     * is 12:45", "Romans 12:45"), whereas a start time is introduced by "at", "from",
                     * or nothing at all.
                     */
                    /**
     * Whether a candidate title is a running-prose sentence that was cut off, rather than a
     * heading. A heading is short and has no sentence punctuation; text that runs on past a comma
     * and stops mid-sentence, or that opens with a lowercase continuation, is prose.
     */
    private function looksLikeSentenceFragment(string $candidate): bool
    {
        $candidate = trim($candidate);

        if ($candidate === '') {
            return false;
        }

        if (preg_match('/[.,;:!?]$/', $candidate) === 1) {
            return false;
        }

        if (preg_match('/^(?:the|a|an|our|we|all|please|kindly|on|in|at|as|for|from|every)\b/i', $candidate) === 1) {
            return true;
        }

        // A heading is a short noun phrase. Prose that had to be cut mid-sentence is long.
        return str_word_count($candidate) > 8;
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
