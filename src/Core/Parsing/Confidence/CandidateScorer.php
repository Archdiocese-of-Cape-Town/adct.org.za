<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Parsing\Confidence;

use InvalidArgumentException;

/**
 * Turns per-field evidence into a per-field score and an overall candidate score.
 *
 * The scoring model exists because issue #130: the previous model added a fixed bonus for every
 * populated field, so a parser that invented more values scored *higher*. Here a value the
 * parser fell back to (`unsupported`) scores zero and actively lowers the overall score, so
 * guessing cannot help.
 *
 * Pure PHP with no WordPress dependency, and no clock: everything it needs is passed in.
 */
final class CandidateScorer
{
    /**
     * How much each field is worth to the overall score. A title or a date is what makes a
     * notice usable; a contact number is not.
     *
     * This table is the complete list of fields that are scored, so a field with no weight here
     * would be scored but would not move the overall score or coverage. Keep the two in step.
     */
    public const WEIGHTS = [
        'title' => 3.0,
        'event_date' => 3.0,
        'event_time' => 2.0,
        'parish_name' => 2.0,
        'venue' => 1.0,
        'event_type' => 1.0,
        'contact' => 1.0,
        'description' => 1.0,
        'recurrence' => 1.0,
    ];

    /**
     * Base score per origin, deliberately a table rather than a formula so the ordering that
     * fixes #130 can be read off directly.
     *
     * `unsupported` is the only zero: it means the value was not read from the notice at all.
     *
     * The scale is anchored so that a value stated outright in the notice scores at the top of
     * the range. A notice whose every field was actually read can then reach full confidence,
     * which is what makes the review threshold meaningful: a low score always means something was
     * weak or missing, never just that the scale was compressed. Everything below `explicit` is
     * then read as a degree of indirectness, and `unsupported` as the qualitative break.
     */
    private const ORIGIN_SCORES = [
        FieldEvidence::DIRECTORY_VERIFIED => 1.00,
        FieldEvidence::LABELLED => 1.00,
        FieldEvidence::EXPLICIT => 0.98,
        FieldEvidence::DIRECTORY_TEXT => 0.96,
        FieldEvidence::CONTEXT => 0.92,
        FieldEvidence::INFERRED => 0.85,
        FieldEvidence::AI => 0.45,
        FieldEvidence::UNSUPPORTED => 0.00,
    ];

    /**
     * A human-entered value is as reliable as it is possible to be.
     */
    private const MANUAL_SCORE = 1.00;

    /**
         * Subtractions from a field's own score, for defects that are a property of *that field's*
         * extraction.
         *
         * The candidate-wide date defects (`weekday_mismatch`, `end_before_start`,
         * `next_weekday_ambiguous`) are deliberately absent: they are applied once, at their
         * historic values, in {@see WHOLE_CANDIDATE_PENALTIES}. Applying them in both places would
         * charge one defect twice.
         */
        private const FLAG_PENALTIES = [
            'masthead_fallback' => 0.00,
            'masthead_subject' => 0.00,
            'sentence_fragment' => 0.05,
            'unanchored_parish_match' => 0.05,
            'bare_time_match' => 0.05,
            'sender_fallback' => 0.00,
            'no_date_context' => 0.10,
            // Text recognised from a photograph of a poster is real evidence -- it is what the parish
            // actually wrote -- but a weaker reading of it. A misread digit or a broken letter is invisible
            // to every other check in the model, because the characters were never compared against anything
            // the parish typed. So the charge is set to land a clearly printed value at 0.53: below the field
            // threshold and below the review threshold, at the same score as `ai`. Both are machine-read rather
            // than machine-written, and a machine-read date is the value a human must confirm before it reaches
            // the events page. It still scores well above zero, so an unreadable poster still produces a usable
            // candidate instead of a blank one.
            FieldEvidence::OCR => 0.45,
        ];

    /**
     * How much an `unsupported` field costs the *overall* score, scaled by the field's weight.
     * This is the active half of the #130 fix: inventing a field does not merely fail to help,
     * it hurts.
     */
    private const UNSUPPORTED_PENALTY = 0.15;

    /**
     * The additional overall penalty for each share of required weight the parser could not
     * support, as a fraction of the total weight.
     *
     * A missing required field is worse than an unsupported optional one: without a title or a
     * date there is no event to publish at all, so this is charged on top of the unsupported
     * penalty and on top of the loss of coverage.
     */
    private const MISSING_REQUIRED_CHARGE = 0.30;

    /**
     * Defects that are properties of the candidate as a whole rather than of one field. These
     * are the pre-existing penalties, carried over at their exact values so the deltas already
     * asserted by DateParsingTest and RecurrenceDetectionStageTest are preserved.
     */
    public const WHOLE_CANDIDATE_PENALTIES = [
        'weekday_mismatch' => 0.15,
        'end_before_start' => 0.15,
        'next_weekday_ambiguous' => 0.05,
        'recurrence_ambiguous' => 0.15,
    ];

    /**
     * The overall score is the weighted mean of the field scores, then scaled by coverage. A
     * candidate cannot reach a high score by having only one perfect field.
     *
     * The floor and range are calibrated so that a complete, fully evidenced notice still
     * scores at its full confidence, while a thin notice is held back.
     */
    private const COVERAGE_FLOOR = 0.88;
    private const COVERAGE_RANGE = 0.12;

    /**
     * Without these the event cannot be published, so a missing or fabricated one is charged as
     * missing evidence rather than being quietly skipped.
     */
    private const REQUIRED_FIELDS = ['title', 'event_date'];

