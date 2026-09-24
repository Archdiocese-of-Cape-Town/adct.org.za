<?php

namespace ADCT\ParishIntake\Parsing\Stages;

use ADCT\ParishIntake\Parsing\Contracts\StageInterface;
use ADCT\ParishIntake\Parsing\Input\Message;
use ADCT\ParishIntake\Parsing\ParseContext;
use ADCT\ParishIntake\Parsing\ParseResult;
use ADCT\ParishIntake\Support\Text;

final class SourceNormalizationStage implements StageInterface
{
    public function process(Message $message, ParseResult $result, ParseContext $context): ParseResult
    {
        $normalized = Text::normalizeWhitespace($message->fullText());

        $result->setNormalizedText($normalized);
        $result->setField('source_type', $message->getSourceType());
        $result->setField('source_identifier', $message->getSourceIdentifier());
        $result->setField('sender_email', $message->getSenderEmail());
        $result->setField('sender_name', $message->getSenderName());
        $result->addStrategy('source_normalization');
        $context->addNote('Normalized source text for deterministic parsing.');

        return $result;
    }
}
