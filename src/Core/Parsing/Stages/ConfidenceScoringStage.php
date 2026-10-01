<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Parsing\Stages;

use ADCT\ParishIntake\Core\Parsing\Confidence\CandidateScorer;
use ADCT\ParishIntake\Core\Parsing\Confidence\FieldEvidence;
use ADCT\ParishIntake\Core\Parsing\Contracts\StageInterface;
use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\ParseContext;
use ADCT\ParishIntake\Core\Parsing\ParseResult;

/**
 * Scores each field from the evidence that produced it and derives the overall score from
 * those per-field scores.
 *
 * The scoring itself lives in {@see CandidateScorer}; this stage only gathers the evidence and
 * publishes the result.
 */
final class ConfidenceScoringStage implements StageInterface
{
    /**
     * A candidate below this overall score is not trustworthy enough to publish unattended.
     */
    public const DEFAULT_REVIEW_THRESHOLD = 0.55;

    /**
     * A field below this score is highlighted for the submitter and the reviewer.
     */
    public const DEFAULT_FIELD_THRESHOLD = 0.60;

    /**
     * A field this important being weak is enough to hold the candidate for review even when
     * the overall score looks acceptable.
     */
    private const MATERIAL_FIELDS = ['title', 'event_date'];

    private CandidateScorer $scorer;
    private float $reviewThreshold;
    private float $fieldThreshold;

    public function __construct(
        ?CandidateScorer $scorer = null,
        ?float $reviewThreshold = null,
        ?float $fieldThreshold = null
    ) {
        $this->scorer = $scorer ?? new CandidateScorer();
        $this->reviewThreshold = self::clampThreshold($reviewThreshold, self::DEFAULT_REVIEW_THRESHOLD);
        $this->fieldThreshold = self::clampThreshold($fieldThreshold, self::DEFAULT_FIELD_THRESHOLD);
    }

    public function process(Message $message, ParseResult $result, ParseContext $context): ParseResult
    {
        $result->pruneFieldEvidence();
        $this->recordResultLevelEvidence($result);

        $scored = $this->scorer->score($result->fieldEvidence());
        $score = $this->scorer->applyWholeCandidatePenalties(
            $scored['score'],
            $this->wholeCandidateFlags($result)
        );

        $result->setConfidence($score);
        $result->setFieldConfidence([
            'score' => round($score, 3),
            'coverage' => $scored['coverage'],
            'fields' => $scored['fields'],
        ]);

        $weakMaterialField = $this->weakMaterialField($scored['fields']);
        $result->setNeedsReprocess(
            $result->needsReprocess()
            || $score < $this->reviewThreshold
            || $weakMaterialField !== null
        );

        $result->addStrategy('confidence_scoring');
        $context->addNote(sprintf('Deterministic confidence score assigned: %.2f', $score));

        if ($weakMaterialField !== null) {
            $context->addNote(sprintf(
                'The %s has no supporting evidence in the notice and needs confirmation.',
                $weakMaterialField
            ));
        }

        return $result;
    }

    /**
         * Evidence for values that are not carried in `fields` but still describe the candidate.
         */
        private function recordResultLevelEvidence(ParseResult $result): void
        {
            if ($result->getRecurrence() !== [] && $result->fieldEvidenceFor('recurrence') === null) {
                $result->recordFieldEvidence('recurrence', new FieldEvidence(FieldEvidence::CONTEXT));
            }
        }

        /**
         * The pre-existing candidate-wide defects.
         *
         * These are applied exactly once, at their historic values, and are never also charged
         * against the field that carries the flag (see {@see CandidateScorer::FLAG_PENALTIES}).
         * Counting "next Friday is ambiguous" both ways would charge one defect twice.
         *
         * `recurrence_anchor_inferred` is not here: an anchor derived from a real recurrence rule is
         * weak evidence, recorded as `inferred` on the recurrence field itself, not a fabrication.
         *
         * @return list<string>
         */
        private function wholeCandidateFlags(ParseResult $result): array
        {
            $flags = [];

            if ($result->hasDateWeekdayMismatch()) {
                $flags[] = 'weekday_mismatch';
            }

            if ($result->hasRangeEndBeforeStart()) {
                $flags[] = 'end_before_start';
            }

            if ($result->hasAmbiguousNextWeekday()) {
                $flags[] = 'next_weekday_ambiguous';
            }

            $recurrence = $result->getRecurrence();

            if (! empty($recurrence['ambiguous'])) {
                $flags[] = 'recurrence_ambiguous';
            }

            return $flags;
        }

    /**
     * @param array<string, array{score: float, origin: string, flags: array<int, string>}> $fields
     */
    private function weakMaterialField(array $fields): ?string
    {
        foreach (self::MATERIAL_FIELDS as $name) {
            $field = $fields[$name] ?? null;

            if ($field !== null && $field['score'] < $this->fieldThreshold) {
                return $name;
            }
        }

        return null;
    }

    private static function clampThreshold(?float $value, float $default): float
    {
        if ($value === null || ! is_finite($value)) {
            return $default;
        }

        return max(0.0, min(1.0, $value));
    }
}
