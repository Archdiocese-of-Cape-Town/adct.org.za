<?php

namespace ADCT\ParishIntake\Core\Parsing;

final class BlockSplitResult
{
    /** @var EventBlock[] */
    private array $blocks;
    private array $notes;
    private array $errors;

    /**
     * @param EventBlock[] $blocks
     * @param string[] $notes
     * @param string[] $errors
     */
    public function __construct(array $blocks, array $notes = [], array $errors = [])
    {
        $this->blocks = $blocks;
        $this->notes = $notes;
        $this->errors = $errors;
    }

    /** @return EventBlock[] */
    public function getBlocks(): array
    {
        return $this->blocks;
    }

    /** @return EventBlock[] */
    public function getCandidateBlocks(): array
    {
        return array_values(array_filter(
            $this->blocks,
            static fn (EventBlock $block): bool => $block->isCandidate()
        ));
    }

    public function getBlockMetadata(): array
    {
        return array_map(
            static fn (EventBlock $block): array => $block->toMetadata(),
            $this->blocks
        );
    }

    public function getNotes(): array
    {
        return $this->notes;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
