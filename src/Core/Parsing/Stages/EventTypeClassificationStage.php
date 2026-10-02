<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Parsing\Stages;

use ADCT\ParishIntake\Core\Parsing\Confidence\FieldEvidence;
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

        $existingType = $result->getField('event_type');
        $eventTypeSource = $result->getField('event_type_source');
        $isExplicitType = in_array($eventTypeSource, ['admin', 'manual', 'keyword'], true);
        $aiSuppliedType = $eventTypeSource === 'ai'
            || in_array('event_type', $result->getAiFieldsFilled(), true);

        if ($existingType !== null && ($isExplicitType || ! $aiSuppliedType)) {
            return $result;
        }

        $body = $context->getRuntimeValue(
            'block_source_text',
            $context->getRuntimeValue('cleaned_body', '')
        );
        $description = $result->getField('description');

        if (is_string($description) && trim($description) !== '') {
            $body = (is_string($body) ? $body : '') . "\n" . $description;
        }

        $type = $this->classifier->classify(
            (string) $result->getField('title'),
            is_string($body) ? $body : ''
        );
        $result->setField('event_type', $type['slug']);
        $result->setField('event_type_confidence', $type['confidence']);
        $result->setField('event_type_source', 'keyword');
                $result->recordFieldEvidence('event_type', new FieldEvidence(
                    $type['confidence'] >= 0.5 ? FieldEvidence::DIRECTORY_TEXT : FieldEvidence::INFERRED
                ));
                $result->addStrategy('event_type_keywords');
                return $result;
    }
}
