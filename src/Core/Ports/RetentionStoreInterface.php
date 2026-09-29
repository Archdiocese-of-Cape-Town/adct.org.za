<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

interface RetentionStoreInterface
{
    public function nextExpiredMessageId(int $afterId, string $cutoff): ?int;

    public function removeExpiredMessageFiles(int $id, string $cutoff): void;

    public function nextExpiredTokenId(int $afterId, string $cutoff): ?int;

    public function removeExpiredToken(int $id, string $cutoff): void;

    public function nextExpiredAuditId(int $afterId, string $cutoff): ?int;

    public function removeExpiredAudit(int $id, string $cutoff): void;
}
