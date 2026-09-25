<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Parsing\Stages;

use ADCT\ParishIntake\Core\Parsing\Contracts\StageInterface;
use ADCT\ParishIntake\Core\Parsing\EventTypeClassifier;
use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\ParseContext;
use ADCT\ParishIntake\Core\Parsing\ParseResult;

final class EventTypeClassificationStage implements StageInterface
{
    public function __construct(private EventTypeClassifier $classifier)
    {
    }

    public function process(Message $message, ParseResult $result, ParseContext $context): ParseResult
    {
        if ($result->getClassification() === 'general_notice') {
            return $result;
        }

        $body = $context->getRuntimeValue('cleaned_body', '');
        $type = $this->classifier->classify(
            (string) $result->getField('title'),
            is_string($body) ? $body : ''
        );
        $result->setField('event_type', $type['slug']);
        $result->setField('event_type_confidence', $type['confidence']);
        $result->setField('event_type_source', 'keyword');
        $result->addStrategy('event_type_keywords');
        return $result;
    }
}
