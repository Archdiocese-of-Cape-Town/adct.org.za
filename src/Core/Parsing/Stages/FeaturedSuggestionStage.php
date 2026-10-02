<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Parsing\Stages;

use ADCT\ParishIntake\Core\Parsing\Contracts\StageInterface;
use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\ParseContext;
use ADCT\ParishIntake\Core\Parsing\ParseResult;

final class FeaturedSuggestionStage implements StageInterface
{
    public function process(Message $message, ParseResult $result, ParseContext $context): ParseResult
    {
        $title = $result->getField('title');
        $text = (is_string($title) ? $title . "\n" : '') . $result->getNormalizedText();
        $isEvent = in_array($result->getClassification(), ['event', 'recurring_event'], true);
        $isOnceOff = $result->getRecurrence() === [];

        if ($isEvent && $isOnceOff && preg_match(
            '/\b(?:conference|pilgrimage|jubilee|ordination)s?\b/iu',
            $text
        ) === 1 && $result->getField('featured') === null) {
            $result->setField('featured', true);
            $result->addNote('Featured suggested from once-off event wording; an editor can change it.');
        }

        $result->addStrategy('featured_suggestion');

        return $result;
    }
}
