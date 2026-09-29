<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use DateTimeImmutable;

interface ProcessedMailRetentionInterface
{
    /** @return array{validity: int, uid: ?int} */
    public function nextOldProcessedUid(DateTimeImmutable $before, int $afterUid): ?array;

    public function deleteOldProcessedUid(int $uid, int $expectedValidity): void;

    public function close(): void;
}
