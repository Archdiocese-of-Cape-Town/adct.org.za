<?php

namespace ADCT\ParishIntake\Core\Parsing\Stages;

use ADCT\ParishIntake\Core\Parsing\Contracts\StageInterface;
use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\ParseContext;
use ADCT\ParishIntake\Core\Parsing\ParseResult;

final class ConfidenceScoringStage implements StageInterface
{
    public function process(Message $message, ParseResult $result, ParseContext $context): ParseResult
    {
        $score = 0.1;

        if ($result->getField('title')) {
            $score += 0.2;
        }

        if ($result->getField('parish_name')) {
            $score += 0.15;
        }

        if ($result->getField('event_date')) {
            $score += 0.2;
        }

        if ($result->getField('event_time')) {
            $score += 0.1;
        }

        if ($result->getField('venue')) {
            $score += 0.1;
        }

        if ($result->getField('contact')) {
            $score += 0.05;
        }

        if ($result->getClassification() !== 'low_confidence' && $result->getClassification() !== 'unknown') {
            $score += 0.1;
        }

        if ($result->getRecurrence() !== []) {
            $score += 0.15;
        }

        if (! empty($result->getRecurrence()['ambiguous'])) {
            $score -= 0.15;
        }

        if ($result->hasDateWeekdayMismatch()) {
            $score -= 0.15;
        }

        if ($result->hasRangeEndBeforeStart()) {
            $score -= 0.15;
        }

        if ($result->hasAmbiguousNextWeekday()) {
            $score -= 0.05;
        }

        $score = max(0.0, min(1.0, $score));
        $result->setConfidence($score);
        $result->setNeedsReprocess($result->needsReprocess() || $score < 0.45);
        $result->addStrategy('confidence_scoring');
        $context->addNote(sprintf('Deterministic confidence score assigned: %.2f', $score));

        return $result;
    }
}
