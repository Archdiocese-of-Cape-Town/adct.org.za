<?php

namespace ADCT\ParishIntake\Core\Parsing;

/**
 * A date or time the parser saw and could not resolve, kept in the notes as a token
 * rather than a sentence.
 *
 * The reason string is the contract. A screen branches on the reason and only shows
 * the phrase, so nothing downstream has to match on prose, and the wording of the
 * warning can change without touching the parser.
 *
 * The notes column is the whole mechanism, so this needs no column of its own and no
 * migration: the acknowledgement of an unresolved date is derived from the note at
 * the moment it is read, which is why it can never drift from the parse that produced
 * it. The cost is that the note outlives an edit -- a saved correction updates
 * `fields` and leaves the note alone -- so an approval that acknowledges an
 * unresolved date has to acknowledge the attempt, not the candidate.
 */
final class UnparsedDateTimeCandidate
{
    /** A phrase read as a date that does not resolve to a calendar date. */
    public const DATE_REASON = 'unparsed_date_candidate';

    /** A phrase read as a clock that does not resolve to a time of day. */
    public const TIME_REASON = 'unparsed_time_candidate';

    /** Long enough to recognise a phrase, short enough to read in a table cell. */
    private const MAX_PHRASE_LENGTH = 40;

    /**
     * @param list<string> $dates
     * @param list<string> $times
     */
    private function __construct(
        public readonly array $dates,
        public readonly array $times
    ) {
    }

    /**
     * Every unresolved date and time recorded on a result, in note order.
     *
         * A reason with no phrase after it is ignored. Such a note says a value could not be
         * read but not which one, so there is nothing to put in front of a human, and
         * counting it would ask an approver to acknowledge something they cannot see.
         *
         * @param iterable<mixed> $notes
         */
        public static function fromNotes(iterable $notes): self
        {
            $dates = [];
            $times = [];

            foreach ($notes as $note) {
                if (! is_string($note)) {
                    continue;
                }

                if (str_starts_with($note, self::DATE_REASON . ':')) {
                    $phrase = self::phraseOf($note, self::DATE_REASON);

                    if ($phrase !== '') {
                        $dates[$phrase] = true;
                    }

                    continue;
                }

                if (str_starts_with($note, self::TIME_REASON . ':')) {
                    $phrase = self::phraseOf($note, self::TIME_REASON);

                    if ($phrase !== '') {
                        $times[$phrase] = true;
                    }
                }
            }

            return new self(array_keys($dates), array_keys($times));
        }

    /** Whether a date was found and could not be read. */
    public function hasDate(): bool
    {
        return $this->dates !== [];
    }

    /** Whether a time was found and could not be read. */
    public function hasTime(): bool
    {
        return $this->times !== [];
    }

    /**
     * Builds the note for a phrase the parser found and could not resolve.
     *
     * The phrase is collapsed and trimmed, because it is quoted back on screens that
     * reach further than the parser's own logs do. It is only ever the matched fragment
     * of a date- or clock-shaped run and never a line of the source email, so no address,
     * phone number or parish name from the notice travels with it.
     */
    public static function note(string $reason, string $phrase): string
    {
        return $reason . ':' . self::bounded($phrase);
    }

    /**
         * Whether anything on this candidate has to be acknowledged before approval.
         *
         * An unreadable date counts and an unreadable time does not. A candidate with no date
         * publishes as an event that never happens, which is the failure #167 reports. A
         * candidate with no time is published all-day unless the parish said otherwise, which
         * is wrong for a morning Mass but recoverable, and a notice whose clock was unreadable
         * may well have been an all-day notice to begin with. Asking an approver to acknowledge
         * a value they cannot act on only teaches them to tick the box without reading it.
         *
         * This is the one place that asymmetry is defined. {@see ReviewQueuePolicy} asks this
         * question, and so do the tests, so the blocking condition cannot be one thing in
         * production and another in the suite.
         */
        public function isUnresolved(): bool
        {
            return $this->hasDate();
        }

    /** Every phrase, in the order the notes recorded it. */
    public function phrases(): array
    {
        return [...$this->dates, ...$this->times];
    }

    /** The reasons this class owns, for a caller testing several at once. */
    public static function reasons(): array
    {
        return [self::DATE_REASON, self::TIME_REASON];
    }

        /**
         * The sentence a screen shows, or null when the note is not one of ours.
         *
         * The wording has to say the value *could not be read*, never that the field is
         * empty: an empty date reads as "the bulletin did not say" and gets approved,
         * where an unreadable one has to be put right. Each reason is handled separately
         * because the two are corrected by different people -- a date is corrected on the
         * form before approval, a time often needs the parish asked.
         */
        public static function describe(string $note): ?string
        {
            if (str_starts_with($note, self::DATE_REASON . ':')) {
                return sprintf(
                    'The notice gives "%s" as the date. It could not be read as a real date, '
                            . 'so no date has been set. Enter the date here, or ask the parish to confirm it.',
                    self::phraseOf($note, self::DATE_REASON)
                );
            }

            if (str_starts_with($note, self::TIME_REASON . ':')) {
                return sprintf(
                    'The notice gives "%s" as the time. It could not be read as a real time, '
                            . 'so no time has been set. Enter the time here if this is not an all-day event.',
                    self::phraseOf($note, self::TIME_REASON)
                );
            }

            return null;
        }

        private static function phraseOf(string $note, string $reason): string
        {
            return self::bounded(substr($note, strlen($reason) + 1));
        }

    private static function bounded(string $phrase): string
    {
        $phrase = trim(preg_replace('/\s+/u', ' ', $phrase) ?? $phrase);

        if ($phrase === '') {
            return '';
        }

        return function_exists('mb_substr')
            ? mb_substr($phrase, 0, self::MAX_PHRASE_LENGTH)
            : substr($phrase, 0, self::MAX_PHRASE_LENGTH);
    }
}
