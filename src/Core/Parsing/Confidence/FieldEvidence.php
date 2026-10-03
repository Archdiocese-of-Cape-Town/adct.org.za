<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Parsing\Confidence;

use InvalidArgumentException;

/**
 * Why a single extracted field is believed, recorded at the moment the parser sets it.
 *
 * The parser already knows how it obtained every value; this records that knowledge so a
 * value read from the bulletin can be told apart from one the parser fell back to.
 *
 * @see CandidateScorer for how an origin and its flags turn into a score.
 */
final class FieldEvidence
{
    /** Resolved from a verified sender link or the parish directory. */
    public const DIRECTORY_VERIFIED = 'directory_verified';

    /** Matched from the message body or the block context, without sender verification. */
    public const DIRECTORY_TEXT = 'directory_text';

    /** Read from an explicit label in the block, such as "Venue:". */
    public const LABELLED = 'labelled';

    /** An unambiguous literal token in the block, such as an ISO or numeric date. */
    public const EXPLICIT = 'explicit';

    /** Derived from safe shared context, such as the bulletin month or the owning heading. */
    public const CONTEXT = 'context';

    /** A deterministic derivation with weaker support, such as a bare day plus block month. */
    public const INFERRED = 'inferred';

    /** A fallback matched something unrelated: there is no supporting evidence. */
    public const UNSUPPORTED = 'unsupported';

    /** Supplied by the AI enrichment stage. */
    public const AI = 'ai';

    /**
     * Read out of an image attachment by the OCR stage rather than typed by the parish.
     *
     * This is a flag rather than an origin on purpose. The origin still records *how* the value was read
     * -- a date printed on the poster is genuinely `explicit` -- and the flag records the single extra
     * reason to doubt it: the characters were recognised from a photograph. Keeping the two apart means
     * an OCR field is charged for its unreadability without also being flattened to the weakest origin,
     * so a clearly printed date on a good scan is still stronger evidence than a date guessed from a bare
     * day and the bulletin month.
     */
    public const OCR = 'ocr';

    /** Set by a human. Never scored down. */
    public const MANUAL = 'manual';

    private const ORIGINS = [
        self::DIRECTORY_VERIFIED,
        self::DIRECTORY_TEXT,
        self::LABELLED,
        self::EXPLICIT,
        self::CONTEXT,
        self::INFERRED,
        self::UNSUPPORTED,
        self::AI,
        self::MANUAL,
    ];

    private const FLAGS = [
            'masthead_fallback',
            'masthead_subject',
            'sentence_fragment',
            'unanchored_parish_match',
            'bare_time_match',
            'sender_fallback',
            'no_date_context',
            'weekday_mismatch',
            'end_before_start',
            'next_weekday_ambiguous',
            'recurrence_ambiguous',
            self::OCR,
        ];

    /**
     * @param string       $origin One of the ORIGINS constants.
     * @param array<mixed> $flags  Specific defects attached to this value.
     */
    public function __construct(
        private readonly string $origin,
        private readonly array $flags = []
    ) {
        if (! in_array($origin, self::ORIGINS, true)) {
            throw new InvalidArgumentException(sprintf('Unknown field evidence origin "%s".', $origin));
        }

        foreach ($flags as $flag) {
            if (! is_string($flag) || ! in_array($flag, self::FLAGS, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown field evidence flag "%s".',
                    is_scalar($flag) ? (string) $flag : gettype($flag)
                ));
            }
        }
    }

    public static function unsupported(string ...$flags): self
    {
        return new self(self::UNSUPPORTED, $flags);
    }

    public static function inferred(string ...$flags): self
    {
        return new self(self::INFERRED, $flags);
    }

    public function origin(): string
    {
        return $this->origin;
    }

    /**
     * @return array<int, string>
     */
    public function flags(): array
    {
        return array_values($this->flags);
    }

    public function hasFlag(string $flag): bool
    {
        return in_array($flag, $this->flags, true);
    }

    /**
     * Storage-safe label combining the origin and any flags, e.g. `unsupported:masthead_fallback`.
     */
    public function reason(): string
    {
        return $this->flags === []
            ? $this->origin
            : $this->origin . ':' . implode(',', $this->flags);
    }

    /**
     * @return array{origin: string, flags: array<int, string>, reason: string}
     */
    public function toArray(): array
    {
        return [
            'origin' => $this->origin,
            'flags' => $this->flags(),
            'reason' => $this->reason(),
        ];
    }
}