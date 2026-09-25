<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use DateTimeImmutable;

interface ActionTokenRateLimitStoreInterface
{
    public function consume(
        string $scopeHash,
        DateTimeImmutable $windowStart,
        int $limit
    ): bool;
}
