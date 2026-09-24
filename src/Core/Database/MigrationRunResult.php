<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Database;

final class MigrationRunResult
{
    /**
     * @param int[] $appliedVersions
     */
    public function __construct(
        private bool $succeeded,
        private int $currentVersion,
        private array $appliedVersions = [],
        private ?int $failedVersion = null,
        private ?string $error = null
    ) {
    }

    public function succeeded(): bool
    {
        return $this->succeeded;
    }

    public function currentVersion(): int
    {
        return $this->currentVersion;
    }

    /**
     * @return int[]
     */
    public function appliedVersions(): array
    {
        return $this->appliedVersions;
    }

    public function failedVersion(): ?int
    {
        return $this->failedVersion;
    }

    public function error(): ?string
    {
        return $this->error;
    }
}
