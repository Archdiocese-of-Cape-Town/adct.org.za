<?php

namespace ADCT\ParishIntake\Core\Parsing\Stages;

use ADCT\ParishIntake\Core\Parsing\Contracts\StageInterface;
use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\ParseContext;
use ADCT\ParishIntake\Core\Parsing\ParseResult;
use ADCT\ParishIntake\Core\Support\Text;

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
