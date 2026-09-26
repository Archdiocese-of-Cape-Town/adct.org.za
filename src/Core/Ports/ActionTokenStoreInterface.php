<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use ADCT\ParishIntake\Core\Auth\ActionTokenBinding;
use ADCT\ParishIntake\Core\Auth\ActionTokenRecord;
use DateTimeImmutable;

interface ActionTokenStoreInterface
{
    public function create(ActionTokenRecord $record): void;

    public function findByHash(string $tokenHash): ?ActionTokenRecord;

    public function consume(
        string $tokenHash,
        ActionTokenBinding $binding,
        DateTimeImmutable $now
    ): bool;
}