    /**
     * Score one field from its evidence.
     *
     * @param list<string> $flags
     */
    public function scoreField(string $field, string $origin, array $flags = []): float
    {
        if ($origin === FieldEvidence::MANUAL) {
            return self::MANUAL_SCORE;
        }

        $base = self::ORIGIN_SCORES[$origin] ?? 0.0;
        $penalty = 0.0;

        foreach ($flags as $flag) {
            $penalty += self::FLAG_PENALTIES[$flag] ?? 0.0;
        }

        return self::clamp($base - $penalty);
    }

    /**
     * Score every supplied field and derive the overall score from the results.
     *
     * @param array<string, FieldEvidence> $evidence Keyed by field name.
     *
     * @return array{
     *     score: float,
     *     coverage: float,
     *     fields: array<string, array{score: float, origin: string, flags: array<int, string>}>
     * }
     */
    public function score(array $evidence): array
    {
        $fields = [];
        $weightedSum = 0.0;
        $presentWeight = 0.0;
        $unsupportedPenalty = 0.0;

        foreach ($evidence as $field => $item) {
            $field = (string) $field;

            if (! array_key_exists($field, self::WEIGHTS)) {
                // Scoring a field that has no weight would report a per-field score that has no
                // effect on the overall result, which is exactly the "score looks fine, event is
                // not" problem this scoring exists to prevent.
                throw new InvalidArgumentException(sprintf(
                    'Field "%s" is scored but has no weight; add it to CandidateScorer::WEIGHTS.',
                    $field
                ));
            }

            $flags = $item->flags();
            $score = $this->scoreField($field, $item->origin(), $flags);
            $weight = self::WEIGHTS[$field];

            $fields[$field] = [
                'score' => $score,
                'origin' => $item->origin(),
                'flags' => $flags,
            ];

            // An unsupported field contributes nothing to the mean *and* is penalised, which
            // is why guessing cannot raise the score.
            if ($item->origin() !== FieldEvidence::UNSUPPORTED) {
                $weightedSum += $weight * $score;
                $presentWeight += $weight;
            } else {
                $unsupportedPenalty += self::UNSUPPORTED_PENALTY * $weight;
            }
        }

        $totalWeight = array_sum(self::WEIGHTS);

        // Coverage measures how much of the notice the parser actually managed to support.
        //
        // Measuring it as a plain fraction of all known fields would reward thin candidates: a
        // block that produced no date at all would score full coverage on the handful of fields
        // it did produce, and would out-score a complete notice. A missing required field is
        // instead counted as missing evidence, so the fraction only rewards breadth once the
        // core fields are present.
        [$coverage, $missingRequiredWeight] = $this->coverage($evidence, $presentWeight, $totalWeight);

        $weightedMean = $presentWeight > 0.0 ? $weightedSum / $presentWeight : 0.0;
        $scaled = $weightedMean * (self::COVERAGE_FLOOR + (self::COVERAGE_RANGE * $coverage));

                // A required field the parser could not support is charged again here, as a fraction of
                // the total weight, so a notice without a usable date or title is held back no matter how
                // well its other fields were read.
                $missingRequiredCharge = $missingRequiredWeight > 0.0
                    ? self::MISSING_REQUIRED_CHARGE * ($missingRequiredWeight / $totalWeight)
                    : 0.0;
                $overall = self::clamp($scaled - $unsupportedPenalty - $missingRequiredCharge);

        return [
            'score' => $overall,
            'coverage' => round($coverage, 3),
            'fields' => $fields,
        ];
    }

    /**
     * Apply the candidate-wide defects that are not owned by a single field.
     *
     * Kept separate from {@see score()} so the whole-candidate flags recorded by the parser
     * apply exactly once, at their historic values.
     *
     * @param list<string> $wholeCandidateFlags
     */
    public function applyWholeCandidatePenalties(float $score, array $wholeCandidateFlags): float
    {
        foreach ($wholeCandidateFlags as $flag) {
            $score -= self::WHOLE_CANDIDATE_PENALTIES[$flag] ?? 0.0;
        }

        return self::clamp($score);
    }

    /**
     * How strongly an unsupported value hurts, for the field given.
     */
    public function unsupportedPenalty(string $field): float
    {
        return self::UNSUPPORTED_PENALTY * (self::WEIGHTS[$field] ?? 1.0);
    }

    /**
     * The share of the notice the parser actually supported.
     *
     * Weight not backed by evidence is charged as missing, including the weight of a required
     * field that was never extracted or that was fabricated. This keeps a candidate from scoring
     * highly on the strength of a few fields while the fields that make an event usable are
     * absent.
     *
         * The charge is applied to the overall score, not folded back into the mean, because the
         * mean already divides by the weight that *is* present: adding a missing required field's
         * weight back into coverage cancels the very penalty it was meant to apply.
         *
         * @param array<string, FieldEvidence> $evidence
         *
         * @return float{0: float, 1: float} Coverage, and the weight missing from required fields.
         */
        private function coverage(array $evidence, float $presentWeight, float $totalWeight): array
        {
            $requiredWeight = 0.0;

            foreach (self::REQUIRED_FIELDS as $required) {
                $item = $evidence[$required] ?? null;

                if ($item === null || $item->origin() === FieldEvidence::UNSUPPORTED) {
                    $requiredWeight += self::WEIGHTS[$required] ?? 1.0;
                }
            }

            $coverage = $totalWeight > 0.0 ? max(0.0, min(1.0, $presentWeight / $totalWeight)) : 0.0;

            return [$coverage, $requiredWeight];
        }

    private static function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}