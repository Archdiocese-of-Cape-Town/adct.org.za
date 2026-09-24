<?php

namespace ADCT\ParishIntake\Core\Parsing\Stages;

use ADCT\ParishIntake\Core\Parsing\Contracts\StageInterface;
use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\ParseContext;
use ADCT\ParishIntake\Core\Parsing\ParseResult;

final class RecurrenceDetectionStage implements StageInterface
{
    private const WEEKDAY_MAP = [
        'monday' => 'MO',
        'tuesday' => 'TU',
        'wednesday' => 'WE',
        'thursday' => 'TH',
        'friday' => 'FR',
        'saturday' => 'SA',
        'sunday' => 'SU',
    ];

    private const POSITION_MAP = [
        'first' => 1,
        'second' => 2,
        'third' => 3,
        'fourth' => 4,
        'last' => -1,
    ];

    public function process(Message $message, ParseResult $result, ParseContext $context): ParseResult
    {
        $text = $result->getNormalizedText();
        $recurrence = [];

        if (preg_match('/\bevery\s+(first|second|third|fourth|last)\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i', $text, $matches)) {
            $recurrence = [
                'frequency' => 'monthly',
                'interval' => 1,
                'by_day' => self::WEEKDAY_MAP[strtolower($matches[2])],
                'by_set_position' => self::POSITION_MAP[strtolower($matches[1])],
                'text' => $matches[0],
            ];
        } elseif (preg_match('/\bweekly\s+on\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i', $text, $matches)) {
            $recurrence = [
                'frequency' => 'weekly',
                'interval' => 1,
                'by_day' => self::WEEKDAY_MAP[strtolower($matches[1])],
                'text' => $matches[0],
            ];
        } elseif (preg_match('/\bevery\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i', $text, $matches)) {
            $recurrence = [
                'frequency' => 'weekly',
                'interval' => 1,
                'by_day' => self::WEEKDAY_MAP[strtolower($matches[1])],
                'text' => $matches[0],
            ];
        } elseif (preg_match('/\b(?:every\s+month|monthly)\b/i', $text, $matches)) {
            $recurrence = [
                'frequency' => 'monthly',
                'interval' => 1,
                'text' => $matches[0],
                'ambiguous' => true,
            ];
        }

        if ($recurrence !== []) {
            $result->setRecurrence($recurrence);
            $result->setClassification('recurring_event');
            $result->addStrategy('recurrence_detection');

            if (! empty($recurrence['ambiguous'])) {
                $result->setNeedsReprocess(true);
                $context->addNote('Recurring wording detected but needs human confirmation.');
            }
        }

        return $result;
    }
}
