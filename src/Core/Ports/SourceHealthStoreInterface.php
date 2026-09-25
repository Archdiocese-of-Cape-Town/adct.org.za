<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Sources\SourceHealthState;

interface SourceHealthStoreInterface
{
    public function findHealth(int $sourceId): ?SourceHealthState;

    public function saveHealthIfUnchanged(
        int $sourceId,
        SourceHealthState $expected,
        SourceHealthState $replacement,
        string $updatedAt
    ): bool;
}
