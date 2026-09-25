<?php

namespace ADCT\ParishIntake\Core\Parsing;

use ADCT\ParishIntake\Core\Parsing\Contracts\StageInterface;
use ADCT\ParishIntake\Core\Parsing\Input\Message;

final class Pipeline
{
    /** @var StageInterface[] */
    private array $stages;
    private ParseContext $context;
    private BulletinBlockSplitter $blockSplitter;

    public function __construct(
        array $stages,
        ?ParseContext $context = null,
        ?BulletinBlockSplitter $blockSplitter = null
    ) {
        $this->stages = $stages;
        $this->context = $context ?? new ParseContext();
        $this->blockSplitter = $blockSplitter ?? new BulletinBlockSplitter();
    }

    public function parse(Message $message): ParseResult
    {
        return $this->parseAll($message)->getPrimaryResult();
    }

    public function parseAll(Message $message): ParseOutcome
    {
        $this->context->reset();
        $split = $this->blockSplitter->split($message);
        $blocks = $split->getCandidateBlocks();
        $candidates = [];

        foreach ($blocks as $block) {
            $subject = $this->subjectForBlock($message, $block, count($blocks));
            $blockMessage = $message->withBody($block->getParseText(), $subject);
            $useBlockTitle = count($blocks) > 1 || $subject !== $message->getSubject();
            $candidates[] = $this->parseBlock(
                $blockMessage,
                $block,
                $useBlockTitle ? $block->getTitle() : null
            );
        }

        $notes = $split->getNotes();
        $errors = $split->getErrors();

        foreach ($candidates as $candidate) {
            foreach ($candidate->getErrors() as $error) {
                if (! in_array($error, $errors, true)) {
                    $errors[] = $error;
                }
            }
        }

        if ($candidates === []) {
            $notes[] = 'No event candidate was identified; the legacy result is a notice.';
            $primaryResult = $split->getBlocks() === []
                ? $this->parseBlock($message, null, null)
                : $this->noticeResult($message);
        } else {
            $primaryResult = $candidates[0];
        }

        return new ParseOutcome(
            $candidates,
            $notes,
            $errors,
            $split->getBlockMetadata(),
            $primaryResult
        );
    }

    private function parseBlock(Message $message, ?EventBlock $block, ?string $blockTitle): ParseResult
    {
        $this->context->reset();

        if ($block !== null) {
            $blockContext = $block->getContext();
            $this->context->setRuntimeValue('block_context', $blockContext);
            $this->context->setRuntimeValue('block_title', $blockTitle);

            foreach (['shared_signature_text', 'shared_quoted_text'] as $key) {
                if (isset($blockContext[$key]) && is_string($blockContext[$key])) {
                    $this->context->setRuntimeValue($key, $blockContext[$key]);
                }
            }
        }

        $result = new ParseResult();
        $result->setParserVersion((string) $this->context->getOption('parser_version', '0.1.0'));

        foreach ($this->stages as $stage) {
            $result = $stage->process($message, $result, $this->context);
        }

        foreach ($this->context->notes() as $note) {
            $result->addNote($note);
        }

        foreach ($this->context->errors() as $error) {
            $result->addError($error);
        }

        if ($block !== null) {
            $result->setBlockMetadata($block->getBlockIndex(), $block->getSourceText());
        }

        return $result;
    }

    private function subjectForBlock(Message $message, EventBlock $block, int $candidateCount): string
    {
        $subject = trim($message->getSubject());

        if ($candidateCount === 1 && ! preg_match(
            '/\b(?:bulletin|newsletter|community\s+calendar|parish\s+calendar)\b/i',
            $subject
        )) {
            return $message->getSubject();
        }

        return $block->getTitle() ?? '';
    }

    private function noticeResult(Message $message): ParseResult
    {
        $result = new ParseResult();
        $result->setParserVersion((string) $this->context->getOption('parser_version', '0.1.0'));
        $result->setClassification('general_notice');
        $result->setField('source_type', $message->getSourceType());
        $result->setField('source_identifier', $message->getSourceIdentifier());
        $result->setField('sender_email', $message->getSenderEmail());
        $result->setField('sender_name', $message->getSenderName());
        $result->setField('title', trim($message->getSubject()));

        return $result;
    }
}
