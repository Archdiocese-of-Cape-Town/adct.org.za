<?php

namespace ADCT\ParishIntake\Core\Parsing\Stages;

use ADCT\ParishIntake\Core\Parsing\Contracts\StageInterface;
use ADCT\ParishIntake\Core\Parsing\Input\Message;
use ADCT\ParishIntake\Core\Parsing\ParseContext;
use ADCT\ParishIntake\Core\Parsing\ParseResult;
use ADCT\ParishIntake\Core\Support\EmailTextCleaner;
use ADCT\ParishIntake\Core\Support\Text;

final class SourceNormalizationStage implements StageInterface
{
    private EmailTextCleaner $textCleaner;

    public function __construct(?EmailTextCleaner $textCleaner = null)
    {
        $this->textCleaner = $textCleaner ?? new EmailTextCleaner();
    }

    public function process(Message $message, ParseResult $result, ParseContext $context): ParseResult
    {
        $cleaned = $this->textCleaner->clean(
            $message->getBody(),
            $message->getSubject(),
            $message->isForwarded()
        );
        $body = $cleaned->getBody();
        $sharedSignature = (string) $context->getRuntimeValue('shared_signature_text', '');
        $sharedQuoted = (string) $context->getRuntimeValue('shared_quoted_text', '');
        $signature = $message->getSignatureText() !== ''
            ? $message->getSignatureText()
            : ($sharedSignature !== '' ? $sharedSignature : $cleaned->getSignatureText());
        $quoted = $message->getQuotedText() !== ''
            ? $message->getQuotedText()
            : ($sharedQuoted !== '' ? $sharedQuoted : $cleaned->getQuotedText());
        $normalized = Text::normalizeWhitespace($message->getSubject() . "\n\n" . $body);

        $result->setNormalizedText($normalized);
        $result->setField('source_type', $message->getSourceType());
        $result->setField('source_identifier', $message->getSourceIdentifier());
        $result->setField('sender_email', $message->getSenderEmail());
        $result->setField('sender_name', $message->getSenderName());
        $context->setRuntimeValue('cleaned_body', $body);
        $context->setRuntimeValue('signature_text', $signature);
        $context->setRuntimeValue('quoted_text', $quoted);
        $result->addStrategy('source_normalization');
        $context->addNote('Normalized and cleaned source text for deterministic parsing.');

        return $result;
    }
}
