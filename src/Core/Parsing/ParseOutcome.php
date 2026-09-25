<?php

namespace ADCT\ParishIntake\Core\Parsing;

final class ParseOutcome
{
    public const MAX_CANDIDATES = 50;

    /** @var ParseResult[] */
    private array $candidates;
    private array $notes;
    private array $errors;
    private array $blocks;
    private ParseResult $primaryResult;

    /**
     * @param ParseResult[] $candidates
     * @param string[] $notes
     * @param string[] $errors
     * @param array<int, array<string, mixed>> $blocks
     */
    public function __construct(
        array $candidates,
        array $notes,
        array $errors,
        array $blocks,
        ParseResult $primaryResult
    ) {
        $this->candidates = $candidates;
        $this->notes = $notes;
        $this->errors = $errors;
        $this->blocks = $blocks;
        $this->primaryResult = $primaryResult;
    }

    /** @return ParseResult[] */
    public function getCandidates(): array
    {
        return $this->candidates;
    }

    public function getPrimaryResult(): ParseResult
    {
        return $this->primaryResult;
    }

    public function getNotes(): array
    {
        return $this->notes;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getBlocks(): array
    {
        return $this->blocks;
    }

    public function toArray(): array
    {
        return [
            'candidate_count' => count($this->candidates),
            'candidates' => array_map(
                static fn (ParseResult $candidate): array => $candidate->toArray(),
                $this->candidates
            ),
            'notes' => $this->notes,
            'errors' => $this->errors,
            'blocks' => $this->blocks,
        ];
    }
}
