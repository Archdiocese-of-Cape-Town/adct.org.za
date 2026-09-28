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
        $allCandidateBlocks = $split->getCandidateBlocks();
        $totalCandidates = count($allCandidateBlocks);
        $candidateLimitExceeded = $totalCandidates > ParseOutcome::MAX_CANDIDATES;
        $blocks = array_slice($allCandidateBlocks, 0, ParseOutcome::MAX_CANDIDATES);
        $candidateCount = count($blocks);
        $candidates = [];
        $candidateLimitError = $candidateLimitExceeded
            ? sprintf('candidate_limit_exceeded:%d', $totalCandidates)
            : null;
        $candidateLimitNote = $candidateLimitExceeded
            ? sprintf(
                'Candidate limit exceeded; returned the first %d of %d event candidates in document order and lowered their confidence for manual review.',
                ParseOutcome::MAX_CANDIDATES,
                $totalCandidates
            )
            : null;

        foreach ($blocks as $block) {
            $subject = $this->subjectForBlock($message, $block, $candidateCount);
            $blockMessage = $message->withBody($block->getParseText(), $subject);
            $useBlockTitle = $candidateCount > 1 || $subject !== $message->getSubject();
            $candidate = $this->parseBlock(
                $blockMessage,
                $block,
                $useBlockTitle ? $block->getTitle() : null
            );

            if ($candidateLimitExceeded) {
                $candidate->setConfidence(0.0);
                $candidate->setNeedsReprocess(true);
                $candidate->addError((string) $candidateLimitError);
                $candidate->addNote((string) $candidateLimitNote);
            }

            $sectionKeywordOverride = $block->getSectionKeywordOverride();

            if ($sectionKeywordOverride !== null) {
                $candidate->setConfidence($candidate->getConfidence() - 0.1);
                $candidate->addNote('section_keyword_overridden: ' . $sectionKeywordOverride);
            }

            $candidates[] = $candidate;
        }

        $notes = $split->getNotes();
        $errors = $split->getErrors();

        if ($candidateLimitExceeded) {
            $notes[] = (string) $candidateLimitNote;
            $errors[] = (string) $candidateLimitError;
        }

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

        foreach ($notes as $note) {
            if (str_starts_with($note, 'possible_missed_event_after_skipped_section: ')) {
                $primaryResult->addNote($note);

                foreach ($candidates as $candidate) {
                    if ($candidate !== $primaryResult) {
                        $candidate->addNote($note);
                    }
                }
            }
        }

        $blockMetadata = $split->getBlockMetadata();

        if ($candidateLimitExceeded) {
            $retainedBlockIndexes = array_fill_keys(
                array_map(
                    static fn (EventBlock $block): int => $block->getBlockIndex(),
                    $blocks
                ),
                true
            );

            foreach ($blockMetadata as &$metadata) {
                if (
                    ! empty($metadata['candidate'])
                    && ! isset($retainedBlockIndexes[$metadata['block_index']])
                ) {
                    $metadata['candidate'] = false;
                    $metadata['classification'] = 'skipped';
                    $metadata['reason'] = 'candidate_limit_exceeded';
                }
            }
            unset($metadata);
        }

        return new ParseOutcome(
            $candidates,
            $notes,
            $errors,
            $blockMetadata,
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
