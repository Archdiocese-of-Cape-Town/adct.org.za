<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Jobs;

use InvalidArgumentException;

final class JobRunResult
{
    public readonly JobRunStatus $status;
    public readonly int $itemsProcessed;
    public readonly ?string $errorMessage;

    public function __construct(
        JobRunStatus $status,
        int $itemsProcessed,
        ?string $errorMessage = null
    ) {
        if ($itemsProcessed < 0) {
            throw new InvalidArgumentException('The processed item count cannot be negative.');
        }

        $this->status = $status;
        $this->itemsProcessed = $itemsProcessed;
        $this->errorMessage = $errorMessage;
    }

    public function succeeded(): bool
    {
        return in_array($this->status, [
            JobRunStatus::COMPLETED,
            JobRunStatus::TIME_BUDGET_REACHED,
            JobRunStatus::ITEM_BUDGET_REACHED,
        ], true);
    }
}
