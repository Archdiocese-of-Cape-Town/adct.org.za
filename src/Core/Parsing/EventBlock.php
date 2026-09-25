<?php

namespace ADCT\ParishIntake\Core\Parsing;

final class EventBlock
{
    private int $blockIndex;
    private string $sourceText;
    private string $parseText;
    private array $context;
    private ?string $title;
    private string $classification;
    private ?string $reason;
    private bool $candidate;
    private ?string $sectionKeywordOverride;

    /**
     * @param array<string, string|int> $context
     */
    public function __construct(
        int $blockIndex,
        string $sourceText,
        string $parseText,
        array $context,
        ?string $title,
        string $classification,
        ?string $reason,
        bool $candidate,
        ?string $sectionKeywordOverride = null
    ) {
        $this->blockIndex = $blockIndex;
        $this->sourceText = $sourceText;
        $this->parseText = $parseText;
        $this->context = $context;
        $this->title = $title;
        $this->classification = $classification;
        $this->reason = $reason;
        $this->candidate = $candidate;
        $this->sectionKeywordOverride = $sectionKeywordOverride;
    }

    public function getBlockIndex(): int
    {
        return $this->blockIndex;
    }

    public function getSourceText(): string
    {
        return $this->sourceText;
    }

    public function getParseText(): string
    {
        return $this->parseText;
    }

    /** @return array<string, string|int> */
    public function getContext(): array
    {
        return $this->context;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getClassification(): string
    {
        return $this->classification;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function isCandidate(): bool
    {
        return $this->candidate;
    }

    public function getSectionKeywordOverride(): ?string
    {
        return $this->sectionKeywordOverride;
    }

    public function toMetadata(): array
    {
        $metadata = [
            'block_index' => $this->blockIndex,
            'classification' => $this->classification,
            'candidate' => $this->candidate,
        ];

        if ($this->reason !== null) {
            $metadata['reason'] = $this->reason;
        }

        return $metadata;
    }
}
