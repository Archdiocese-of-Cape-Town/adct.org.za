<?php

declare(strict_types=1);

namespace ADCT\ParishIntake\Core\Ports;

use DateTimeImmutable;

interface JobLockInterface
{
    public function acquire(string $jobId, DateTimeImmutable $now, int $expiresInSeconds): ?string;

    public function isHeldBy(string $jobId, string $token, DateTimeImmutable $now): bool;

    public function release(string $jobId, string $token): bool;
}
