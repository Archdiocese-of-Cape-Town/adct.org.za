<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Jobs;

final class JobStepResult
{
    private readonly ?string $checkpoint;
    private readonly bool $complete;

    private function __construct(?string $checkpoint, bool $complete)
    {
        $this->checkpoint = $checkpoint;
        $this->complete = $complete;
    }

    public static function continueAt(?string $checkpoint): self
    {
        return new self($checkpoint, false);
    }

    public static function completeAt(?string $checkpoint): self
    {
        return new self($checkpoint, true);
    }

    public function checkpoint(): ?string
    {
        return $this->checkpoint;
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }
}
